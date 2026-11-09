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

/**
 * Status of a generation as reported by a generator.
 *
 * Statuses follow the backend contract: pending, running, done, error.
 *
 * @package    theme_brandable
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class generation_status {
    /** @var string Waiting for a worker (also after a repair request). */
    public const PENDING = 'pending';
    /** @var string Being generated. */
    public const RUNNING = 'running';
    /** @var string Finished, result available. */
    public const DONE = 'done';
    /** @var string Failed, error available. */
    public const ERROR = 'error';

    /**
     * Constructor.
     *
     * @param string $status One of the constants.
     * @param array $events List of ['type', 'message', 'at', 'run'], all runs included.
     * @param generation_result|null $result Result when done.
     * @param array|null $error ['code' => string, 'message' => string] when failed.
     */
    public function __construct(
        /** @var string Status. */
        public readonly string $status,
        /** @var array Events. */
        public readonly array $events = [],
        /** @var generation_result|null Result. */
        public readonly ?generation_result $result = null,
        /** @var array|null Error. */
        public readonly ?array $error = null,
    ) {
    }

    /**
     * Builds a status from the contract format.
     *
     * @param array $data Decoded GET /generations/:id response.
     * @return self
     */
    public static function from_array(array $data): self {
        $events = [];
        foreach ($data['events'] ?? [] as $event) {
            if (is_array($event)) {
                $events[] = [
                    'type' => (string) ($event['type'] ?? ''),
                    'message' => (string) ($event['message'] ?? ''),
                    'at' => (string) ($event['at'] ?? ''),
                    'run' => (int) ($event['run'] ?? 0),
                ];
            }
        }
        $status = (string) ($data['status'] ?? self::PENDING);
        if (!in_array($status, [self::PENDING, self::RUNNING, self::DONE, self::ERROR], true)) {
            $status = self::RUNNING;
        }
        $result = isset($data['result']) && is_array($data['result']) ? generation_result::from_array($data['result']) : null;
        $error = isset($data['error']) && is_array($data['error'])
            ? ['code' => (string) ($data['error']['code'] ?? 'error'), 'message' => (string) ($data['error']['message'] ?? '')]
            : null;
        return new self($status, $events, $result, $error);
    }
}
