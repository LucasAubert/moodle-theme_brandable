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

use theme_brandable\local\account\api_client;
use theme_brandable\local\account\api_exception;
use theme_brandable\local\overrides\revision_manager;
use theme_brandable\local\overrides\template_source;
use theme_brandable\local\parent_theme;

/**
 * Export of the published version: version of the theme (GET /exports/latest), licence (POST /exports, one retry on
 * 409 version_conflict), zip, then the export is recorded (numbering and changelog of the theme name).
 *
 * @package    theme_brandable
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class export_manager {
    /** @var int Maximum length of the author sent. */
    private const MAX_AUTHOR = 100;

    /**
     * Exports the published version as a theme.
     *
     * @param string $name Theme name, without theme_.
     * @param string $note Version note.
     * @param string $author Author shown in the Brandable dashboard (optional).
     * @param array $mapping Course and activity scopes on the target site (see exporter).
     * @param api_client|null $client API client (the connection one when null).
     * @param bool $testlicence Whether to write the test licence instead of asking Brandable (tests, development).
     * @return array ['path' => zip file (temporary), 'filename', 'version', 'previousversion', 'warnings'].
     * @throws api_exception From Brandable (403 plan_required, 429, 503...).
     */
    public static function export(
        string $name,
        string $note,
        string $author = '',
        array $mapping = [],
        ?api_client $client = null,
        bool $testlicence = false
    ): array {
        global $DB, $USER;

        $parent = parent_theme::get_name();
        if ($error = theme_name::check($name, $parent)) {
            throw new \moodle_exception($error, 'theme_brandable');
        }
        $version = revision_manager::get_current_version();
        if (!$version) {
            throw new \moodle_exception('export:error:nothingpublished', 'theme_brandable');
        }
        $note = \core_text::substr(trim($note), 0, revision_manager::MAX_NOTE);
        $previous = null;
        if ($testlicence) {
            $themeversion = exporter::next_version($name);
            $licence = licence::test();
        } else {
            $client = $client ?? new api_client();
            $latest = $client->request('GET', '/exports/latest?themeName=' . rawurlencode($name));
            $themeversion = exporter::next_version($name, $latest['minimumVersion'] ?? null);
            $body = [
                'themeName' => $name,
                'themeVersion' => $themeversion,
                'moodleVersion' => template_source::get_moodle_version(),
                'parentTheme' => $parent,
                'note' => $note,
            ];
            if (trim($author) !== '') {
                $body['author'] = \core_text::substr(trim($author), 0, self::MAX_AUTHOR);
            }
            try {
                $response = $client->request('POST', '/exports', $body);
            } catch (api_exception $e) {
                // Exported meanwhile from another site: once more with the version Brandable expects.
                $minimum = (string) ($e->details['minimumVersion'] ?? '');
                if ($e->apicode !== 'version_conflict' || !preg_match('/^\d{10}$/', $minimum)) {
                    throw $e;
                }
                $body['themeVersion'] = $themeversion = exporter::next_version($name, $minimum);
                $response = $client->request('POST', '/exports', $body);
            }
            $licence = licence::from_response($response);
            $previous = isset($response['previousVersion']) ? (string) $response['previousVersion'] : null;
        }

        $path = make_request_directory() . '/theme_' . $name . '.zip';
        $warnings = (new exporter($name, $themeversion, $note, $licence, $mapping))->build($path);
        $DB->insert_record('theme_brandable_export', (object) [
            'themename' => $name,
            'themeversion' => $themeversion,
            'versionnumber' => (int) $version->number,
            'note' => $note,
            'licenceid' => $licence->test ? null : $licence->id,
            'userid' => (int) $USER->id,
            'timecreated' => time(),
        ]);
        return [
            'path' => $path,
            'filename' => 'theme_' . $name . '_' . $themeversion . '.zip',
            'version' => $themeversion,
            'previousversion' => $previous,
            'warnings' => $warnings,
        ];
    }
}
