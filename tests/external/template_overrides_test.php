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

namespace theme_brandable\external;

use core_external\external_api;
use theme_brandable\local\overrides\change;
use theme_brandable\local\overrides\revision_manager;
use theme_brandable\local\preview;

#[\PHPUnit\Framework\Attributes\CoversClass(get_template_overrides::class)]
/**
 * Tests for the templates rendered in JavaScript: overrides service and page initialisation.
 *
 * @package    theme_brandable
 * @category   test
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \theme_brandable\external\get_template_overrides
 */
final class template_overrides_test extends \advanced_testcase {
    #[\Override]
    public static function setUpBeforeClass(): void {
        global $CFG;
        parent::setUpBeforeClass();
        require_once($CFG->dirroot . '/theme/brandable/lib.php');
    }

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        preview::reset();
    }

    #[\Override]
    protected function tearDown(): void {
        preview::disable();
        preview::reset();
        parent::tearDown();
    }

    /**
     * Creates a revision (as admin).
     *
     * @param array $templates Template name => content.
     * @return int Revision id.
     */
    private function revision(array $templates): int {
        $this->setAdminUser();
        $changes = [];
        foreach ($templates as $name => $content) {
            $changes[] = change::from_array(['type' => 'template', 'target' => $name, 'content' => $content]);
        }
        return revision_manager::create($changes, 'p', 's');
    }

    /**
     * Calls the service.
     *
     * @param int $revisionid Revision id.
     * @return array
     */
    private function call(int $revisionid): array {
        return external_api::clean_returnvalue(
            get_template_overrides::execute_returns(),
            get_template_overrides::execute($revisionid)
        );
    }

    public function test_published_overrides_are_public(): void {
        $this->revision(['theme_brandable/js_a' => 'A']);
        revision_manager::publish();
        $published = revision_manager::get_published();
        $this->setUser(null);

        $result = $this->call($published);
        $this->assertSame([['name' => 'theme_brandable/js_a', 'scopelevel' => 'site', 'scopevalue' => '',
            'contenthash' => hash('sha256', 'A'), 'content' => 'A']], $result['templates']);
    }

    public function test_other_revisions_require_the_capability(): void {
        $draft = $this->revision(['theme_brandable/js_a' => 'A']);
        $this->assertSame('A', $this->call($draft)['templates'][0]['content']);

        $this->setUser($this->getDataGenerator()->create_user());
        $this->expectException(\required_capability_exception::class);
        $this->call($draft);
    }

    public function test_draft_is_refused_to_guests(): void {
        $draft = $this->revision(['theme_brandable/js_a' => 'A']);
        $this->setUser(null);
        $this->expectException(\moodle_exception::class);
        $this->call($draft);
    }

    public function test_page_init_lists_the_active_overrides(): void {
        $page = new \moodle_page();
        theme_brandable_page_init($page);
        $this->assertSame([], $this->get_js_data($page));

        $this->revision(['theme_brandable/js_a' => 'A', 'core/loading' => 'L']);
        revision_manager::publish();
        $published = revision_manager::get_published();
        $draft = $this->revision(['theme_brandable/js_b' => 'B']);

        $page = new \moodle_page();
        theme_brandable_page_init($page);
        $data = $this->get_js_data($page);
        $this->assertSame(['revision', 'assets', 'public', 'templates', 'markers'], array_keys($data));
        $this->assertSame([$published, true, false], [$data['revision'], $data['public'], $data['markers']]);
        // An object, so that no override is {} (not []) in JavaScript.
        $this->assertSame([
            'theme_brandable/js_a' => hash('sha256', 'A'),
            'core/loading' => hash('sha256', 'L'),
        ], (array) $data['templates']);

        preview::enable();
        $page = new \moodle_page();
        theme_brandable_page_init($page);
        $data = $this->get_js_data($page);
        $this->assertSame($draft, $data['revision']);
        $this->assertFalse($data['public']);
        $this->assertArrayHasKey('theme_brandable/js_b', (array) $data['templates']);
    }

    /**
     * Returns the data put in the page head for the template loader.
     *
     * @param \moodle_page $page Page.
     * @return array
     */
    private function get_js_data(\moodle_page $page): array {
        $variables = (new \ReflectionProperty($page->requires, 'jsinitvariables'))->getValue($page->requires);
        foreach ($variables['head'] ?? [] as [$name, $data]) {
            if ($name === 'theme_brandable_overrides') {
                return $data;
            }
        }
        return [];
    }
}
