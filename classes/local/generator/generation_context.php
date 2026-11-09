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

use theme_brandable\local\assets\request;
use theme_brandable\local\inspector\inspector;
use theme_brandable\local\js\consent;
use theme_brandable\local\overrides\change;
use theme_brandable\local\overrides\resolver;
use theme_brandable\local\overrides\revision_manager;
use theme_brandable\local\overrides\template_source;
use theme_brandable\local\parent_theme;

/**
 * Everything a generator knows about the site when it receives a prompt.
 *
 * Mirrors the body of POST /api/v1/generations (backend contract v2): lang, moodleVersion, parentTheme,
 * themeChain, page, pageTemplates, draftOverrides (in effective concatenation order) and history.
 *
 * @package    theme_brandable
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class generation_context {
    /** @var int Maximum page templates sent (backend limit). */
    public const MAX_PAGE_TEMPLATES = 200;

    /** @var int Maximum history entries sent (backend limit). */
    public const MAX_HISTORY = 50;

    /** @var int Maximum length of texts sent (prompts, summaries). */
    public const MAX_TEXT = 4000;

    /** @var int Request size budget, below the 8 MiB backend limit. */
    public const MAX_BYTES = 7 * 1024 * 1024;

    /**
     * Constructor.
     *
     * @param string $lang Language of the administrator.
     * @param string $moodleversion Moodle release, X.Y.Z.
     * @param string $parenttheme Parent theme.
     * @param string[] $themechain Parent theme, then its ancestors.
     * @param array $page ['url' => string, 'pagetype' => string, 'pageKey' => ?string, 'courseId' => ?string,
     *                    'cmId' => ?string] (ids as strings of digits, null outside a course / activity).
     * @param array $pagetemplates List of ['name', 'source', 'sourceHash', 'sampleContext'].
     * @param array $draftoverrides List of ['type', 'target', 'content'].
     * @param array $history List of ['prompt', 'summary'], oldest first.
     * @param int $draft Draft revision the context describes.
     * @param array|null $selection Element targeted in the preview, cleaned (see selection).
     * @param string $sessionid Session the prompt is sent in (UUID).
     * @param string[] $attachments Slugs of the images of the library attached to the prompt.
     * @param bool $jsenabled Whether the generated JavaScript is on (contract 12.2).
     * @param string $jsconsentversion Version of the text of the consent accepted, empty if none.
     */
    public function __construct(
        /** @var string Language. */
        public readonly string $lang,
        /** @var string Moodle release. */
        public readonly string $moodleversion,
        /** @var string Parent theme. */
        public readonly string $parenttheme,
        /** @var string[] Theme chain. */
        public readonly array $themechain,
        /** @var array Page. */
        public readonly array $page,
        /** @var array Page templates. */
        public readonly array $pagetemplates,
        /** @var array Draft overrides. */
        public readonly array $draftoverrides,
        /** @var array History. */
        public readonly array $history,
        /** @var int Draft revision. */
        public readonly int $draft,
        /** @var array|null Element targeted in the preview (see selection). */
        public readonly ?array $selection = null,
        /** @var string Session (UUID). */
        public readonly string $sessionid = '',
        /** @var string[] Slugs of the images attached to the prompt (their thumbnail is sent). */
        public readonly array $attachments = [],
        /** @var bool Whether the generated JavaScript is on. */
        public readonly bool $jsenabled = false,
        /** @var string Version of the text of the consent accepted. */
        public readonly string $jsconsentversion = '',
    ) {
    }

    /**
     * Builds the context of the current draft, for the page shown in the editor preview.
     *
     * @param string|null $pageurl URL shown in the preview iframe (the last inspected page when null or unknown).
     * @param array|null $selection Element targeted in the preview, cleaned (see selection).
     * @param array $exchange Question being answered: prompts and questions leading to it, oldest first.
     * @param int[] $attachments Ids of the images of the library attached to the prompt.
     * @return self
     */
    public static function build(
        ?string $pageurl = null,
        ?array $selection = null,
        array $exchange = [],
        array $attachments = []
    ): self {
        $draft = revision_manager::get_draft();
        $parent = parent_theme::get_name();
        $map = resolver::get_map($draft);

        $inspected = ($pageurl !== null ? inspector::get_page($pageurl) : null) ?? inspector::get_page();
        $page = [
            'url' => $pageurl ?? ($inspected['url'] ?? '/'),
            'pagetype' => $inspected['pagetype'] ?? 'unknown',
            'pageKey' => $inspected['pagekey'] ?? null,
            'courseId' => self::export_id($inspected['courseid'] ?? null, true),
            'cmId' => self::export_id($inspected['cmid'] ?? null),
        ];
        if ($page['pagetype'] === '') {
            $page['pagetype'] = 'unknown';
        }

        $js = consent::for_generation();
        return new self(
            self::get_lang(),
            template_source::get_moodle_version(),
            $parent,
            parent_theme::get_chain($parent),
            $page,
            self::export_page_templates($inspected['templates'] ?? []),
            self::export_overrides($map),
            self::export_history($exchange),
            $draft,
            $selection,
            revision_manager::get_session_id(),
            request::slugs($attachments),
            $js['enabled'],
            $js['version']
        );
    }

    /**
     * Returns the body of POST /api/v1/generations.
     *
     * @param string $prompt Prompt.
     * @return array
     */
    public function to_request(string $prompt): array {
        $body = [
            'prompt' => self::truncate($prompt),
            'lang' => $this->lang,
            'moodleVersion' => $this->moodleversion,
            'parentTheme' => $this->parenttheme,
            'themeChain' => $this->themechain,
            // Colour modes of Moodle 5.3 (spec of the colour modes, CM3): always sent, also when they are off, so that
            // the generator prefers the colours that follow the mode.
            'colourModes' => \theme_brandable\local\colour_modes::to_context(),
            'page' => $this->page,
            'pageTemplates' => $this->pagetemplates,
            'draftOverrides' => $this->draftoverrides,
            'history' => $this->history,
            // The library (contract 11.2): images, fonts by family, thumbnails of the attached images and of the logo.
            'assets' => request::assets($this->attachments, $prompt, resolver::get_map($this->draft)),
        ];
        // Generated JavaScript (contract 12.2): the setting, and the version of the consent accepted.
        $body['jsEnabled'] = $this->jsenabled;
        if ($this->jsconsentversion !== '') {
            $body['jsConsentVersion'] = $this->jsconsentversion;
        }
        if ($this->sessionid !== '') {
            $body['sessionId'] = $this->sessionid;
        }
        if ($this->selection !== null) {
            $body['selection'] = selection::to_request($this->selection);
        }
        // Keep within the request size limit: page templates are the only optional bulk.
        while (strlen((string) json_encode($body)) > self::MAX_BYTES && $body['pageTemplates']) {
            $dropped = array_pop($body['pageTemplates']);
            debugging(
                "theme_brandable: page template {$dropped['name']} not sent to the generator (request size limit).",
                DEBUG_DEVELOPER
            );
        }
        return $body;
    }

    /**
     * Exports an id as the contract expects: a string of digits, or null. The site course is never a course.
     *
     * @param mixed $id Id.
     * @param bool $course Whether this is a course id.
     * @return string|null
     */
    private static function export_id($id, bool $course = false): ?string {
        if ($id === null || $id === '' || (int) $id <= 0 || ($course && (int) $id === (int) SITEID)) {
            return null;
        }
        return (string) (int) $id;
    }

    /**
     * Exports the inspected templates with their original source (never the override).
     *
     * Templates existing only in the database (theme_brandable/*) are already part of draftOverrides.
     * Templates over the count limit are left out with a debugging message: never silently.
     *
     * @param array $templates Inspected templates: ['name', 'sampleContext'].
     * @return array
     */
    private static function export_page_templates(array $templates): array {
        $result = [];
        foreach ($templates as $template) {
            $name = $template['name'];
            if (count($result) >= self::MAX_PAGE_TEMPLATES) {
                debugging("theme_brandable: page template {$name} not sent to the generator (more than "
                    . self::MAX_PAGE_TEMPLATES . ' templates).', DEBUG_DEVELOPER);
                continue;
            }
            $source = template_source::get_original($name);
            if ($source === null) {
                // Database-only template (theme_brandable/*): already sent in draftOverrides.
                continue;
            }
            $result[] = [
                'name' => $name,
                'source' => $source,
                'sourceHash' => template_source::hash($source),
                'sampleContext' => $template['sampleContext'],
            ];
        }
        return $result;
    }

    /**
     * Exports the draft overrides in effective order: pre/* SCSS chunks, post/* chunks, then templates.
     *
     * @param array $map Resolved map of the draft.
     * @return array
     */
    public static function export_overrides(array $map): array {
        $export = fn(string $type, array $entry) => [
            'type' => $type,
            'target' => $entry['target'],
            'content' => $entry['content'],
            'scope' => resolver::entry_scope($entry)->to_array(),
        ];
        $overrides = [];
        foreach (['pre/', 'post/'] as $section) {
            foreach ($map[change::TYPE_SCSS] as $entry) {
                if (strpos($entry['target'], $section) === 0) {
                    $overrides[] = $export(change::TYPE_SCSS, $entry);
                }
            }
        }
        foreach ([change::TYPE_TEMPLATE, change::TYPE_JS, change::TYPE_SETTING] as $type) {
            foreach ($map[$type] ?? [] as $entry) {
                $overrides[] = $export($type, $entry);
            }
        }
        return $overrides;
    }

    /**
     * Exports the prompts and summaries of the session leading to the draft, oldest first, then the question being
     * answered. The versions published before are not part of it.
     *
     * A revision created by an answer comes after its exchange, so that the answer keeps its meaning.
     *
     * @param array $exchange Question being answered (see generation_manager::get_exchange()).
     * @return array
     */
    private static function export_history(array $exchange): array {
        global $DB;

        $entries = [];
        $ids = array_slice(revision_manager::get_session_path(), 0, self::MAX_HISTORY);
        if ($ids) {
            $revisions = $DB->get_records_list('theme_brandable_revision', 'id', $ids, '', 'id, prompt, summary');
            [$insql, $params] = $DB->get_in_or_equal($ids);
            $answers = $DB->get_records_select_menu(
                'theme_brandable_generation',
                "revisionid $insql AND questionid IS NOT NULL",
                $params,
                '',
                'revisionid, questionid'
            );
            foreach (array_reverse($ids) as $id) {
                if (!empty($answers[$id])) {
                    $entries = array_merge($entries, generation_manager::get_exchange((int) $answers[$id]));
                }
                $entries[] = ['prompt' => $revisions[$id]->prompt, 'summary' => $revisions[$id]->summary];
            }
        }
        $entries = array_slice(array_merge($entries, $exchange), -self::MAX_HISTORY);
        return array_map(fn($entry) => [
            'prompt' => self::truncate((string) $entry['prompt']),
            'summary' => self::truncate((string) $entry['summary']),
        ], $entries);
    }

    /**
     * Returns the language sent to the generator (lowercase, as the backend expects).
     *
     * @return string
     */
    public static function get_lang(): string {
        $lang = strtolower(current_language());
        return preg_match('/^[a-z]{2,3}(?:_[a-z0-9]+)*$/', $lang) ? $lang : 'en';
    }

    /**
     * Truncates a text to the backend limit.
     *
     * @param string $text Text.
     * @return string
     */
    private static function truncate(string $text): string {
        return \core_text::substr(trim($text), 0, self::MAX_TEXT);
    }
}
