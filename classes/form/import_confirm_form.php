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

namespace theme_brandable\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Import of an exported theme, second step: the version note, then the confirmation (the zip stays in the draft
 * area of the first step).
 *
 * Custom data: 'note' (default note), 'discards' (whether the draft in progress is discarded).
 *
 * @package    theme_brandable
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class import_confirm_form extends \moodleform {
    /**
     * Defines the form.
     */
    protected function definition(): void {
        $form = $this->_form;
        $form->addElement('hidden', 'zip');
        $form->setType('zip', PARAM_INT);
        $form->addElement('hidden', 'confirm', 1);
        $form->setType('confirm', PARAM_BOOL);

        $form->addElement('textarea', 'note', get_string('publish:note', 'theme_brandable'), ['rows' => 4, 'cols' => 60]);
        $form->setType('note', PARAM_TEXT);
        $form->setDefault('note', $this->_customdata['note'] ?? '');
        if (!empty($this->_customdata['discards'])) {
            $form->addElement('static', 'discards', '', get_string('import:discards', 'theme_brandable'));
        }
        $this->add_action_buttons(true, get_string('import:submit', 'theme_brandable'));
    }
}
