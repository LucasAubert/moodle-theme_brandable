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

use cache;

/**
 * Base class of synchronous generators: implement generate(), the asynchronous protocol is handled here.
 *
 * The result is computed by start() and kept in an application cache until the editor polls it. A repair
 * runs repair_result() on the previous result, with the same limit as the backend (2 repairs).
 *
 * @package    theme_brandable
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class sync_generator implements generator_interface {
    /** @var int Maximum repairs, as the backend. */
    public const MAX_REPAIRS = 2;

    /**
     * Generates the changes for a prompt.
     *
     * @param string $prompt Prompt.
     * @param generation_context $context Context.
     * @return generation_result
     * @throws generator_exception
     */
    abstract public function generate(string $prompt, generation_context $context): generation_result;

    /**
     * Produces a new attempt after the plugin's validation failed. Default: drops the rejected changes.
     *
     * @param generation_result $result Previous result.
     * @param array $errors List of ['target', 'message'].
     * @return generation_result|null Null when nothing valid remains.
     */
    protected function repair_result(generation_result $result, array $errors): ?generation_result {
        $rejected = array_column($errors, 'target');
        $changes = array_values(array_filter($result->changes, fn($change) => !in_array($change->target, $rejected, true)));
        return $changes ? new generation_result($result->summary, $changes) : null;
    }

    #[\Override]
    public function start(string $prompt, generation_context $context): string {
        $id = 'sync_' . random_string(24);
        $events = [self::event('planning', 'event:planning', 0)];
        try {
            $result = $this->generate($prompt, $context);
        } catch (generator_exception $e) {
            $this->store($id, ['run' => 0, 'events' => array_merge($events, [self::event('error', $e->displaymessage, 0, false)]),
                'result' => null, 'error' => ['code' => $e->generatorcode, 'message' => $e->displaymessage]]);
            return $id;
        }
        $events[] = $result->needsclarification ? self::event('clarification', $result->summary, 0, false)
            : self::event('editing', 'event:editing', 0);
        $events[] = self::event('done', 'event:done', 0);
        $this->store($id, ['run' => 0, 'events' => $events, 'result' => $result->to_array(), 'error' => null]);
        return $id;
    }

    #[\Override]
    public function poll(string $id): generation_status {
        $data = $this->load($id);
        $status = $data['error'] ? generation_status::ERROR : generation_status::DONE;
        return new generation_status(
            $status,
            $data['events'],
            $data['result'] ? generation_result::from_array($data['result']) : null,
            $data['error']
        );
    }

    #[\Override]
    public function repair(string $id, array $errors): void {
        $data = $this->load($id);
        if ($data['result'] === null || !empty($data['result']['needsClarification'])) {
            throw new generator_exception('conflict', get_string('error:repairconflict', 'theme_brandable'));
        }
        if ($data['run'] >= self::MAX_REPAIRS) {
            throw new generator_exception('repair_limit_reached', get_string('error:repairlimit', 'theme_brandable'));
        }
        $run = $data['run'] + 1;
        $data['run'] = $run;
        $data['events'][] = self::event('repairing', 'event:repairing', $run);
        $result = $this->repair_result(generation_result::from_array($data['result']), $errors);
        if ($result === null) {
            $message = get_string('error:nothingvalid', 'theme_brandable');
            $data['events'][] = self::event('error', $message, $run, false);
            $data['result'] = null;
            $data['error'] = ['code' => 'validation_failed', 'message' => $message];
        } else {
            $data['events'][] = self::event('done', 'event:done', $run);
            $data['result'] = $result->to_array();
        }
        $this->store($id, $data);
    }

    /**
     * Builds an event.
     *
     * @param string $type Event type.
     * @param string $message Message, or string identifier when $isstring.
     * @param int $run Run.
     * @param bool $isstring Whether $message is a theme_brandable string identifier.
     * @return array
     */
    protected static function event(string $type, string $message, int $run, bool $isstring = true): array {
        return [
            'type' => $type,
            'message' => $isstring ? get_string($message, 'theme_brandable') : $message,
            'at' => gmdate('Y-m-d\TH:i:s\Z'),
            'run' => $run,
        ];
    }

    /**
     * Stores the state of a generation.
     *
     * @param string $id Generation id.
     * @param array $data State.
     */
    private function store(string $id, array $data): void {
        cache::make('theme_brandable', 'syncresults')->set($id, $data);
    }

    /**
     * Loads the state of a generation.
     *
     * @param string $id Generation id.
     * @return array
     */
    private function load(string $id): array {
        $data = cache::make('theme_brandable', 'syncresults')->get($id);
        if (!is_array($data)) {
            throw new generator_exception('context_expired', get_string('error:contextexpired', 'theme_brandable'));
        }
        return $data;
    }
}
