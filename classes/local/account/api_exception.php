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

namespace theme_brandable\local\account;

/**
 * Error of the Brandable API: { error: { code, message, url? } }, or a failure to reach it.
 *
 * @package    theme_brandable
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class api_exception extends \moodle_exception {
    /** @var string Key of the details: seconds to wait before trying again (Retry-After of the answer). */
    public const RETRY_AFTER = 'retryafter';

    /** @var int Seconds to wait when Brandable cannot be reached and says nothing (contract 7). */
    public const DEFAULT_RETRY_AFTER = 60;

    /**
     * Constructor.
     *
     * @param string $apicode Error code (contract, or http_<status>, url_blocked, invalid_response; service_unavailable
     *                        also when Brandable cannot be reached).
     * @param string $displaymessage Message shown as is to the administrator.
     * @param string $url Page where the administrator can act (recharge, plan, portal), empty if none.
     * @param int $httpstatus HTTP status, 0 when the API could not be reached.
     * @param array $details Other fields of the error.
     */
    public function __construct(
        /** @var string Error code. */
        public readonly string $apicode,
        /** @var string Message shown as is to the administrator. */
        public readonly string $displaymessage,
        /** @var string Page where the administrator can act, empty if none. */
        public readonly string $url = '',
        /** @var int HTTP status, 0 when the API could not be reached. */
        public readonly int $httpstatus = 0,
        /** @var array Other fields of the error (latestVersion, minimumVersion...). */
        public readonly array $details = [],
    ) {
        parent::__construct('error:generator', 'theme_brandable', '', $displaymessage);
    }

    /**
     * Seconds to wait before trying again (Retry-After), 0 when the answer did not say.
     *
     * @return int
     */
    public function retry_after(): int {
        return (int) ($this->details[self::RETRY_AFTER] ?? 0);
    }
}
