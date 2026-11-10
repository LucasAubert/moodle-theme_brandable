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
 * Error reported by a generator. The message is meant to be shown as is to the administrator.
 *
 * @package    theme_brandable
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class generator_exception extends \moodle_exception {
    /** @var string Error code (generator contract, e.g. repair_limit_reached, context_expired). */
    public readonly string $generatorcode;

    /** @var string Message shown to the administrator. */
    public readonly string $displaymessage;

    /** @var string Page where the administrator can act (recharge, plan), empty if none. */
    public readonly string $url;

    /** @var int Seconds to wait before trying again (Retry-After of Brandable), 0 if not said. */
    public readonly int $retryafter;

    /**
     * @var bool Brandable itself is unavailable (503, overloaded, not reached: contract 7), not the generation: the
     * request is kept and asked again after the Retry-After.
     */
    public readonly bool $unavailable;

    /**
     * Constructor.
     *
     * @param string $code Error code.
     * @param string $message Message shown to the administrator.
     * @param string $url Page where the administrator can act, empty if none.
     * @param int $retryafter Seconds to wait before trying again, 0 if not said.
     * @param bool $unavailable Whether Brandable itself is unavailable (not the generation).
     */
    public function __construct(string $code, string $message, string $url = '', int $retryafter = 0, bool $unavailable = false) {
        $this->generatorcode = $code;
        $this->displaymessage = $message;
        $this->url = $url;
        $this->retryafter = $retryafter;
        $this->unavailable = $unavailable;
        parent::__construct('error:generator', 'theme_brandable', '', $message);
    }
}
