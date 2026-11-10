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

namespace theme_brandable\output;

#[\PHPUnit\Framework\Attributes\CoversNothing]
/**
 * Tests for the documentation of the templates: the example context of each one is valid JSON, ending where the
 * comment ends (a "}}" inside the example would close the comment early and show the rest in the page).
 *
 * @package    theme_brandable
 * @category   test
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @coversNothing
 */
final class template_docs_test extends \basic_testcase {
    public function test_example_contexts_are_json(): void {
        $files = glob(__DIR__ . '/../../templates/*.mustache');
        $this->assertNotEmpty($files);
        foreach ($files as $file) {
            $source = (string) file_get_contents($file);
            $start = strpos($source, '@template');
            $this->assertNotFalse($start, basename($file) . ': @template');
            $example = strpos($source, 'Example context (json):', $start);
            if ($example === false) {
                continue;
            }
            $example += strlen('Example context (json):');
            $end = strpos($source, '}}', $example);
            $json = trim(substr($source, $example, $end - $example));
            $this->assertIsArray(json_decode($json, true), basename($file) . ': ' . json_last_error_msg());
        }
    }
}
