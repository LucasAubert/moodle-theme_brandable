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

use theme_brandable\local\account\connection;
use theme_brandable\local\overrides\change;
use theme_brandable\local\overrides\revision_manager;
use theme_brandable\local\overrides\template_source;

#[\PHPUnit\Framework\Attributes\CoversClass(exporter::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(export_manager::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(licence::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(theme_name::class)]
/**
 * Tests for the export of the published version as a standalone theme.
 *
 * @package    theme_brandable
 * @category   test
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \theme_brandable\local\export\exporter
 * @covers     \theme_brandable\local\export\export_manager
 * @covers     \theme_brandable\local\export\licence
 * @covers     \theme_brandable\local\export\theme_name
 */
final class exporter_test extends \advanced_testcase {
    use \theme_brandable\tests\requires_classic;

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
     * Publishes a version: templates (one created by Brandable, used as a partial), a course variant, SCSS chunks.
     */
    private function publish(): void {
        revision_manager::create([
            change::from_array(['type' => 'template', 'target' => 'theme_brandable/hero_banner',
                'content' => '<section class="hero">{{title}}</section>']),
            change::from_array(['type' => 'template', 'target' => 'core/loading',
                'content' => '<div>{{> theme_brandable/hero_banner}}</div>']),
            change::from_array(['type' => 'template', 'target' => 'core/loading', 'content' => '<p>Course</p>',
                'scope' => ['level' => 'course', 'value' => (string) $this->course->id]]),
            change::from_array(['type' => 'scss', 'target' => 'pre/colours', 'content' => '$primary: #0a0a0a;']),
            change::from_array(['type' => 'scss', 'target' => 'post/course', 'content' => '.navbar { color: red; }',
                'scope' => ['level' => 'course', 'value' => (string) $this->course->id]]),
        ], 'p', 's');
        revision_manager::publish("Barre bleue.\nCartes arrondies.");
    }

    /**
     * Extracts a zip and returns its folder.
     *
     * @param string $zip Zip file.
     * @return string
     */
    private function extract(string $zip): string {
        $dir = make_request_directory();
        get_file_packer('application/zip')->extract_to_pathname($zip, $dir);
        return $dir;
    }

    public function test_export_with_the_test_licence(): void {
        global $DB;

        $this->publish();
        $result = export_manager::export('acme', "Barre bleue.\nCartes arrondies.", 'Admin User', [], null, true);
        $this->assertSame(date('Ymd') . '00', $result['version']);
        $this->assertSame('theme_acme_' . $result['version'] . '.zip', $result['filename']);
        $this->assertSame([], $result['warnings']);
        $dir = $this->extract($result['path']) . '/acme';

        foreach (
            ['version.php', 'config.php', 'lib.php', 'licence.php', 'lang/en/theme_acme.php', 'db/install.php',
                'classes/brandable/scss.php', 'README.md', 'CHANGELOG.md', 'COPYING.txt', 'brandable/manifest.json'] as $file
        ) {
            $this->assertFileExists("$dir/$file");
        }
        // Templates of the whole site are standard files; the one created by Brandable belongs to the theme.
        $this->assertSame('<div>{{> theme_acme/hero_banner}}</div>', file_get_contents("$dir/templates/core/loading.mustache"));
        $this->assertFileExists("$dir/templates/hero_banner.mustache");
        $manifest = json_decode(file_get_contents("$dir/brandable/manifest.json"), true);
        $this->assertSame('boost', $manifest['parent']);
        $this->assertSame([['template' => 'core/loading', 'file' => 'brandable/variants/1.mustache',
            'scope' => ['level' => 'course', 'value' => 'MATH101', 'by' => 'shortname'],
            'sourcehash' => template_source::get_original_hash('core/loading')]], $manifest['variants']);
        $this->assertSame(['pre', 'post'], array_column($manifest['scss'], 'section'));
        $this->assertSame('$primary: #0a0a0a;', file_get_contents("$dir/brandable/scss/1.scss"));

        // Valid PHP, the runtime theme name replaced everywhere, data written by var_export().
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $content = file_get_contents($file->getPathname());
            $this->assertStringNotContainsString(theme_name::RUNTIME_TOKEN, $content, $file->getPathname());
            if (str_ends_with($file->getFilename(), '.php')) {
                token_get_all($content, TOKEN_PARSE);
                $this->assertStringStartsWith("<?php\n// This file is part of Moodle", $content, $file->getPathname());
                // The PHPDoc names the exported theme, not Brandable where the runtime lives.
                $this->assertDoesNotMatchRegularExpression('/@package\s+theme_brandable$/m', $content, $file->getPathname());
            }
        }
        $this->assertStringContainsString('@package    theme_acme' . "\n", file_get_contents("$dir/lib.php"));
        $plugin = new \stdClass();
        include("$dir/version.php");
        $this->assertSame('theme_acme', $plugin->component);
        $this->assertSame((int) $result['version'], $plugin->version);
        $this->assertSame(['theme_boost' => ANY_VERSION], $plugin->dependencies);
        $this->assertStringEndsWith(' - Barre bleue.', $plugin->release);
        $licence = include("$dir/licence.php");
        $this->assertSame(licence::TEST_TOKEN, $licence['licenceToken']);
        $this->assertSame('acme', $licence['themeName']);
        $this->assertTrue($licence['test']);

