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

use theme_brandable\local\js\modules;
use theme_brandable\local\js\review;
use theme_brandable\local\overrides\change;
use theme_brandable\local\overrides\resolver;
use theme_brandable\local\overrides\revision_manager;
use theme_brandablert\brandable\js as runtime_js;
use theme_brandablert\brandable\manifest as runtime_manifest;

/**
 * Tests for the generated JavaScript in the exported themes (spec B15): files of the modules as delivered, the library b,
 * the manifest; the runtime of the theme (choice of the modules, settings); the import, as a draft whose modules are
 * reviewed again on the site that imports.
 *
 * @package    theme_brandable
 * @category   test
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \theme_brandable\local\export\exporter
 * @covers     \theme_brandable\local\export\importer
 * @covers     \theme_brandable\local\overrides\revision_manager
 * @covers     \theme_brandable\local\js\review
 */
#[\PHPUnit\Framework\Attributes\CoversClass(exporter::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(importer::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(revision_manager::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(review::class)]
final class js_export_test extends \advanced_testcase {
    /** @var string Source of the module of the test. */
    private const SOURCE = "export default function (b) {\n  b.once('welcome');\n}\n";

    #[\Override]
    public static function setUpBeforeClass(): void {
        parent::setUpBeforeClass();
        $dir = exporter::runtime_dir() . '/classes/brandable';
        foreach (['manifest', 'page_key', 'scope', 'assets', 'js'] as $class) {
            require_once("$dir/$class.php");
        }
    }

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
    }

    #[\Override]
    protected function tearDown(): void {
        runtime_manifest::reset();
        parent::tearDown();
    }

    /**
     * A JavaScript change.
     *
     * @param string $slug Slug of the target.
     * @param string $source Source.
     * @param array|null $scope Scope.
     * @return change
     */
    private function module(string $slug, string $source = self::SOURCE, ?array $scope = null): change {
        return change::from_array(['type' => 'js', 'target' => "js/$slug", 'scope' => $scope, 'content' => $source,
            'compiled' => 'function(b){"use strict";b.once("' . $slug . '")}',
            'report' => ['analyzer' => '1', 'apis' => ['b.once'], 'findings' => []],
            'signature' => 'v1.testSignatureNotFromBrandable00']);
    }

    /**
     * Publishes a version with modules (reviewed) and exports it with the test licence.
     *
     * @return string Zip file.
     */
    private function export(): string {
        revision_manager::create([
            $this->module('welcome'),
            $this->module('banner', "export default function (b) {}\n", ['level' => 'pagetype', 'value' => 'my-index']),
            change::from_array(['type' => 'scss', 'target' => 'post/x', 'content' => '.x { color: red; }']),
        ], 'p', 's');
        foreach (review::get_changed() as $module) {
            review::record($module['key'], $module['hash']);
        }
        revision_manager::publish('Bienvenue.');
        set_config(modules::ENABLED_CONFIG, 1, 'theme_brandable');
        return export_manager::export('acme', 'Bienvenue.', '', [], null, true)['path'];
    }

    /**
     * Reads the files of a zip.
     *
     * @param string $zip Zip file.
     * @return array Path => content.
     */
    private function files(string $zip): array {
        $dir = make_request_directory();
        get_file_packer('application/zip')->extract_to_pathname($zip, $dir);
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $files[substr($file->getPathname(), strlen($dir) + 1)] = file_get_contents($file->getPathname());
        }
        return $files;
    }

    public function test_export_of_the_modules(): void {
        $files = $this->files($this->export());
        $manifest = json_decode($files['acme/brandable/manifest.json'], true);
        $this->assertTrue($manifest['js']['enabled']);
        $this->assertSame(['m1', 'm2'], array_column($manifest['js']['modules'], 'key'));
        $welcome = array_column($manifest['js']['modules'], null, 'target')['js/welcome'];
        $this->assertSame(hash('sha256', self::SOURCE), $welcome['hash']);
        $this->assertSame('v1.testSignatureNotFromBrandable00', $welcome['signature']);
        $this->assertFalse($welcome['errors']);
        $banner = array_column($manifest['js']['modules'], null, 'target')['js/banner'];
        $this->assertSame(['level' => 'pagetype', 'value' => 'my-index', 'by' => 'id'], $banner['scope']);

        // As delivered: the source with its markers, the compiled function, the analysis and the signature.
        $dir = 'acme/' . $welcome['dir'] . '/';
        $this->assertSame(self::SOURCE, $files[$dir . 'source.js']);
        $this->assertSame('function(b){"use strict";b.once("welcome")}', $files[$dir . 'compiled.js']);
        $this->assertSame("v1.testSignatureNotFromBrandable00\n", $files[$dir . 'signature.txt']);
        $this->assertSame(['b.once'], json_decode($files[$dir . 'report.json'], true)['apis']);

        // The library b, renamed for the theme; the PHP of the theme stays the static runtime.
        $this->assertStringStartsWith('define("theme_acme/runtime"', $files['acme/amd/build/runtime.min.js']);
        $this->assertStringNotContainsString('theme_brandable', $files['acme/amd/build/runtime.min.js']
            . $files['acme/amd/src/runtime.js'] . $files['acme/amd/build/runtime.min.js.map']);
        $this->assertStringContainsString('theme_acme\\brandable\\js::page_init', $files['acme/lib.php']);
        $this->assertArrayHasKey('acme/js.php', $files);
        $this->assertStringContainsString('?brandable_nojs=1', $files['acme/README.md']);

        // Without modules: no library, nothing in the manifest.
        revision_manager::create([change::from_array(['type' => 'js', 'target' => 'js/welcome', 'deleted' => true]),
            change::from_array(['type' => 'js', 'target' => 'js/banner', 'deleted' => true,
                'scope' => ['level' => 'pagetype', 'value' => 'my-index']])], 'p', 's');
        revision_manager::publish('Sans JS.');
        $files = $this->files(export_manager::export('acme', 'Sans JS.', '', [], null, true)['path']);
        $this->assertSame(['enabled' => false, 'modules' => []], json_decode($files['acme/brandable/manifest.json'], true)['js']);
        $this->assertArrayNotHasKey('acme/amd/build/runtime.min.js', $files);
        $this->assertStringNotContainsString('?brandable_nojs=1', $files['acme/README.md']);
    }

    public function test_import_as_a_draft_reviewed_again(): void {
        global $DB;
        $zip = $this->export();
        $published = revision_manager::get_published();
        // The site that imports has its JavaScript off: it stays off.
        set_config(modules::ENABLED_CONFIG, 0, 'theme_brandable');

        $read = importer::read($zip);
        $this->assertSame([], $read['warnings']);
        $modules = array_values(array_filter($read['changes'], fn($change) => $change->type === change::TYPE_JS));
        $this->assertCount(2, $modules);
        $this->assertSame('v1.testSignatureNotFromBrandable00', $modules[0]->signature);
        $this->assertStringStartsWith('function(b)', (string) $modules[0]->compiled);

        // Published here meanwhile: without the modules, with other SCSS. The import is a draft of a new session:
        // nothing published; the overrides not in the theme removed.
        revision_manager::create([change::from_array(['type' => 'scss', 'target' => 'post/extra',
            'content' => '.y { color: blue; }']), change::from_array(['type' => 'js', 'target' => 'js/welcome',
            'deleted' => true]), change::from_array(['type' => 'js', 'target' => 'js/banner', 'deleted' => true,
            'scope' => ['level' => 'pagetype', 'value' => 'my-index']])], 'p', 's');
        revision_manager::publish('Extra.');
        $published = revision_manager::get_published();
        $draft = revision_manager::import_as_draft(importer::apply_assets($read), 'Importé.');
        review::mark_imported(array_map(fn($change) => review::hash((string) $change->content), $modules));
        $this->assertSame($published, revision_manager::get_published());
        $this->assertSame($draft, revision_manager::get_draft());
        $map = resolver::get_map($draft);
        $this->assertArrayNotHasKey('post/extra', $map[change::TYPE_SCSS]);
        $this->assertArrayHasKey('post/x', $map[change::TYPE_SCSS]);
        $this->assertSame('v1.testSignatureNotFromBrandable00', $map[change::TYPE_JS]['js/welcome']['signature']);
        $this->assertFalse(modules::is_enabled());

        // Reviewed on this site before (the same code was published here): reviewed again all the same.
        $this->assertTrue($DB->record_exists('theme_brandable_jsreview', ['contenthash' => hash('sha256', self::SOURCE)]));
        $pending = array_filter(review::get_changed(), fn($module) => !$module['reviewed']);
        $this->assertEqualsCanonicalizing(['js/welcome', 'js/banner'], array_column($pending, 'target'));
        try {
            revision_manager::publish('Importé.');
            $this->fail('Not reviewed again');
        } catch (\moodle_exception $e) {
            $this->assertSame('error:jsnotreviewed', $e->errorcode);
        }
        $this->waitForSecond();
        foreach ($pending as $module) {
            review::record($module['key'], $module['hash']);
        }
        revision_manager::publish('Importé.');
        $this->assertNotSame($published, revision_manager::get_published());
        $this->assertSame([], review::get_changed());
    }

    public function test_a_changed_source_is_left_out(): void {
        $zip = $this->export();
        $files = $this->files($zip);
        $manifest = json_decode($files['acme/brandable/manifest.json'], true);
        $dir = 'acme/' . array_column($manifest['js']['modules'], 'dir', 'target')['js/welcome'];
        $files[$dir . '/source.js'] = "export default function (b) { /* changed */ }\n";
        $path = make_request_directory() . '/theme.zip';
        get_file_packer('application/zip')->archive_to_pathname(array_map(fn($content) => [$content], $files), $path);

        $read = importer::read($path);
        $this->assertSame([get_string('import:jschanged', 'theme_brandable', 'js/welcome')], $read['warnings']);
        $targets = array_map(fn($change) => $change->target, array_filter($read['changes'], fn($c) => $c->type === 'js'));
        $this->assertSame(['js/banner'], array_values($targets));
    }

    public function test_runtime_of_the_theme(): void {
        global $PAGE;
        $modules = [
            ['key' => 'm1', 'target' => 'js/welcome', 'scope' => ['level' => 'site'], 'dir' => 'brandable/js/1-welcome'],
            ['key' => 'm2', 'target' => 'js/welcome', 'scope' => ['level' => 'pagetype', 'value' => 'my-index'],
                'dir' => 'brandable/js/2-welcome'],
            ['key' => 'm3', 'target' => 'js/alpha', 'scope' => ['level' => 'site'], 'dir' => 'brandable/js/3-alpha'],
            ['key' => 'm4', 'target' => 'js/broken', 'scope' => ['level' => 'site'], 'dir' => 'brandable/js/4-broken',
                'errors' => true],
        ];
        runtime_manifest::reset(['parent' => 'boost', 'js' => ['enabled' => true, 'modules' => $modules],
            'assets' => [['slug' => 'logo', 'file' => 'brandable/assets/logo.png', 'hash' => 'abcdef012345']]]);

        // On at the export, until the setting of the theme says otherwise; the kill switch stops everything.
        $this->assertTrue(runtime_js::is_enabled());
        set_config(runtime_js::SUSPENDED_CONFIG, 1, 'theme_brandablert');
        $this->assertFalse(runtime_js::is_enabled());
        unset_config(runtime_js::SUSPENDED_CONFIG, 'theme_brandablert');
        set_config(runtime_js::ENABLED_CONFIG, 0, 'theme_brandablert');
        $this->assertFalse(runtime_js::is_enabled());
        set_config(runtime_js::ENABLED_CONFIG, 1, 'theme_brandablert');

        // The most precise variant of each target, by target; a module with errors never runs.
        $page = new \moodle_page();
        $page->set_context(\context_system::instance());
        $page->set_pagetype('my-index');
        $page->set_url('/my/');
        $this->assertSame(['m3', 'm2'], array_column(runtime_js::select($page, null), 'key'));
        $page = new \moodle_page();
        $page->set_context(\context_system::instance());
        $page->set_pagetype('course-view-topics');
        $page->set_url('/course/view.php');
        $this->assertSame(['m3', 'm1'], array_column(runtime_js::select($page, null), 'key'));
        $this->assertTrue(runtime_js::run_on($page));

        // The parameter brandable_nojs=1 turns them off on the page, for the administrators only.
        $_GET[runtime_js::NOJS_PARAM] = '1';
        try {
            $this->assertFalse(runtime_js::run_on($page));
            $this->setUser($this->getDataGenerator()->create_user());
            $this->assertTrue(runtime_js::run_on($page));
            $this->setAdminUser();
        } finally {
            unset($_GET[runtime_js::NOJS_PARAM]);
        }

        // Never on the administration pages.
        $admin = new \moodle_page();
        $admin->set_context(\context_system::instance());
        $admin->set_pagelayout('admin');
        $admin->set_url('/admin/search.php');
        $this->assertFalse(runtime_js::run_on($admin));

        // Markers of the library: URLs of the theme, encoded within a string.
        $this->assertSame(
            'f("' . (new \moodle_url('/theme/brandablert/asset.php/abcdef012345/logo.png'))->out(false) . '","")',
            runtime_js::resolve_markers('f("[[brandable-asset:logo]]","[[brandable-asset:missing]]")')
        );
        // The same opaque user key on every page, never the id.
        $this->assertSame(runtime_js::user_key(), runtime_js::user_key());
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', runtime_js::user_key());
        $this->assertNotNull($PAGE);
    }
}
