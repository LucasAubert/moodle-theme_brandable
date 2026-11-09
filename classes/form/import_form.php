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

use theme_brandable\local\export\importer;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Import of an exported theme, first step: the zip.
 *
 * @package    theme_brandable
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class import_form extends \moodleform {
    /**
     * Defines the form.
     */
    protected function definition(): void {
        $form = $this->_form;
        $form->addElement('static', 'intro', '', get_string('import:intro', 'theme_brandable'));
        $form->addElement('filepicker', 'zip', get_string('import:zip', 'theme_brandable'), null, [
            'accepted_types' => ['.zip'],
            'maxbytes' => importer::MAX_ZIP,
        ]);
        $form->addRule('zip', null, 'required', null, 'client');
        $this->add_action_buttons(true, get_string('import:read', 'theme_brandable'));
    }
}
