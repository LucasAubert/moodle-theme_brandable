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

namespace theme_brandable\local\generator;

/**
 * Tests for the Brandable API client (HTTP responses mocked).
 *
 * @package    theme_brandable
 * @category   test
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \theme_brandable\local\generator\remote_generator
 */
#[\PHPUnit\Framework\Attributes\CoversClass(remote_generator::class)]
final class remote_generator_test extends \advanced_testcase {
    #[\Override]
    public static function setUpBeforeClass(): void {
        global $CFG;
        parent::setUpBeforeClass();
        require_once($CFG->libdir . '/filelib.php');
    }

    #[\Override]
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
    }

    /**
     * Returns a configured client.
     *
     * @return remote_generator
     */
    private function client(): remote_generator {
        return new remote_generator('https://api.example.com/', str_repeat('k', 64));
    }

    public function test_start_poll_repair(): void {
        \curl::mock_response(json_encode(['generationId' => '8b0b4a52-3b1f-4c3e-9a5e-1f2a3b4c5d6e']));
        $id = $this->client()->start('Make it blue', generation_context::build());
        $this->assertSame('8b0b4a52-3b1f-4c3e-9a5e-1f2a3b4c5d6e', $id);

        \curl::mock_response(json_encode([
            'status' => 'done',
            'events' => [
                ['type' => 'planning', 'run' => 0, 'message' => 'Je planifie', 'at' => '2026-10-09T10:00:00.000Z'],
                ['type' => 'done', 'run' => 0, 'message' => 'Terminé', 'at' => '2026-10-09T10:00:05.000Z'],
            ],
            'result' => ['summary' => 'Bleu', 'changes' => [
                ['type' => 'scss', 'target' => 'pre/brand-colors', 'content' => '$primary: #1f3a5f;', 'deleted' => false,
                    'sourceHash' => null, 'moodleVersion' => '4.5.0'],
            ]],
        ]));
        $status = $this->client()->poll($id);
        $this->assertSame(generation_status::DONE, $status->status);
        $this->assertSame('Bleu', $status->result->summary);
        $this->assertSame('pre/brand-colors', $status->result->changes[0]->target);
        $this->assertSame(['planning', 'done'], array_column($status->events, 'type'));

        \curl::mock_response(json_encode(['status' => 'error', 'events' => [], 'error' => ['code' => 'interrupted',
            'message' => 'La génération a été interrompue.']]));
        $status = $this->client()->poll($id);
        $this->assertSame(generation_status::ERROR, $status->status);
        $this->assertSame(['code' => 'interrupted', 'message' => 'La génération a été interrompue.'], $status->error);

        \curl::mock_response(json_encode(['generationId' => $id]));
        $this->client()->repair($id, [['target' => '', 'message' => 'Global error']]);
    }

    public function test_api_errors_carry_code_and_message(): void {
        \curl::mock_response(json_encode(['error' => ['code' => 'repair_limit_reached',
            'message' => 'Nombre maximal de réparations atteint.']]));
        try {
            $this->client()->repair('id', [['target' => 'pre/x', 'message' => 'm']]);
            $this->fail('An exception was expected');
        } catch (generator_exception $e) {
            $this->assertSame('repair_limit_reached', $e->generatorcode);
            $this->assertSame('Nombre maximal de réparations atteint.', $e->displaymessage);
        }

        \curl::mock_response('<html>Bad gateway</html>');
        $this->expectException(generator_exception::class);
        $this->client()->poll('id');
    }

    public function test_not_configured(): void {
        $this->expectException(generator_exception::class);
        (new remote_generator('', ''))->poll('id');
    }
}
