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

namespace theme_brandable\local\validation;

/**
 * Thrown when changes fail validation. The revision is refused and the draft left unchanged.
 *
 * @package    theme_brandable
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class validation_exception extends \moodle_exception {
    /**
     * Constructor.
     *
     * @param array $errors List of ['target' => string, 'message' => string] (the repair contract format).
     */
    public function __construct(
        /** @var array Validation errors. */
        public readonly array $errors,
    ) {
        $messages = array_map(
            fn(array $error) => ($error['target'] !== '' ? $error['target'] . ': ' : '') . $error['message'],
            $errors
        );
        parent::__construct('error:validation', 'theme_brandable', '', implode("\n", $messages));
    }
}
