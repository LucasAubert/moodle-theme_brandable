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

namespace theme_brandable\local\assets;

use theme_brandable\local\account\api_client;
use theme_brandable\local\account\api_exception;
use theme_brandable\local\generator\generation_context;
use theme_brandable\local\generator\generation_manager;
use theme_brandable\local\generator\generation_result;
use theme_brandable\local\generator\sync_generator;
use theme_brandable\local\overrides\change;
use theme_brandable\local\overrides\resolver;
use theme_brandable\local\overrides\revision_manager;

/**
 * Tests for the library in the generation requests and the fonts of the catalogue (spec A11, A12; contract 11.2,
 * 11.6).
 *
 * @package    theme_brandable
 * @category   test
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \theme_brandable\local\assets\request
 * @covers     \theme_brandable\local\assets\font_downloader
 * @covers     \theme_brandable\local\generator\generation_manager
 */
#[\PHPUnit\Framework\Attributes\CoversClass(request::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(font_downloader::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(generation_manager::class)]
final class fonts_test extends \advanced_testcase {
    /** @var string Public URL of the Brandable API. */
    private const API = 'https://app.brandablelms.com/api/v1';

    #[\Override]
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $CFG->theme_brandable_devmode = true;
    }

    /**
     * Adds a PNG image.
     *
     * @param string $slug Slug.
     * @return \stdClass
     */
    private function image(string $slug): \stdClass {
        $path = make_request_directory() . '/image';
        imagepng(imagecreatetruecolor(40, 20), $path);
        return library::add(file_checker::check_image($path), ['slug' => $slug]);
    }

    /**
     * Adds a font file.
     *
     * @param string $slug Slug.
     * @param string $family Family.
     * @param int $weight Weight.
     * @param array $meta Other description (origin, licence, subset).
     * @return \stdClass
     */
    private function font(string $slug, string $family, int $weight = 400, array $meta = []): \stdClass {
        $path = make_request_directory() . '/font';
        file_put_contents($path, 'wOF2' . random_string(40));
        return library::add(file_checker::check_font($path), ['slug' => $slug, 'family' => $family, 'weight' => $weight,
            'style' => 'normal'] + $meta);
    }

    /**
     * A client of the API that serves fixed files (or fails), and records what was asked.
     *
     * @param array $files Content by path; an api_exception to throw.
     * @return api_client
     */
    private function client(array $files): api_client {
        return new class ($files) extends api_client {
            /** @var string[] Paths asked. */
            public array $asked = [];

            /**
             * Constructor.
             *
             * @param array $files Files by path.
             */
            public function __construct(
                /** @var array Files by path. */
                private array $files
            ) {
                parent::__construct('https://brandable.test/api/v1', 'key');
            }

            #[\Override]
            public function download(string $path, int $maxbytes): string {
                $this->asked[] = $path;
                $file = $this->files[$path] ?? new api_exception('not_found', 'Not found', '', 404);
                if ($file instanceof api_exception) {
                    throw $file;
                }
                return $file;
            }
        };
    }

    /**
     * Requests of a family of the catalogue.
     *
     * @param string[] $files Slugs of the files (weight 700, subsets latin, latin-ext...).
     * @return array result.fontRequests
     */
    private function requests(array $files): array {
        return [['family' => 'Montserrat', 'weights' => [700], 'styles' => ['normal'], 'license' => 'OFL-1.1',
            'licenseUrl' => self::API . '/fonts/montserrat/LICENSE',
            'files' => array_map(fn($slug) => ['slug' => $slug, 'weight' => 700, 'style' => 'normal',
                'subset' => substr($slug, strlen('font-montserrat-'), -strlen('-700-normal')),
                'url' => self::API . '/fonts/montserrat/' . substr($slug, strlen('font-montserrat-')) . '.woff2',
            ], $files)]];
    }

    public function test_assets_of_the_request(): void {
        $logo = $this->image('logo-universite');
        $this->image('campus');
        $this->font('marque', 'Marque', 400);
        $this->font('marque-bold', 'marque', 700);
        $this->font('font-montserrat-latin-700-normal', 'Montserrat', 700, ['origin' => 'google', 'licence' => 'OFL-1.1',
            'subset' => 'latin']);

        $assets = request::assets(['campus'], 'Mets une bannière', resolver::empty_map());
        $images = array_column(array_filter($assets, fn($a) => $a['mime'] !== 'font/woff2'), null, 'slug');
        $fonts = array_column(array_filter($assets, fn($a) => $a['mime'] === 'font/woff2'), null, 'family');
        $this->assertEqualsCanonicalizing(['campus', 'logo-universite'], array_keys($images));
        // Thumbnail of the attached image only.
        $this->assertStringStartsWith('data:image/', $images['campus']['thumbnail']);
        $this->assertArrayNotHasKey('thumbnail', $images['logo-universite']);
        // Fonts by family (case ignored); licence only for the catalogue; subset only for its files.
        $this->assertEqualsCanonicalizing(['marque', 'montserrat'], array_map('strtolower', array_keys($fonts)));
        $marque = array_values(array_filter($assets, fn($a) => strtolower($a['family'] ?? '') === 'marque'))[0];
        $this->assertSame([['slug' => 'marque', 'weight' => 400, 'style' => 'normal'],
            ['slug' => 'marque-bold', 'weight' => 700, 'style' => 'normal']], $marque['files']);
        $this->assertArrayNotHasKey('license', $marque);
        $this->assertSame('OFL-1.1', $fonts['Montserrat']['license']);
        $this->assertSame('latin', $fonts['Montserrat']['files'][0]['subset']);
        // A family counts once in the limit.
        $this->assertSame(4, library::count_units());

        // About the logo: the logo of the draft is sent too.
        $draft = resolver::apply(resolver::empty_map(), [(object) ['type' => change::TYPE_SETTING, 'target' => 'logo',
            'content' => 'logo-universite', 'deleted' => false]], 0);
        $assets = array_column(request::assets([], 'Reprends les couleurs du logo', $draft), null, 'slug');
        $this->assertArrayHasKey('thumbnail', $assets['logo-universite']);
        $this->assertArrayNotHasKey('thumbnail', $assets['campus']);
        $this->assertSame((int) $logo->id, (int) library::get_all()['logo-universite']->id);
    }

    public function test_logo_of_moodle_copied_into_the_library(): void {
        $path = make_request_directory() . '/logo.png';
        imagepng(imagecreatetruecolor(300, 80), $path);
        get_file_storage()->create_file_from_pathname(['contextid' => \context_system::instance()->id,
            'component' => 'core_admin', 'filearea' => 'logo', 'itemid' => 0, 'filepath' => '/', 'filename' => 'logo.png'], $path);

        $assets = array_column(request::assets([], 'Agrandis le logo', resolver::empty_map()), null, 'slug');
        $this->assertArrayHasKey('logo-moodle', $assets);
        $this->assertStringStartsWith('data:image/', $assets['logo-moodle']['thumbnail']);
        $this->assertSame('moodle', library::get_all()['logo-moodle']->origin);
        // Copied once.
        request::assets([], 'Agrandis le logo', resolver::empty_map());
        $this->assertCount(1, library::get_all());
        // Nothing for a prompt about something else.
        $this->assertArrayNotHasKey('thumbnail', array_column(
            request::assets([], 'Fond bleu', resolver::empty_map()),
            null,
            'slug'
        )['logo-moodle']);
    }

    public function test_families_limits_and_licences(): void {
        for ($i = 0; $i < font_downloader::MAX_FILES; $i++) {
            $this->font('marque-' . $i, 'Marque', $i + 1);
        }
        try {
            $this->font('marque-more', 'MARQUE', 999);
            $this->fail('64 files at most');
        } catch (\moodle_exception $e) {
            $this->assertSame('asset:error:familyfull', $e->errorcode);
        }
        $this->assertSame(1, library::count_units());

        $google = $this->font('font-inter-latin-400-normal', 'Inter', 400, ['origin' => 'google', 'licence' => 'OFL-1.1']);
        library::set_family_licence('Inter', 'OFL-1.1', 'Copyright Inter. SIL OFL 1.1');
        $this->assertSame('Copyright Inter. SIL OFL 1.1', library::get_family('inter')->licencetext);
        // The last file of the family takes its licence away.
        library::delete((int) $google->id);
        $this->assertNull(library::get_family('Inter'));
    }

    public function test_requests_are_checked(): void {
        $parsed = font_downloader::parse($this->requests(['font-montserrat-latin-700-normal']));
        $this->assertSame('/fonts/montserrat/latin-700-normal.woff2', $parsed[0]['files'][0]['path']);
        $this->assertSame('/fonts/montserrat/LICENSE', $parsed[0]['licensepath']);

        foreach (
            [
                // Any other path of the API, or another URL, is never fetched.
                fn($r) => array_replace_recursive($r, [0 => ['files' => [0 => ['url' => 'https://evil.test/x.woff2']]]]),
                fn($r) => array_replace_recursive($r, [0 => ['files' => [0 => ['url' => self::API . '/account']]]]),
                fn($r) => array_replace_recursive($r, [0 => ['licenseUrl' => self::API . '/fonts/../account']]),
                // Fixtures of the demo generator for another generator.
                fn($r) => array_replace_recursive($r, [0 => ['files' => [0 => ['url' => 'mock:/fonts/montserrat/x.woff2']]]]),
                fn($r) => array_replace_recursive($r, [0 => ['license' => 'GPL-3.0']]),
                fn($r) => array_replace_recursive($r, [0 => ['files' => [0 => ['slug' => 'Bad Slug']]]]),
                fn($r) => array_replace_recursive($r, [0 => ['files' => [0 => ['style' => 'oblique']]]]),
            ] as $i => $alter
        ) {
            try {
                font_downloader::parse($alter($this->requests(['font-montserrat-latin-700-normal'])));
                $this->fail("Case $i accepted");
            } catch (\moodle_exception $e) {
                $this->assertSame('asset:error:fontrequest', $e->errorcode, "Case $i");
            }
        }
    }

    public function test_download_in_batches(): void {
        $woff2 = 'wOF2' . random_string(60);
        $client = $this->client([
            '/fonts/montserrat/latin-700-normal.woff2' => $woff2,
            '/fonts/montserrat/latin-ext-700-normal.woff2' => $woff2 . 'x',
            '/fonts/montserrat/LICENSE' => "Copyright 2011 The Montserrat Project Authors\nSIL Open Font License 1.1",
        ]);
        $requests = font_downloader::parse($this->requests(['font-montserrat-latin-700-normal',
            'font-montserrat-latin-ext-700-normal']));

        // No time left: nothing downloaded.
        $this->assertSame(['done' => false, 'stored' => 0, 'missing' => 2], font_downloader::download($requests, $client, 0));
        $this->assertSame(['done' => true, 'stored' => 2, 'missing' => 0], font_downloader::download($requests, $client));
        $file = library::get_all()['font-montserrat-latin-ext-700-normal'];
        $this->assertSame(
            ['Montserrat', 700, 'normal', 'latin-ext', 'google', 'OFL-1.1'],
            [$file->family, (int) $file->weight, $file->style, $file->subset, $file->origin, $file->licence]
        );
        $this->assertStringContainsString('Montserrat Project Authors', library::get_family('Montserrat')->licencetext);
        // Already in the library: not downloaded again.
        $asked = count($client->asked);
        $this->assertSame(['done' => true, 'stored' => 0, 'missing' => 0], font_downloader::download($requests, $client));
        $this->assertCount($asked, $client->asked);

        // A file Brandable cannot serve: the error goes up.
        $this->expectException(api_exception::class);
        font_downloader::download(font_downloader::parse($this->requests(['font-montserrat-cyrillic-700-normal'])), $client);
    }

    public function test_mock_generation_with_a_font(): void {
        $record = generation_manager::refresh(generation_manager::submit('Police Montserrat pour les titres')->id);
        $this->assertSame(generation_manager::COMPLETED, $record->status, (string) ($record->errormessage ?? ''));
        $font = library::get_all()['font-montserrat-latin-700-normal'];
        $this->assertSame('Montserrat', $font->family);
        $this->assertNotSame('', (string) library::get_family('Montserrat')->licencetext);
        // The chunk designates the file stored: its URL in the CSS.
        $code = \theme_brandable\local\overrides\context::with_revision(
            revision_manager::get_draft(),
            fn() => \theme_brandable\local\overrides\scss::get_code('post')
        );
        $this->assertStringContainsString('url("' . library::url($font)->out(false) . '") format("woff2")', $code);
        $this->assertContains('fonts', array_column(generation_manager::export($record)['events'], 'type'));
    }

    public function test_failed_download_is_resumed_without_generating_again(): void {
        $requests = $this->requests(['font-montserrat-latin-700-normal']);
        $generator = new class ($requests) extends sync_generator {
            /** @var int Results produced. */
            public int $generated = 0;

            /**
             * Constructor.
             *
             * @param array $requests Font requests.
             */
            public function __construct(
                /** @var array Font requests. */
                private array $requests
            ) {
            }

            #[\Override]
            public function generate(string $prompt, generation_context $context): generation_result {
                $this->generated++;
                $chunk = ['type' => 'scss', 'target' => 'post/font-montserrat'];
                return new generation_result('Montserrat', [change::from_array($chunk + [
                    'content' => 'h1 { font-family: "Montserrat"; }'])], false, $this->requests);
            }
        };
        // Not connected: the download fails (the result is not applied).
        $record = generation_manager::submit('Montserrat', null, $generator);
        $record = generation_manager::refresh($record->id, $generator);
        $this->assertSame(generation_manager::FAILED, $record->status);
        $this->assertSame(generation_manager::FONT_ERROR, $record->errorcode);
        $this->assertTrue(generation_manager::export($record)['resumable']);
        $this->assertSame(0, (int) ($record->revisionid ?? 0));

        // Resumed: the same result is read again (here: fails again, still not connected), nothing generated again.
        $record = generation_manager::resume((int) $record->id, $generator);
        $this->assertSame(generation_manager::FONT_ERROR, $record->errorcode);
        $this->assertSame(1, $generator->generated);

        // Too old: the result is no longer kept by Brandable.
        $GLOBALS['DB']->set_field('theme_brandable_generation', 'timecreated', time() - DAYSECS - 1, ['id' => $record->id]);
        $this->expectException(\moodle_exception::class);
        generation_manager::resume((int) $record->id);
    }
}
