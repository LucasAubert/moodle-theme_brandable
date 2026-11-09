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
 * A generator turns a prompt and its context into changes.
 *
 * Generation is asynchronous (the remote backend takes up to a couple of minutes): the plugin starts it,
 * the editor polls it through the plugin, and the plugin may ask for repairs when its own validation
 * fails. Synchronous generators extend {@see sync_generator} and only implement generate().
 *
 * @package    theme_brandable
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface generator_interface {
    /**
     * Starts a generation.
     *
     * @param string $prompt Prompt.
     * @param generation_context $context Context.
     * @return string Generation id.
     * @throws generator_exception
     */
    public function start(string $prompt, generation_context $context): string;

    /**
     * Returns the current status of a generation.
     *
     * @param string $id Generation id.
     * @return generation_status
     * @throws generator_exception
     */
    public function poll(string $id): generation_status;

    /**
     * Asks for a new attempt after the plugin's validation failed.
     *
     * @param string $id Generation id.
     * @param array $errors List of ['target' => string, 'message' => string].
     * @throws generator_exception
     */
    public function repair(string $id, array $errors): void;
}
