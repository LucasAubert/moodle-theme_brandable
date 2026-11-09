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

namespace theme_brandable\local\js;

use theme_brandable\local\overrides\change;
use theme_brandable\local\overrides\resolver;
use theme_brandable\local\overrides\revision_manager;

/**
 * The generated JavaScript of a version or of the draft, as a zip for a review out of Moodle (spec B14): one folder per
 * module (target and scope) with source.js, compiled.js, report.json and signature.txt, as stored (never changed).
 *
 * @package    theme_brandable
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class jsdownload {
    /**
     * Returns the revision to download and its label.
     *
     * @param int $number Number of a published version (0: the published one when not the draft).
     * @param bool $draft Whether the draft is asked.
     * @return array [revision id, label used in the name of the file]
     * @throws \moodle_exception When the version does not exist.
     */
    public static function find_revision(int $number, bool $draft): array {
        global $DB;
        if ($draft) {
            return [revision_manager::get_draft(), 'draft'];
        }
        $version = $number > 0 ? $DB->get_record('theme_brandable_version', ['number' => $number])
            : revision_manager::get_current_version();
        if (!$version) {
            throw new \moodle_exception('error:jsnoversion', 'theme_brandable');
        }
        return [(int) $version->revisionid, 'v' . (int) $version->number];
    }

    /**
     * Returns the files of the zip.
     *
     * @param int $revisionid Revision.
     * @param string $label Label (folder of the zip).
     * @return array Path in the zip => [content].
     */
    public static function files(int $revisionid, string $label): array {
        $root = 'brandable-js-' . $label . '/';
        $files = [];
        $index = [];
        foreach (resolver::get_map($revisionid)[change::TYPE_JS] ?? [] as $entry) {
            $scope = resolver::entry_scope($entry);
            $folder = substr((string) $entry['target'], strlen('js/'))
                . ($scope->is_site() ? '' : '--' . trim((string) preg_replace('/[^a-z0-9]+/i', '-', $scope->key()), '-'));
            $report = json_decode((string) ($entry['report'] ?? ''), true);
            $files[$root . $folder . '/source.js'] = [(string) $entry['content']];
            $files[$root . $folder . '/compiled.js'] = [(string) ($entry['compiled'] ?? '')];
            $json = json_encode(
                is_array($report) ? $report : new \stdClass(),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            );
            $files[$root . $folder . '/report.json'] = [$json . "\n"];
            $files[$root . $folder . '/signature.txt'] = [(string) ($entry['signature'] ?? '') . "\n"];
            $index[] = '- ' . $folder . ': ' . $entry['target'] . ' (' . $scope->label() . '), sha256 '
                . review::hash((string) $entry['content']);
        }
        $files[$root . 'README.txt'] = [get_string('jsdownload:readme', 'theme_brandable', (object) [
            // Plain text: not escaped as HTML.
            'site' => format_string(get_site()->fullname, true, ['context' => \context_system::instance(), 'escape' => false]),
            'label' => $label,
            'modules' => $index ? implode("\n", $index) : get_string('jsdownload:none', 'theme_brandable'),
        ]) . "\n"];
        return $files;
    }

    /**
     * Writes the zip.
     *
     * @param int $revisionid Revision.
     * @param string $label Label.
     * @param string $path File written.
     * @throws \moodle_exception When the zip cannot be written.
     */
    public static function build(int $revisionid, string $label, string $path): void {
        if (!get_file_packer('application/zip')->archive_to_pathname(self::files($revisionid, $label), $path)) {
            throw new \moodle_exception('export:error:zip', 'theme_brandable');
        }
    }
}
