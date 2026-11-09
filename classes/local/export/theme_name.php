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

namespace theme_brandable\local\export;

use core_component;

/**
 * Name of an exported theme (frankenstyle theme_<name>, the name without theme_ for Brandable).
 *
 * @package    theme_brandable
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class theme_name {
    /** @var string Pattern of a name (Brandable rule, POST /exports). */
    public const PATTERN = '/^[a-z](?:[a-z0-9_]*[a-z0-9])?$/';

    /** @var int Maximum length (Brandable rule). */
    public const MAX_LENGTH = 50;

    /** @var string Name of the runtime theme in the static files, replaced by the name at the export. */
    public const RUNTIME_TOKEN = 'brandablert';

    /**
     * Checks a name: valid, not Brandable nor its parent, not a theme installed on this site unless it was exported
     * from Brandable under this name (new version).
     *
     * @param string $name Name without theme_.
     * @param string $parent Parent theme.
     * @return string|null Error string identifier (theme_brandable), null when valid.
     */
    public static function check(string $name, string $parent): ?string {
        global $DB;

        if (
            !preg_match(self::PATTERN, $name) || strlen($name) > self::MAX_LENGTH || strpos($name, '__') !== false
                || !core_component::is_valid_plugin_name('theme', $name)
        ) {
            return 'export:error:name';
        }
        if ($name === 'brandable' || $name === $parent || $name === self::RUNTIME_TOKEN) {
            return 'export:error:reserved';
        }
        if (
            core_component::get_plugin_directory('theme', $name) !== null
                && !$DB->record_exists('theme_brandable_export', ['themename' => $name])
        ) {
            return 'export:error:installed';
        }
        return null;
    }
}
