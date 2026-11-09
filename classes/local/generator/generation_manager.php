<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace theme_brandable\local\generator;

use moodle_exception;
use stdClass;
use theme_brandable\local\account\account;
use theme_brandable\local\account\api_client;
use theme_brandable\local\account\api_exception;
use theme_brandable\local\account\connection;
use theme_brandable\local\assets\font_downloader;
use theme_brandable\local\assets\markers;
use theme_brandable\local\overrides\change;
use theme_brandable\local\overrides\revision_manager;
use theme_brandable\local\validation\validation_exception;

/**
 * Runs generations for the editor: start, relay of the generator status on each poll, validation of the
 * result into a revision, and repairs when the validation fails.
 *
 * The editor never talks to the generator: it polls {@see self::refresh()} through an external function.
 *
 * @package    theme_brandable
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class generation_manager {
    /** @var string Waiting for the generator. */
    public const RUNNING = 'running';
    /** @var string A revision was created. */
    public const COMPLETED = 'completed';
    /** @var string Failed; errormessage is shown to the administrator. */
    public const FAILED = 'failed';
    /** @var string The request was too ambiguous: the summary is a question, answered by a new generation. */
    public const QUESTION = 'question';
    /**
     * @var string The request needs the generated JavaScript, not available on the site (contract 12.3): the summary
     * explains it; sent again once the JavaScript is turned on (spec B3).
     */
    public const JSACTIVATION = 'jsactivation';
    /** @var string A request that needed the JavaScript, sent again once it was turned on. */
    public const JSRESENT = 'jsresent';

    /** @var string Error code of a generation whose fonts could not be downloaded (it can be resumed). */
    public const FONT_ERROR = 'font_download';

    /** @var int Repairs requested after a failed validation, as the backend allows. */
    public const MAX_REPAIRS = 2;

    /** @var int Questions followed back when sending an answer (question, answer that was a question again, ...). */
    public const MAX_EXCHANGE = 10;

    /** @var string Event added by the plugin when modules failed in the preview (sent to the repair). */
    public const EVENT_JSERROR = 'js_runtime_error';

    /** @var string Event added by the plugin when its validation rejects a result. */
    public const EVENT_REJECTED = 'rejected';
    /**
     * @var string[] Error codes of failures caused by the service (overload, timeout, unreachable API): the same
     * prompt can be sent again a bit later. service_unavailable: the backend (LLM timeout, connection, 429).
     */
    public const TRANSIENT_CODES = ['service_unavailable', 'interrupted', 'connection_failed', 'rate_limited', 'http_429',
        'http_502', 'http_503', 'http_504'];

    /**
     * @var string[] Account errors, by code: what the editor offers (connect the site, link it again, buy credits,
     * choose a paid plan). Credits and plan: the same prompt can be sent again once bought.
     */
    public const ACCOUNT_ACTIONS = ['site_not_connected' => 'connect', 'site_mismatch' => 'relink',
        'insufficient_credits' => 'credits', 'plan_required' => 'plan', 'plugin_upgrade_required' => 'upgrade',
        self::CAPABILITIES_ERROR => 'upgrade'];

    /** @var string Error code of a request that needs capabilities the plugin lacks (needsPluginUpgrade, contract 15.3). */
    public const CAPABILITIES_ERROR = 'plugin_capabilities';

    /**
     * Starts a generation for the draft.
     *
     * An answer to a question is sent with the exchange in the history, and with the element targeted by the
     * question when none is given (on the page of the question).
     *
     * @param string $prompt Prompt.
     * @param string|null $pageurl Page shown in the preview.
     * @param generator_interface|null $generator Generator (the active one when null).
     * @param array|null $selection Element targeted in the preview.
     * @param int $questionid Generation whose question the prompt answers, 0 if none.
     * @param int[] $attachments Ids of the images of the library attached to the prompt.
     * @return stdClass Generation record.
     */
    public static function submit(
        string $prompt,
        ?string $pageurl = null,
        ?generator_interface $generator = null,
        ?array $selection = null,
        int $questionid = 0,
        array $attachments = []
    ): stdClass {
        global $DB, $USER;

        $prompt = trim($prompt);
        if ($prompt === '') {
            throw new moodle_exception('error:emptyprompt', 'theme_brandable');
        }
        $exchange = [];
        if ($questionid) {
            $question = $DB->get_record('theme_brandable_generation', ['id' => $questionid, 'status' => self::QUESTION]);
            if (!$question) {
                throw new moodle_exception('error:noquestion', 'theme_brandable');
            }
            if ($selection === null && !empty($question->selection)) {
                $selection = json_decode($question->selection, true);
                $pageurl = $question->pageurl ?: $pageurl;
            }
            $exchange = self::get_exchange($questionid);
        }
        $name = factory::get_name();
        $generator = $generator ?? factory::get($name);
        $selection = is_array($selection) ? selection::clean($selection) : null;
        $attachments = array_values(array_unique(array_map('intval', $attachments)));
        $context = generation_context::build($pageurl, $selection, $exchange, $attachments);

        $record = (object) [
            'userid' => (int) $USER->id,
            'sessionid' => $context->sessionid,
            'generator' => $name,
            'prompt' => $prompt,
            'pageurl' => $context->page['url'],
            'selection' => $selection !== null ? json_encode($selection) : null,
            'questionid' => $questionid ?: null,
            'attachments' => $attachments ? json_encode($attachments) : null,
            'status' => self::RUNNING,
            'basedraft' => $context->draft,
            'repairs' => 0,
            'remoteevents' => '[]',
            'localevents' => '[]',
            'timecreated' => time(),
            'timemodified' => time(),
        ];
        try {
            $record->remoteid = $generator->start($prompt, $context);
        } catch (generator_exception $e) {
            self::fail($record, $e->generatorcode, $e->displaymessage, $e->url);
        }
        $record->id = $DB->insert_record('theme_brandable_generation', $record);
        return $record;
    }

    /**
     * Relays the generator status, and turns a result into a revision.
     *
     * @param int $id Generation id.
     * @param generator_interface|null $generator Generator (the one that started the generation when null).
     * @return stdClass Generation record.
     */
    public static function refresh(int $id, ?generator_interface $generator = null): stdClass {
        global $DB;

        $record = $DB->get_record('theme_brandable_generation', ['id' => $id], '*', MUST_EXIST);
        if ($record->status !== self::RUNNING) {
            return $record;
        }

        // One poll at a time per generation: a result must never be turned into two revisions.
        $lock = \core\lock\lock_config::get_lock_factory('theme_brandable')->get_lock('generation_' . $id, 30);
        if (!$lock) {
            return $record;
        }
        try {
            $record = $DB->get_record('theme_brandable_generation', ['id' => $id], '*', MUST_EXIST);
            if ($record->status === self::RUNNING) {
                self::advance($record, $generator ?? factory::get($record->generator));
                $record->timemodified = time();
                $DB->update_record('theme_brandable_generation', $record);
            }
        } finally {
            $lock->release();
        }
        return $record;
    }

    /**
     * Records the verification of the revision in the preview, and asks for a repair when selectors match
     * no element (V5): the draft goes back to where the generation started, the revision is abandoned.
     *
     * Only for the revision the generation created, while it is still the draft, within the repair budget.
     *
     * @param int $id Generation id.
     * @param array $failures Selectors matching no element: [['target' => string, 'selector' => string]].
     * @param array $warnings Selectors matching elements out of the targeted one:
     *                        [['target' => string, 'selector' => string, 'count' => int]].
     * @param int $checked Number of selectors checked.
     * @param generator_interface|null $generator Generator (the one that started the generation when null).
     * @param array $jserrors Errors of the JavaScript modules in the preview (spec B11):
     *                        [['target' => string, 'message' => string]]; each module is repaired once per generation.
     * @param array $colourwarnings Colour warnings of the colour modes (spec CM4, CM6), shown only, never repaired:
     *                        [['target', 'selector', 'kind' => 'fixed'|'contrast', 'mode', 'ratio']].
     * @return stdClass Generation record.
     */
    public static function verify(
        int $id,
        array $failures,
        array $warnings,
        int $checked,
        ?generator_interface $generator = null,
        array $jserrors = [],
        array $colourwarnings = []
    ): stdClass {
        global $DB;

        $lock = \core\lock\lock_config::get_lock_factory('theme_brandable')->get_lock('generation_' . $id, 30);
        if (!$lock) {
            return $DB->get_record('theme_brandable_generation', ['id' => $id], '*', MUST_EXIST);
        }
        try {
            $record = $DB->get_record('theme_brandable_generation', ['id' => $id], '*', MUST_EXIST);
            if ($record->status !== self::COMPLETED || empty($record->revisionid)) {
                return $record;
            }
            $run = (int) $record->repairs;
            $events = json_decode($record->localevents ?: '[]', true);
            foreach ($events as $event) {
                $done = ['verified', 'verification_failed', self::EVENT_JSERROR];
                if ($event['run'] === $run && in_array($event['type'], $done, true)) {
                    // Already verified (editor reloaded, or two editors open).
                    return $record;
                }
            }
            if ($warnings) {
                $lines = array_map(fn($w) => get_string('verification:outside', 'theme_brandable', (object) $w), $warnings);
                self::add_event($record, 'verification_warning', implode("\n", $lines), $run);
            }
            if ($colourwarnings) {
                self::add_event($record, 'verification_warning', self::describe_colour_warnings($colourwarnings), $run);
            }
            // Errors of the modules: sent to the repair once per module and per generation, then only shown.
            $modules = $DB->get_fieldset_select(
                'theme_brandable_change',
                'target',
                'revisionid = ? AND type = ? AND deleted = 0',
                [(int) $record->revisionid, change::TYPE_JS]
            );
            $jserrors = self::clean_js_errors($jserrors, $modules);
            $reported = self::get_reported_js_targets($events);
            $again = array_intersect_key($jserrors, array_flip($reported));
            $jserrors = array_diff_key($jserrors, $again);
            if ($again) {
                self::add_event($record, 'verification_warning', get_string(
                    'verification:jsstillfailing',
                    'theme_brandable',
                    implode(', ', array_keys($again))
                ), $run);
            }
            if ($jserrors) {
                $lines = array_map(fn($error) => $error['target'] . ' : ' . $error['message'], $jserrors);
                self::add_event(
                    $record,
                    self::EVENT_JSERROR,
                    get_string('verification:jserror', 'theme_brandable') . "\n" . implode("\n", $lines),
                    $run
                );
            }
            if (!$failures && !$jserrors) {
                self::add_event($record, 'verified', $modules
                    ? get_string('verification:okjs', 'theme_brandable', (object) ['selectors' => $checked,
                        'modules' => count(array_unique($modules))])
                    : get_string('verification:ok', 'theme_brandable', $checked), $run);
            } else {
                if ($failures) {
                    $lines = array_map(fn($f) => $f['target'] . ' : ' . $f['selector'], $failures);
                    self::add_event(
                        $record,
                        'verification_failed',
                        get_string('verification:nomatch', 'theme_brandable') . "\n" . implode("\n", $lines),
                        $run
                    );
                }
                self::repair_after_verification(
                    $record,
                    $failures,
                    $warnings,
                    $generator ?? factory::get($record->generator),
                    array_values($jserrors)
                );
            }
            $record->timemodified = time();
            $DB->update_record('theme_brandable_generation', $record);
            return $record;
        } finally {
            $lock->release();
        }
    }

    /**
     * Text of the colour warnings of a verification, one line each.
     *
     * @param array $colourwarnings See verify().
     * @return string
     */
    private static function describe_colour_warnings(array $colourwarnings): string {
        $lines = [];
        foreach ($colourwarnings as $warning) {
            $a = (object) ['target' => (string) $warning['target'], 'selector' => (string) $warning['selector']];
            if (($warning['kind'] ?? '') === 'contrast') {
                $mode = ($warning['mode'] ?? '') === 'dark' ? 'dark' : 'light';
                $a->mode = get_string('verification:mode_' . $mode, 'theme_brandable');
                $a->ratio = format_float((float) ($warning['ratio'] ?? 0), 1);
                $lines[] = get_string('verification:contrast', 'theme_brandable', $a);
            } else {
                $lines[] = get_string('verification:fixedcolour', 'theme_brandable', $a);
            }
        }
        return implode("\n", array_unique($lines));
    }

    /**
     * Abandons the revision and asks for a repair, when still possible.
     *
     * @param stdClass $record Generation record (updated in place).
     * @param array $failures Selectors matching no element.
     * @param array $warnings Selectors matching elements out of the targeted element (sent along with the failures).
     * @param generator_interface $generator Generator.
     * @param array $jserrors Errors of the JavaScript modules (contract 12.8): [['target', 'message']].
     */
    private static function repair_after_verification(
        stdClass $record,
        array $failures,
        array $warnings,
        generator_interface $generator,
        array $jserrors = []
    ): void {
        $run = (int) $record->repairs;
        if ($run >= self::MAX_REPAIRS) {
            self::add_event($record, 'verification_failed', get_string('verification:kept', 'theme_brandable'), $run);
            return;
        }
        if (!revision_manager::abandon((int) $record->revisionid, (int) $record->basedraft)) {
            // The administrator moved on: keep the revision.
            self::add_event($record, 'verification_failed', get_string('verification:kept', 'theme_brandable'), $run);
            return;
        }
        $errors = array_merge(
            array_map(fn($f) => ['target' => $f['target'], 'message' => 'selector matches no element: ' . $f['selector'],
                'kind' => 'selector_no_match', 'selector' => $f['selector'], 'matches' => 0], $failures),
            array_map(fn($w) => ['target' => $w['target'],
                'message' => 'selector also matches ' . $w['count'] . ' element(s) outside the targeted element: ' . $w['selector'],
                'kind' => 'selector_outside_target', 'selector' => $w['selector'], 'matches' => (int) $w['count']], $warnings),
            // Contract 12.8: the message only, no position (it would be in the compiled code, not in content).
            array_map(fn($e) => ['target' => $e['target'], 'kind' => 'js_runtime_error', 'message' => $e['message']], $jserrors)
        );
        try {
            $generator->repair($record->remoteid, $errors);
        } catch (generator_exception $e) {
            self::fail($record, $e->generatorcode, $e->displaymessage, $e->url);
            return;
        }
        $record->repairs = $run + 1;
        $record->status = self::RUNNING;
        $record->revisionid = null;
        $record->summary = null;
    }

    /**
     * Keeps the errors of the modules of the revision, one per target, their message cleaned and masked
     * ({@see \theme_brandable\local\js\modules::clean_error()}).
     *
     * @param array $jserrors Errors reported by the editor.
     * @param string[] $modules Targets of the modules of the revision.
     * @return array Errors by target.
     */
    private static function clean_js_errors(array $jserrors, array $modules): array {
        $clean = [];
        foreach ($jserrors as $error) {
            $target = (string) ($error['target'] ?? '');
            if (!in_array($target, $modules, true) || isset($clean[$target])) {
                continue;
            }
            // Masked before it is cut (a cut email would no longer be recognised), and before anything is sent.
            $message = \theme_brandable\local\js\modules::clean_error((string) ($error['message'] ?? ''));
            $clean[$target] = ['target' => $target, 'message' => $message];
        }
        return $clean;
    }

    /**
     * Returns the modules whose errors were already sent to a repair in this generation.
     *
     * @param array $events Events of the plugin.
     * @return string[] Targets.
     */
    private static function get_reported_js_targets(array $events): array {
        $targets = [];
        foreach ($events as $event) {
            if ($event['type'] !== self::EVENT_JSERROR) {
                continue;
            }
            // First line: the title; then "target : message".
            foreach (array_slice(explode("\n", (string) $event['message']), 1) as $line) {
                $targets[] = explode(' : ', $line, 2)[0];
            }
        }
        return $targets;
    }

    /**
     * Sends again a request that needed the generated JavaScript, once it is on (spec B3): same prompt, page, element
     * and images, answering the same question if it is still the last one.
     *
     * @param int $id Generation that needed the JavaScript.
     * @param generator_interface|null $generator Generator (the active one when null).
     * @return stdClass New generation record.
     */
    public static function resend(int $id, ?generator_interface $generator = null): stdClass {
        global $DB;
        $record = $DB->get_record('theme_brandable_generation', ['id' => $id, 'status' => self::JSACTIVATION]);
        if (!$record) {
            throw new moodle_exception('error:cannotresend', 'theme_brandable');
        }
        if (!\theme_brandable\local\js\modules::is_enabled()) {
            throw new moodle_exception('error:jsnotenabled', 'theme_brandable');
        }
        $questionid = (int) ($record->questionid ?? 0);
        if ($questionid && !$DB->record_exists('theme_brandable_generation', ['id' => $questionid, 'status' => self::QUESTION])) {
            $questionid = 0;
        }
        $selection = json_decode((string) ($record->selection ?? ''), true);
        $attachments = json_decode((string) ($record->attachments ?? ''), true);
        $new = self::submit(
            (string) $record->prompt,
            $record->pageurl ?: null,
            $generator,
            is_array($selection) ? $selection : null,
            $questionid,
            is_array($attachments) ? $attachments : []
        );
        // Sent once: the request is no longer waiting for the JavaScript.
        $DB->set_field(
            'theme_brandable_generation',
            'status',
            self::JSRESENT,
            ['id' => $record->id, 'status' => self::JSACTIVATION]
        );
        return $new;
    }

    /**
     * Returns the exchange a question ends: the prompts and questions leading to it, oldest first.
     *
     * @param int $questionid Generation that asked the question.
     * @return array List of ['prompt', 'summary'].
     */
    public static function get_exchange(int $questionid): array {
        global $DB;

        $exchange = [];
        $seen = [];
        while ($questionid && !isset($seen[$questionid]) && count($exchange) < self::MAX_EXCHANGE) {
            $seen[$questionid] = true;
            $record = $DB->get_record('theme_brandable_generation', ['id' => $questionid], 'id, prompt, summary, questionid');
            if (!$record) {
                break;
            }
            array_unshift($exchange, ['prompt' => (string) $record->prompt, 'summary' => (string) $record->summary]);
            $questionid = (int) $record->questionid;
        }
        return $exchange;
    }

    /**
     * Whether a generation failed because of the service (it can be retried as is).
     *
     * @param stdClass $record Generation record.
     * @return bool
     */
    public static function is_transient(stdClass $record): bool {
        return $record->status === self::FAILED && in_array((string) ($record->errorcode ?? ''), self::TRANSIENT_CODES, true);
    }

    /**
     * Returns what the editor offers for a generation that failed because of the account.
     *
     * @param stdClass $record Generation record.
     * @return string connect, relink, credits, plan, upgrade (update of the plugin), or empty.
     */
    public static function get_action(stdClass $record): string {
        if ($record->status !== self::FAILED) {
            return '';
        }
        return self::ACCOUNT_ACTIONS[(string) ($record->errorcode ?? '')] ?? '';
    }

    /**
     * Exports a generation for the editor.
     *
     * @param stdClass $record Generation record.
     * @return array
     */
    public static function export(stdClass $record): array {
        $events = array_merge(json_decode($record->remoteevents ?: '[]', true), json_decode($record->localevents ?: '[]', true));
        usort($events, fn($a, $b) => [$a['run'], $a['at']] <=> [$b['run'], $b['at']]);
        return [
            'id' => (int) $record->id,
            'status' => $record->status,
            'prompt' => (string) $record->prompt,
            'summary' => (string) ($record->summary ?? ''),
            'revisionid' => (int) ($record->revisionid ?? 0),
            'repairs' => (int) $record->repairs,
            'events' => $events,
            'errorcode' => (string) ($record->errorcode ?? ''),
            // Failures of the service: a clear message instead of the raw one (still in the steps, from the backend).
            'errormessage' => self::is_transient($record) ? get_string('error:serviceunavailable', 'theme_brandable')
                : (string) ($record->errormessage ?? ''),
            'retryable' => self::is_transient($record) || in_array(self::get_action($record), ['credits', 'plan', 'upgrade'], true),
            'action' => self::get_action($record),
            'errorurl' => (string) ($record->errorurl ?? ''),
            'selection' => (string) ($record->selection ?? ''),
            'pageurl' => (string) ($record->pageurl ?? ''),
            'questionid' => (int) ($record->questionid ?? 0),
            // Fonts that could not be downloaded: the same result is read again (no new generation).
            'resumable' => self::can_resume($record),
            'attachments' => array_map('intval', json_decode((string) ($record->attachments ?? ''), true) ?: []),
        ];
    }

    /**
     * Polls the generator once and acts on its status.
     *
     * @param stdClass $record Generation record (updated in place).
     * @param generator_interface $generator Generator.
     */
    private static function advance(stdClass $record, generator_interface $generator): void {
        try {
            $status = $generator->poll($record->remoteid);
        } catch (generator_exception $e) {
            self::fail($record, $e->generatorcode, $e->displaymessage, $e->url);
            return;
        }
        $record->remoteevents = json_encode($status->events);

        if ($status->status === generation_status::ERROR) {
            self::fail($record, $status->error['code'] ?? 'error', $status->error['message'] ?? '');
            return;
        }
        // The result of the current run only (after a repair, the previous result may still be shown until
        // the backend picks the repair up).
        if ($status->status !== generation_status::DONE || $status->result === null || !self::run_is_done($status, $record)) {
            return;
        }
        if ($status->result->pluginupgrade !== null) {
            // No credit, nothing to validate (contract 15.3): the summary explains it, the editor offers the update.
            self::fail($record, self::CAPABILITIES_ERROR, $status->result->summary !== '' ? $status->result->summary
                : get_string('pluginupdate:capabilities', 'theme_brandable'), $status->result->pluginupgrade['url']);
            return;
        }
        if ($status->result->needsjsactivation) {
            // No credit, nothing to validate nor to repair (contract 12.3): sent again once the JavaScript is on.
            $record->summary = $status->result->summary;
            $record->status = self::JSACTIVATION;
            return;
        }
        if ($status->result->needsclarification) {
            // Nothing to validate nor to repair: the administrator answers with a new prompt.
            $record->summary = $status->result->summary;
            $record->status = self::QUESTION;
            return;
        }

        // Fonts of the catalogue first (contract 11.6): the markers of their chunk designate these files.
        if ($status->result->fontrequests && !self::download_fonts($record, $status->result->fontrequests)) {
            return;
        }

        try {
            $record->revisionid = revision_manager::create(
                $status->result->changes,
                (string) $record->prompt,
                $status->result->summary,
                (int) $record->userid,
                (int) $record->basedraft
            );
            $record->summary = $status->result->summary;
            $record->status = self::COMPLETED;
            // Images and fonts deleted from the library since the generation: shown empty (contract 11.3).
            $missing = [];
            foreach ($status->result->changes as $change) {
                if (!$change->deleted) {
                    array_push($missing, ...markers::missing($change->type, (string) $change->content));
                }
            }
            if ($missing) {
                self::add_event($record, 'verification_warning', get_string(
                    'generation:missingassets',
                    'theme_brandable',
                    implode(', ', array_unique($missing))
                ), (int) $record->repairs);
            }
        } catch (validation_exception $e) {
            self::reject($record, $generator, $e->errors);
        } catch (moodle_exception $e) {
            self::fail($record, $e->errorcode, $e->getMessage());
        }
    }

    /**
     * Downloads a batch of the fonts asked by a result (one batch per poll; the generation stays running meanwhile).
     *
     * @param stdClass $record Generation record (failed on an error: the result is kept by Brandable for 24 hours,
     *                         resume() tries again).
     * @param array $requests result.fontRequests.
     * @return bool Whether every file is in the library.
     */
    private static function download_fonts(stdClass $record, array $requests): bool {
        $mock = $record->generator === 'mock';
        try {
            $parsed = font_downloader::parse($requests, $mock);
            $client = $mock ? null : new api_client(connection::get_api_url(), connection::get_key());
            $result = font_downloader::download($parsed, $client);
        } catch (api_exception $e) {
            account::handle_error($e->errorcode);
            self::fail($record, self::FONT_ERROR, get_string('generation:fontsfailed', 'theme_brandable', $e->getMessage()));
            return false;
        } catch (moodle_exception $e) {
            self::fail($record, self::FONT_ERROR, get_string('generation:fontsfailed', 'theme_brandable', $e->getMessage()));
            return false;
        }
        $total = array_sum(array_map(fn($request) => count($request['files']), $parsed));
        if ($result['stored'] > 0 || !$result['done']) {
            self::add_event($record, 'fonts', get_string(
                'generation:fonts',
                'theme_brandable',
                ['done' => $total - $result['missing'], 'total' => $total]
            ), (int) $record->repairs);
        }
        return $result['done'];
    }

    /**
     * Resumes a generation whose fonts could not be downloaded: the same result is read again from Brandable (kept
     * 24 hours), nothing is generated again (no credit).
     *
     * @param int $id Generation id.
     * @param generator_interface|null $generator Generator (the one that started the generation when null).
     * @return stdClass Generation record.
     */
    public static function resume(int $id, ?generator_interface $generator = null): stdClass {
        global $DB;
        $record = $DB->get_record('theme_brandable_generation', ['id' => $id], '*', MUST_EXIST);
        if (!self::can_resume($record)) {
            throw new moodle_exception('error:cannotresume', 'theme_brandable');
        }
        $record->status = self::RUNNING;
        $record->errorcode = null;
        $record->errormessage = null;
        $record->errorurl = null;
        $record->timemodified = time();
        $DB->update_record('theme_brandable_generation', $record);
        return self::refresh($id, $generator);
    }

    /**
     * Whether a generation failed on its fonts and can be resumed (result kept 24 hours by Brandable).
     *
     * @param stdClass $record Generation record.
     * @return bool
     */
    public static function can_resume(stdClass $record): bool {
        return $record->status === self::FAILED && $record->errorcode === self::FONT_ERROR
            && time() - (int) $record->timecreated < DAYSECS;
    }

    /**
     * Whether the backend finished the run matching the repairs requested so far.
     *
     * @param generation_status $status Status.
     * @param stdClass $record Generation record.
     * @return bool
     */
    private static function run_is_done(generation_status $status, stdClass $record): bool {
        foreach ($status->events as $event) {
            if ($event['type'] === 'done' && $event['run'] === (int) $record->repairs) {
                return true;
            }
        }
        // Generators that do not report events: trust the status.
        return !$status->events;
    }

    /**
     * Records a validation failure and asks for a repair when still allowed.
     *
     * @param stdClass $record Generation record.
     * @param generator_interface $generator Generator.
     * @param array $errors Validation errors.
     */
    private static function reject(stdClass $record, generator_interface $generator, array $errors): void {
        $lines = array_map(fn($error) => ($error['target'] !== '' ? $error['target'] . ' : ' : '') . $error['message'], $errors);
        self::add_event(
            $record,
            self::EVENT_REJECTED,
            get_string('event:rejected', 'theme_brandable') . "\n" . implode("\n", $lines),
            (int) $record->repairs
        );

        if ((int) $record->repairs >= self::MAX_REPAIRS) {
            self::fail($record, 'validation_failed', get_string('error:stillinvalid', 'theme_brandable'));
            return;
        }
        try {
            $generator->repair($record->remoteid, $errors);
            $record->repairs = (int) $record->repairs + 1;
        } catch (generator_exception $e) {
            self::fail($record, $e->generatorcode, $e->displaymessage, $e->url);
        }
    }

    /**
     * Marks a generation as failed.
     *
     * @param stdClass $record Generation record.
     * @param string $code Error code.
     * @param string $message Message shown to the administrator.
     * @param string $url Page where the administrator can act (recharge, plan), empty if none.
     */
    private static function fail(stdClass $record, string $code, string $message, string $url = ''): void {
        $record->status = self::FAILED;
        $record->errorcode = \core_text::substr($code, 0, 100);
        $record->errorurl = $url !== '' ? $url : null;
        // A key refused by the API is forgotten: the editor shows the connection screen.
        \theme_brandable\local\account\account::handle_error($code);
        $record->errormessage = $message !== '' ? $message : get_string('error:generationfailed', 'theme_brandable');
        if ($message !== '' && in_array($code, ['connection_failed', 'http_429', 'http_502', 'http_503', 'http_504'], true)) {
            // Raised by the plugin (the backend could not tell): keep the details in the steps, the editor shows
            // a clear message instead.
            self::add_event($record, 'error', $message, (int) $record->repairs);
        }
    }

    /**
     * Adds an event of the plugin.
     *
     * @param stdClass $record Generation record.
     * @param string $type Type.
     * @param string $message Message.
     * @param int $run Run.
     */
    private static function add_event(stdClass $record, string $type, string $message, int $run): void {
        $events = json_decode($record->localevents ?: '[]', true);
        $events[] = ['type' => $type, 'message' => $message, 'at' => gmdate('Y-m-d\TH:i:s.000\Z'), 'run' => $run];
        $record->localevents = json_encode($events);
    }
}
