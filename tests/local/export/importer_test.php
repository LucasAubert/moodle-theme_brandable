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

use theme_brandable\local\overrides\change;
use theme_brandable\local\overrides\resolver;
use theme_brandable\local\overrides\revision_manager;
use theme_brandable\local\validation\validation_exception;

#[\PHPUnit\Framework\Attributes\CoversClass(importer::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(revision_manager::class)]
/**
 * Tests for the import of an exported theme.
 *
 * @package    theme_brandable
 * @category   test
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \theme_brandable\local\export\importer
 * @covers     \theme_brandable\local\overrides\revision_manager
 */
final class importer_test extends \advanced_testcase {
    /** @var \stdClass Course of the course variant. */
    private \stdClass $course;

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->course = $this->getDataGenerator()->create_course(['shortname' => 'MATH101']);
    }

    /**
     * Publishes a version (templates, one created by Brandable and used as a partial, a course variant, SCSS) and
     * exports it with the test licence.
     *
     * @return string Zip file.
     */
    private function export(): string {
        revision_manager::create([
            change::from_array(['type' => 'template', 'target' => 'theme_brandable/hero_banner',
                'content' => '<section class="hero">{{title}}</section>']),
            change::from_array(['type' => 'template', 'target' => 'core/loading',
                'content' => '<div>{{> theme_brandable/hero_banner}}</div>']),
            change::from_array(['type' => 'template', 'target' => 'core/loading', 'content' => '<p>Course</p>',
                'scope' => ['level' => 'course', 'value' => (string) $this->course->id]]),
            change::from_array(['type' => 'template', 'target' => 'core/loading', 'content' => '<p>Login</p>',
                'scope' => ['level' => 'pagetype', 'value' => 'login-index']]),
            change::from_array(['type' => 'scss', 'target' => 'pre/colours', 'content' => '$primary: #0a0a0a;']),
            change::from_array(['type' => 'scss', 'target' => 'post/course', 'content' => '.navbar { color: red; }',
                'scope' => ['level' => 'course', 'value' => (string) $this->course->id]]),
        ], 'p', 's');
        revision_manager::publish('Barre bleue.');
        return export_manager::export('acme', 'Barre bleue.', '', [], null, true)['path'];
    }

    /**
     * Writes a zip from contents.
     *
     * @param array $files Path in the zip => content.
     * @return string Zip file.
     */
    private function zip(array $files): string {
        $path = make_request_directory() . '/theme.zip';
        get_file_packer('application/zip')->archive_to_pathname(array_map(fn($content) => [$content], $files), $path);
        return $path;
    }

    /**
     * Returns the overrides of a map without what differs between two revisions (ids, order of the records).
     *
     * @param array $map Resolved map.
     * @return array
     */
    private function content(array $map): array {
        return array_map(fn(array $entries) => array_map(fn(array $entry) => [$entry['target'], $entry['scopelevel'] ?? null,
            $entry['scopevalue'] ?? null, $entry['content']], $entries), $map);
    }

    public function test_import_round_trip(): void {
        global $DB;

        $zip = $this->export();
        $exported = resolver::get_map(revision_manager::get_published());
        // A draft in progress: discarded by the import.
        revision_manager::create(
            [change::from_array(['type' => 'scss', 'target' => 'post/draft', 'content' => 'a { b: c; }'])],
            'p2',
            's2'
        );

        $read = importer::read($zip);
        $this->assertSame('acme', $read['theme']);
        $this->assertSame([], $read['warnings']);
        $this->assertCount(6, $read['changes']);
        $versionid = revision_manager::import($read['changes'], 'Importé.');

        $version = revision_manager::get_current_version();
        $this->assertSame($versionid, (int) $version->id);
        $this->assertSame(2, (int) $version->number);
        $this->assertSame('Importé.', $version->note);
        $this->assertSame(1, (int) revision_manager::get_previous_version()->number);
        $imported = resolver::get_map(revision_manager::get_published());
        // Same overrides, theme_acme/* back to theme_brandable/*.
        $this->assertSame($this->content($exported), $this->content($imported));
        $this->assertSame(revision_manager::get_published(), revision_manager::get_draft());
        $this->assertSame([], revision_manager::get_session_path());
        $this->assertFalse($DB->record_exists_select('theme_brandable_revision', $DB->sql_compare_text('prompt') . ' = ?', ['p2']));
        // The original of core/loading is the same: nothing to review.
        $this->assertSame([], array_filter(array_column($imported['template'], 'needsreview')));
    }

    public function test_import_on_another_site(): void {
        $zip = $this->export();
        // The course of the variants is not on the site (other shortname): the course variant and SCSS are left out.
        update_course((object) ['id' => $this->course->id, 'shortname' => 'OTHER']);
        $read = importer::read($zip);
        $this->assertCount(2, $read['warnings']);
        $this->assertCount(4, $read['changes']);
        revision_manager::import($read['changes'], '');
        $map = resolver::get_map(revision_manager::get_published());
        $this->assertSame(['course' => 0, 'pagetype' => 1], [
            'course' => count(array_filter($map['template'], fn($e) => ($e['scopelevel'] ?? '') === 'course')),
            'pagetype' => count(array_filter($map['template'], fn($e) => ($e['scopelevel'] ?? '') === 'pagetype')),
        ]);
    }

    public function test_changed_original_needs_review(): void {
        $dir = make_request_directory();
        get_file_packer('application/zip')->extract_to_pathname($this->export(), $dir);
        $manifest = json_decode(file_get_contents("$dir/acme/brandable/manifest.json"), true);
        $manifest['variants'][0]['sourcehash'] = str_repeat('0', 64);
        $files = [];
        foreach (
            ['brandable/variants/1.mustache', 'brandable/variants/2.mustache', 'templates/core/loading.mustache',
            'templates/hero_banner.mustache', 'brandable/scss/1.scss', 'brandable/scss/2.scss'] as $file
        ) {
            $files["acme/$file"] = file_get_contents("$dir/acme/$file");
        }
        $files['acme/brandable/manifest.json'] = json_encode($manifest);
        $read = importer::read($this->zip($files));
        revision_manager::import($read['changes'], '');
        $map = resolver::get_map(revision_manager::get_published());
        $this->assertSame(1, array_sum(array_column($map['template'], 'needsreview')));
    }

    public function test_invalid_zips_are_refused(): void {
        $manifest = ['format' => exporter::FORMAT, 'theme' => 'acme', 'parent' => 'boost', 'version' => '2026102800',
            'templates' => [], 'variants' => [], 'scss' => []];
        $cases = [
            'two folders' => [['acme/brandable/manifest.json' => json_encode($manifest), 'other/x.txt' => 'x'], 'zip'],
            'no manifest' => [['acme/version.php' => '<?php die();'], 'notexport'],
            'other format' => [['acme/brandable/manifest.json' => json_encode(['format' => 99] + $manifest)], 'notexport'],
            'other theme' => [['acme/brandable/manifest.json' => json_encode(['theme' => 'evil'] + $manifest)], 'manifest'],
            'other parent' => [['acme/brandable/manifest.json' => json_encode(['parent' => 'classic'] + $manifest)], 'parent'],
            'file out of the theme' => [['acme/brandable/manifest.json' => json_encode(['templates' => [
                ['template' => 'core/loading', 'file' => '../../config.php']]] + $manifest)], 'manifest'],
            'PHP file listed' => [['acme/brandable/manifest.json' => json_encode(['templates' => [
                ['template' => 'core/loading', 'file' => 'version.php']]] + $manifest), 'acme/version.php' => '<?php'], 'manifest'],
            'file missing' => [['acme/brandable/manifest.json' => json_encode(['scss' => [
                ['target' => 'post/x', 'file' => 'brandable/scss/1.scss', 'scope' => ['level' => 'site']]]] + $manifest)],
                'manifest'],
            'invalid name' => [['Acme/brandable/manifest.json' => json_encode($manifest)], 'zip'],
        ];
        foreach ($cases as $label => [$files, $error]) {
            try {
                importer::read($this->zip($files));
                $this->fail("$label: accepted");
            } catch (\moodle_exception $e) {
                $this->assertSame("import:error:$error", $e->errorcode, $label);
            }
        }
        $notzip = make_request_directory() . '/theme.zip';
        file_put_contents($notzip, 'not a zip');
        try {
            importer::read($notzip);
            $this->fail('not a zip: accepted');
        } catch (\moodle_exception $e) {
            $this->assertSame('import:error:zip', $e->errorcode);
        }
        // Reported by zip_archive.
        $this->resetDebugging();
    }

    public function test_contents_are_validated_again(): void {
        $manifest = ['format' => exporter::FORMAT, 'theme' => 'acme', 'parent' => 'boost', 'version' => '2026102800',
            'templates' => [['template' => 'core/loading', 'file' => 'templates/core/loading.mustache'],
                ['template' => 'mod_missing/view', 'file' => 'templates/mod_missing/view.mustache']],
            'variants' => [], 'scss' => [['target' => 'post/x', 'file' => 'brandable/scss/1.scss',
                'scope' => ['level' => 'site', 'value' => '', 'by' => 'id']]]];
        $read = importer::read($this->zip([
            'acme/brandable/manifest.json' => json_encode($manifest),
            'acme/templates/core/loading.mustache' => '<div>{{#js}}alert(1);{{/js}}</div>',
            'acme/templates/mod_missing/view.mustache' => '<div></div>',
            'acme/brandable/scss/1.scss' => 'a { color: ',
        ]));
        // The template of a missing plugin is left out; the others are refused by the validator, nothing is published.
        $this->assertCount(1, $read['warnings']);
        $published = revision_manager::get_published();
        try {
            revision_manager::import($read['changes'], '');
            $this->fail('invalid contents imported');
        } catch (validation_exception $e) {
            $this->assertSame(['core/loading', 'post/x'], array_column($e->errors, 'target'));
        }
        $this->assertSame($published, revision_manager::get_published());
        $this->assertNull(revision_manager::get_current_version());
    }
}
