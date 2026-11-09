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

use theme_brandable\local\export\exporter;
use theme_brandable\local\export\theme_name;
use theme_brandable\local\parent_theme;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Export of the published version: theme name, author, version note, and where the course and activity variants go
 * on the target site.
 *
 * Custom data: 'note' (note of the version), 'targets' (exporter::get_scoped_targets()).
 *
 * @package    theme_brandable
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class export_form extends \moodleform {
    /**
     * Defines the form.
     */
    protected function definition(): void {
        global $USER;

        $form = $this->_form;
        $form->addElement('static', 'intro', '', get_string('export:intro', 'theme_brandable'));

        $form->addElement('text', 'name', get_string('export:name', 'theme_brandable'), ['size' => 30]);
        $form->setType('name', PARAM_RAW_TRIMMED);
        $form->addRule('name', null, 'required', null, 'client');
        $form->addHelpButton('name', 'export:name', 'theme_brandable');

        $form->addElement('text', 'author', get_string('export:author', 'theme_brandable'), ['size' => 30]);
        $form->setType('author', PARAM_TEXT);
        $form->setDefault('author', fullname($USER));
        $form->addHelpButton('author', 'export:author', 'theme_brandable');

        $form->addElement('textarea', 'note', get_string('publish:note', 'theme_brandable'), ['rows' => 5, 'cols' => 60]);
        $form->setType('note', PARAM_TEXT);
        $form->setDefault('note', $this->_customdata['note'] ?? '');

        $targets = $this->_customdata['targets'] ?? [];
        if ($targets) {
            $form->addElement('header', 'scopes', get_string('export:scopes', 'theme_brandable'));
            $form->addElement('static', 'scopesintro', '', get_string('export:scopes_help', 'theme_brandable'));
        }
        foreach ($targets as $i => $target) {
            $keys = $target['level'] === 'course' ? exporter::COURSE_KEYS : exporter::CM_KEYS;
            $options = ['' => get_string('export:leaveout', 'theme_brandable')];
            foreach ($keys as $key) {
                $options[$key] = get_string('export:by' . $key, 'theme_brandable');
            }
            $group = [
                $form->createElement('select', "by[$i]", '', $options),
                $form->createElement('text', "value[$i]", '', ['size' => 20]),
            ];
            $form->addGroup($group, "scope[$i]", $target['label'], ' ', false);
            $form->setType("value[$i]", PARAM_RAW_TRIMMED);
            $form->setDefault("by[$i]", $target['default']['by'] ?? '');
            $form->setDefault("value[$i]", $target['default']['value'] ?? '');
            $form->hideIf("value[$i]", "by[$i]", 'eq', '');
        }

        $this->add_action_buttons(true, get_string('export:submit', 'theme_brandable'));
    }

    /**
     * Checks the theme name and the keys given.
     *
     * @param array $data Data.
     * @param array $files Files.
     * @return array Errors.
     */
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);
        if ($error = theme_name::check((string) $data['name'], parent_theme::get_name())) {
            $errors['name'] = get_string($error, 'theme_brandable');
        }
        foreach ($this->_customdata['targets'] ?? [] as $i => $target) {
            if (!empty($data['by'][$i]) && trim((string) ($data['value'][$i] ?? '')) === '') {
                $errors["scope[$i]"] = get_string('required');
            }
        }
        return $errors;
    }

    /**
     * Returns the mapping of the scopes (exporter), from the submitted data.
     *
     * @param \stdClass $data Submitted data.
     * @return array
     */
    public function get_mapping(\stdClass $data): array {
        $mapping = [];
        foreach ($this->_customdata['targets'] ?? [] as $i => $target) {
            $by = (string) ($data->by[$i] ?? '');
            $mapping[$target['key']] = $by === '' ? null : ['by' => $by, 'value' => trim((string) ($data->value[$i] ?? ''))];
        }
        return $mapping;
    }
}