        $this->assertStringContainsString('theme_acme', file_get_contents("$dir/README.md"));
        // Boost is the parent: the section on the colour modes, and the brandable-dark mixin in the runtime.
        $this->assertStringContainsString(
            strtok(get_string('export:readmecolourmodes', 'theme_brandable'), "\n"),
            file_get_contents("$dir/README.md")
        );
        $this->assertStringContainsString('@mixin brandable-dark', file_get_contents("$dir/classes/brandable/scss.php"));
        $this->assertStringContainsString("Barre bleue.\nCartes arrondies.", file_get_contents("$dir/CHANGELOG.md"));
        $this->assertSame(1, $DB->count_records('theme_brandable_export', ['themename' => 'acme']));

        // Exported again the same day: the next version, the changelog keeps the previous one.
        $second = export_manager::export('acme', 'Police plus grande.', '', [], null, true);
        $this->assertSame(date('Ymd') . '01', $second['version']);
        $changelog = file_get_contents($this->extract($second['path']) . '/acme/CHANGELOG.md');
        $this->assertMatchesRegularExpression('/Police plus grande\.[\s\S]*Barre bleue\./', $changelog);
    }

    public function test_scopes_left_out_or_mapped(): void {
        $this->publish();
        $key = 'course:' . $this->course->id;
        $this->assertSame([['key' => $key, 'level' => 'course', 'id' => (int) $this->course->id,
            'label' => \theme_brandable\local\overrides\scope::from_columns('course', (string) $this->course->id)->label(),
            'default' => ['by' => 'shortname', 'value' => 'MATH101']]], exporter::get_scoped_targets());

        $result = export_manager::export('acme', '', '', [$key => null], null, true);
        $this->assertCount(2, $result['warnings']);
        $manifest = json_decode(file_get_contents($this->extract($result['path']) . '/acme/brandable/manifest.json'), true);
        $this->assertSame([], $manifest['variants']);
        $this->assertCount(1, $manifest['scss']);

        $result = export_manager::export('acme', '', '', [$key => ['by' => 'idnumber', 'value' => 'M-101']], null, true);
        $manifest = json_decode(file_get_contents($this->extract($result['path']) . '/acme/brandable/manifest.json'), true);
        $this->assertSame(['level' => 'course', 'value' => 'M-101', 'by' => 'idnumber'], $manifest['variants'][0]['scope']);
    }

    public function test_theme_names(): void {
        $this->assertNull(theme_name::check('acme', 'boost'));
        $this->assertNull(theme_name::check('acme_2026', 'boost'));
        foreach (['Acme', '1acme', 'acme_', 'ac__me', 'acme-x', str_repeat('a', 51)] as $name) {
            $this->assertSame('export:error:name', theme_name::check($name, 'boost'), $name);
        }
        $this->assertSame('export:error:reserved', theme_name::check('brandable', 'boost'));
        $this->assertSame('export:error:reserved', theme_name::check('boost', 'boost'));
        if (self::has_classic()) {
            $this->assertSame('export:error:installed', theme_name::check('classic', 'boost'));
        }

        $this->expectException(\moodle_exception::class);
        export_manager::export('acme', '', '', [], null, true);
    }

    public function test_export_registered_with_brandable(): void {
        global $DB;

        $this->publish();
        connection::store_key('site-key-1');
        $licence = ['licenceId' => '3f9a2c4e-1b2d-4e5f-8a9b-0c1d2e3f4a5b', 'licenceKey' => 'blk_' . str_repeat('k', 43),
            'licenceToken' => 'aGVhZA.Ym9keQ.c2ln', 'publicKeys' => licence::TEST_KEYS,
            'heartbeatUrl' => 'https://app.brandablelms.com/api/v1/licences/heartbeat',
            'keysUrl' => 'https://app.brandablelms.com/api/v1/licences/keys',
            'latestVersion' => '2099010102', 'previousVersion' => '2099010101'];
        // Answers in reverse order (LIFO): latest version, 409 (exported meanwhile from another site), then 201.
        \curl::mock_response(json_encode($licence));
        \curl::mock_response(json_encode(['error' => ['code' => 'version_conflict', 'message' => 'Conflict',
            'latestVersion' => '2099010101', 'minimumVersion' => '2099010102']]));
        \curl::mock_response(json_encode(['themeName' => 'acme', 'latestVersion' => '2099010100',
            'minimumVersion' => '2099010101', 'exportedAt' => '2099-01-01T00:00:00Z']));

        $result = export_manager::export('acme', 'Note', 'Admin User');
        $this->assertSame('2099010102', $result['version']);
        $this->assertSame('2099010101', $result['previousversion']);
        $data = include($this->extract($result['path']) . '/acme/licence.php');
        $this->assertSame('blk_' . str_repeat('k', 43), $data['licenceKey']);
        $this->assertFalse($data['test']);
        // The key is never kept by the plugin.
        $record = $DB->get_record('theme_brandable_export', ['themename' => 'acme']);
        $this->assertSame('3f9a2c4e-1b2d-4e5f-8a9b-0c1d2e3f4a5b', $record->licenceid);
        $this->assertStringNotContainsString('blk_', json_encode($DB->get_records(
            'config_plugins',
            ['plugin' => 'theme_brandable']
        )));
    }

    public function test_free_plan_has_no_export(): void {
        $this->publish();
        connection::store_key('site-key-1');
        \curl::mock_response(json_encode(['error' => ['code' => 'plan_required', 'message' => 'Paid plans only.',
            'url' => 'https://app.brandablelms.com/billing/plan']]));
        \curl::mock_response(json_encode(['themeName' => 'acme', 'latestVersion' => null, 'minimumVersion' => null,
            'exportedAt' => null]));
        try {
            export_manager::export('acme', 'Note');
            $this->fail('plan_required expected');
        } catch (\theme_brandable\local\account\api_exception $e) {
            $this->assertSame('plan_required', $e->apicode);
        }
        $this->assertFalse($GLOBALS['DB']->record_exists('theme_brandable_export', []));
    }
}
