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

use theme_brandable\local\overrides\change;
use theme_brandable\local\overrides\resolver;
use theme_brandable\local\overrides\revision_manager;

#[\PHPUnit\Framework\Attributes\CoversClass(generation_manager::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(sync_generator::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(mock_generator::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(generation_status::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(generation_result::class)]
/**
 * Tests for the generation orchestration (start, polling relay, validation, repairs) and the mock generator.
 *
 * @package    theme_brandable
 * @category   test
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \theme_brandable\local\generator\generation_manager
 * @covers     \theme_brandable\local\generator\sync_generator
 * @covers     \theme_brandable\local\generator\mock_generator
 * @covers     \theme_brandable\local\generator\generation_status
 * @covers     \theme_brandable\local\generator\generation_result
 */
final class generation_manager_test extends \advanced_testcase {
    #[\Override]
    protected function setUp(): void {
        global $CFG;

        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        // Demo generator.
        $CFG->theme_brandable_devmode = true;
    }

    /**
     * A scripted generator: each start / repair produces the next result of the script.
     *
     * @param array $script Results (generation_result) or errors (generator_exception), in order.
     * @return sync_generator
     */
    private function scripted(array $script): sync_generator {
        return new class ($script) extends sync_generator {
            /** @var array Remaining results. */
            private array $script;

            /**
             * Constructor.
             *
             * @param array $script Results.
             */
            public function __construct(array $script) {
                $this->script = $script;
            }

            #[\Override]
            public function generate(string $prompt, generation_context $context): generation_result {
                return $this->next();
            }

            #[\Override]
            protected function repair_result(generation_result $result, array $errors): ?generation_result {
                return $this->next();
            }

            /**
             * Returns the next scripted result.
             *
             * @return generation_result
             */
            private function next(): generation_result {
                $item = array_shift($this->script);
                if ($item instanceof generator_exception) {
                    throw $item;
                }
                return $item;
            }
        };
    }

    /**
     * A result with one template change.
     *
     * @param string $content Template content.
     * @return generation_result
     */
    private function make_result(string $content): generation_result {
        return new generation_result('Summary ' . $content, [change::from_array([
            'type' => 'template', 'target' => 'theme_brandable/generated', 'content' => $content, 'sourceHash' => null,
        ])]);
    }

    public function test_mock_generation_creates_a_revision(): void {
        $record = generation_manager::submit('Use #123abc with a dark navbar, rounded corners and a hero banner');
        $this->assertSame(generation_manager::RUNNING, $record->status);
        $this->assertSame('mock', $record->generator);

        $record = generation_manager::refresh($record->id);
        $this->assertSame(generation_manager::COMPLETED, $record->status, (string) ($record->errormessage ?? ''));
        $this->assertSame((int) $record->revisionid, revision_manager::get_draft());

        $map = resolver::get_map(revision_manager::get_draft());
        $this->assertSame(
            ['pre/brand-colors', 'post/dark-navbar', 'pre/rounded-corners', 'post/hero-banner'],
            array_keys($map[change::TYPE_SCSS])
        );
        $this->assertStringContainsString('#123abc', $map[change::TYPE_SCSS]['pre/brand-colors']['content']);
        $this->assertSame(['theme_brandable/hero_banner', 'core/full_header'], array_keys($map[change::TYPE_TEMPLATE]));
        $this->assertFalse($map[change::TYPE_TEMPLATE]['core/full_header']['needsreview']);

        $export = generation_manager::export($record);
        $this->assertSame(['planning', 'editing', 'done'], array_column($export['events'], 'type'));
        $this->assertSame([0, 0, 0], array_column($export['events'], 'run'));
        $this->assertNotSame('', $export['summary']);

        // Polling again changes nothing (no second revision).
        generation_manager::refresh($record->id);
        $this->assertCount(1, revision_manager::get_history());
    }

    public function test_events_in_order_of_time_whatever_their_format(): void {
        global $DB;

        $record = generation_manager::refresh(generation_manager::submit('Rounded corners')->id);
        // Brandable without milliseconds, the plugin with: in the same second, the plugin's event comes after.
        $DB->set_field('theme_brandable_generation', 'remoteevents', json_encode([
            ['type' => 'done', 'message' => 'Done', 'at' => '2026-11-09T10:00:05Z', 'run' => 0],
            ['type' => 'planning', 'message' => 'Planning', 'at' => '2026-11-09T10:00:04Z', 'run' => 0],
        ]), ['id' => $record->id]);
        $DB->set_field('theme_brandable_generation', 'localevents', json_encode([
            ['type' => 'verified', 'message' => 'Verified', 'at' => '2026-11-09T10:00:05.000Z', 'run' => 0],
            ['type' => 'error', 'message' => 'Error', 'at' => '2026-11-09T10:00:04.500Z', 'run' => 0],
            ['type' => 'repair', 'message' => 'Repair', 'at' => '2026-11-09T10:00:01.000Z', 'run' => 1],
        ]), ['id' => $record->id]);
        $record = $DB->get_record('theme_brandable_generation', ['id' => $record->id]);

        $this->assertSame(
            ['planning', 'error', 'done', 'verified', 'repair'],
            array_column(generation_manager::export($record)['events'], 'type')
        );
    }

    public function test_mock_without_keyword_still_produces_a_valid_change(): void {
        $record = generation_manager::refresh(generation_manager::submit('Something nicer please')->id);
        $this->assertSame(generation_manager::COMPLETED, $record->status);
        $this->assertArrayHasKey('post/demo-accent', resolver::get_map(revision_manager::get_draft())[change::TYPE_SCSS]);
    }

    public function test_invalid_result_is_repaired(): void {
        $generator = $this->scripted([$this->make_result('{{#broken}}'), $this->make_result('<b>fixed</b>')]);
        $record = generation_manager::submit('prompt', null, $generator);

        $record = generation_manager::refresh($record->id, $generator);
        $this->assertSame(generation_manager::RUNNING, $record->status);
        $this->assertSame(1, (int) $record->repairs);
        $this->assertSame(0, revision_manager::get_draft());

        $record = generation_manager::refresh($record->id, $generator);
        $this->assertSame(generation_manager::COMPLETED, $record->status);
        $templates = resolver::get_map(revision_manager::get_draft())['template'];
        $this->assertSame('<b>fixed</b>', $templates['theme_brandable/generated']['content']);

        $events = generation_manager::export($record)['events'];
        $rejected = array_values(array_filter($events, fn($event) => $event['type'] === generation_manager::EVENT_REJECTED));
        $this->assertCount(1, $rejected);
        $this->assertSame(0, $rejected[0]['run']);
        $this->assertStringContainsString('theme_brandable/generated', $rejected[0]['message']);
        $this->assertContains(1, array_column($events, 'run'));
    }

    public function test_javascript_in_a_template_is_sent_back_for_repair(): void {
        $generator = $this->scripted([
            $this->make_result('<a href="#" onclick="x()">a</a>'),
            $this->make_result('<a href="#">a</a>'),
        ]);
        $record = generation_manager::submit('prompt', null, $generator);
        $record = generation_manager::refresh($record->id, $generator);
        $this->assertSame(generation_manager::RUNNING, $record->status);
        $this->assertSame(1, (int) $record->repairs);
        $rejected = array_values(array_filter(
            generation_manager::export($record)['events'],
            fn($event) => $event['type'] === generation_manager::EVENT_REJECTED
        ));
        $this->assertStringContainsString(
            'JavaScript is not allowed in template overrides; use CSS or template structure',
            $rejected[0]['message']
        );

        $record = generation_manager::refresh($record->id, $generator);
        $this->assertSame(generation_manager::COMPLETED, $record->status);
    }

    public function test_still_invalid_after_two_repairs_fails(): void {
        $generator = $this->scripted([$this->make_result('{{#a}}'), $this->make_result('{{#b}}'), $this->make_result('{{#c}}')]);
        $record = generation_manager::submit('prompt', null, $generator);
        for ($i = 0; $i < 3; $i++) {
            $record = generation_manager::refresh($record->id, $generator);
        }
        $this->assertSame(generation_manager::FAILED, $record->status);
        $this->assertSame(2, (int) $record->repairs);
        $this->assertSame(get_string('error:stillinvalid', 'theme_brandable'), $record->errormessage);
        $this->assertSame(0, revision_manager::get_draft());
    }

    public function test_generator_errors_are_shown_as_is(): void {
        $generator = $this->scripted([new generator_exception('generation_failed', 'Le modèle n\'a rien proposé.')]);
        $record = generation_manager::refresh(generation_manager::submit('prompt', null, $generator)->id, $generator);
        $this->assertSame(generation_manager::FAILED, $record->status);
        $this->assertSame('generation_failed', $record->errorcode);
        $this->assertSame('Le modèle n\'a rien proposé.', $record->errormessage);
    }

    public function test_service_failures_can_be_retried(): void {
        // Reported by the backend: its message stays in the steps, the editor gets a clear one.
        $generator = $this->scripted([new generator_exception('service_unavailable', 'Azure 429 high demand')]);
        $record = generation_manager::refresh(generation_manager::submit('prompt', null, $generator)->id, $generator);
        $export = generation_manager::export($record);
        $this->assertTrue($export['retryable']);
        $this->assertSame(get_string('error:serviceunavailable', 'theme_brandable'), $export['errormessage']);
        $this->assertSame('Azure 429 high demand', $record->errormessage);

        // Not a service failure: shown as is, no retry.
        $generator = $this->scripted([new generator_exception('generation_failed', 'Le modèle n\'a rien proposé.')]);
        $record = generation_manager::refresh(generation_manager::submit('prompt', null, $generator)->id, $generator);
        $export = generation_manager::export($record);
        $this->assertFalse($export['retryable']);
        $this->assertSame('Le modèle n\'a rien proposé.', $export['errormessage']);
    }

    public function test_unreachable_api_keeps_the_details_in_the_steps(): void {
        // The API cannot be reached when the prompt is sent.
        $generator = new class extends sync_generator {
            #[\Override]
            public function start(string $prompt, generation_context $context): string {
                throw new generator_exception('connection_failed', 'Impossible de joindre l\'API Brandable : timeout');
            }

            #[\Override]
            public function generate(string $prompt, generation_context $context): generation_result {
                throw new \coding_exception('Not called');
            }
        };
        $record = generation_manager::submit('prompt', null, $generator);
        $this->assertSame(generation_manager::FAILED, $record->status);
        $export = generation_manager::export($record);
        $this->assertTrue($export['retryable']);
        $this->assertContains('Impossible de joindre l\'API Brandable : timeout', array_column($export['events'], 'message'));
    }

    /**
     * A generator whose progress cannot be read the first times (Brandable unavailable, contract 7).
     *
     * @param int $outages Polls that fail before the result is read.
     * @return sync_generator
     */
    private function unavailable_while_polling(int $outages): sync_generator {
        $generator = new class extends sync_generator {
            /** @var int Polls that still fail. */
            public int $outages = 0;
            /** @var int Polls asked. */
            public int $polls = 0;

            #[\Override]
            public function generate(string $prompt, generation_context $context): generation_result {
                return generation_result::from_array(['summary' => 'Done', 'changes' => [['type' => 'scss',
                    'target' => 'post/outage', 'content' => '.card { border-radius: 1rem; }']]]);
            }

            #[\Override]
            public function poll(string $id): \theme_brandable\local\generator\generation_status {
                $this->polls++;
                if ($this->outages > 0) {
                    $this->outages--;
                    throw new generator_exception(
                        'service_unavailable',
                        'Brandable est momentanément indisponible.',
                        '',
                        30,
                        true
                    );
                }
                return parent::poll($id);
            }
        };
        $generator->outages = $outages;
        return $generator;
    }

    /**
     * Moves the end of the wait of a generation to the past (its Retry-After is over).
     *
     * @param int $id Generation id.
     * @param int $since Start of the outage, unchanged when 0.
     */
    private function end_of_wait(int $id, int $since = 0): void {
        global $DB;
        $events = json_decode($DB->get_field('theme_brandable_generation', 'localevents', ['id' => $id]), true);
        $last = array_key_last($events);
        $events[$last]['until'] = time() - 1;
        if ($since) {
            $events[$last]['since'] = $since;
        }
        $DB->set_field('theme_brandable_generation', 'localevents', json_encode($events), ['id' => $id]);
    }

    public function test_brandable_unavailable_while_generating(): void {
        $generator = $this->unavailable_while_polling(1);
        $id = (int) generation_manager::submit('prompt', null, $generator)->id;

        // 503 while the progress is read: the request is kept, read again after the Retry-After only.
        $record = generation_manager::refresh($id, $generator);
        $this->assertSame(generation_manager::RUNNING, $record->status);
        $export = generation_manager::export($record);
        $this->assertEqualsWithDelta(30, $export['waitinguntil'], 2);
        $this->assertContains('Brandable est momentanément indisponible.', array_column($export['events'], 'message'));
        $this->assertSame(['type', 'message', 'at', 'run'], array_keys(end($export['events'])));
        generation_manager::refresh($id, $generator);
        $this->assertSame(1, $generator->polls);

        // Back: the result is read and becomes a revision.
        $this->end_of_wait($id);
        $record = generation_manager::refresh($id, $generator);
        $this->assertSame(generation_manager::COMPLETED, $record->status);
        $this->assertSame(0, generation_manager::export($record)['waitinguntil']);
    }

    public function test_long_outage_fails_the_generation(): void {
        $generator = $this->unavailable_while_polling(2);
        $id = (int) generation_manager::submit('prompt', null, $generator)->id;
        generation_manager::refresh($id, $generator);
        $this->end_of_wait($id, time() - generation_manager::MAX_OUTAGE - 1);
        $record = generation_manager::refresh($id, $generator);
        $this->assertSame(generation_manager::FAILED, $record->status);
        $export = generation_manager::export($record);
        $this->assertTrue($export['retryable']);
        // The message of Brandable, not the one of an overloaded generation.
        $this->assertSame('Brandable est momentanément indisponible.', $export['errormessage']);
    }

    public function test_brandable_unavailable_when_the_prompt_is_sent(): void {
        $generator = new class extends sync_generator {
            #[\Override]
            public function start(string $prompt, generation_context $context): string {
                throw new generator_exception(
                    'service_unavailable',
                    'Brandable est momentanément indisponible.',
                    '',
                    45,
                    true
                );
            }

            #[\Override]
            public function generate(string $prompt, generation_context $context): generation_result {
                throw new \coding_exception('Not called');
            }
        };
        $record = generation_manager::submit('Make it blue', null, $generator);
        $this->assertSame(generation_manager::FAILED, $record->status);
        $export = generation_manager::export($record);
        // The prompt is kept: sent again at the Retry-After by the editor, or with "Try again".
        $this->assertSame('Make it blue', $export['prompt']);
        $this->assertTrue($export['retryable']);
        $this->assertEqualsWithDelta(time() + 45, $export['retryat'], 2);
        $this->assertSame('Brandable est momentanément indisponible.', $export['errormessage']);
        $this->assertSame(0, $export['waitinguntil']);
    }

    public function test_draft_moved_during_generation(): void {
        $generator = $this->scripted([$this->make_result('<b>late</b>')]);
        $record = generation_manager::submit('prompt', null, $generator);
        revision_manager::create([change::from_array(['type' => 'template', 'target' => 'theme_brandable/other',
            'content' => 'x'])], 'meanwhile', 's');

        $record = generation_manager::refresh($record->id, $generator);
        $this->assertSame(generation_manager::FAILED, $record->status);
        $this->assertSame(get_string('error:draftchanged', 'theme_brandable'), $record->errormessage);
    }

    public function test_empty_prompt_is_refused(): void {
        $this->expectException(\moodle_exception::class);
        generation_manager::submit('   ');
    }

    /**
     * A generator whose start fails with an API error.
     *
     * @param string $code Error code.
     * @param string $url Page given with the error.
     * @return sync_generator
     */
    private function refused(string $code, string $url = ''): sync_generator {
        return new class ($code, $url) extends sync_generator {
            /**
             * Constructor.
             *
             * @param string $code Error code.
             * @param string $url Page given with the error.
             */
            public function __construct(
                /** @var string Error code. */
                private string $code,
                /** @var string Page given with the error. */
                private string $url
            ) {
            }

            #[\Override]
            public function start(string $prompt, generation_context $context): string {
                throw new generator_exception($this->code, 'Message of Brandable', $this->url);
            }

            #[\Override]
            public function generate(string $prompt, generation_context $context): generation_result {
                throw new \coding_exception('Not called');
            }
        };
    }

    public function test_account_errors_offer_an_action(): void {
        $url = 'https://app.brandablelms.com/billing/credits?organization=o1';
        $export = generation_manager::export(generation_manager::submit('p', null, $this->refused('insufficient_credits', $url)));
        $this->assertSame('credits', $export['action']);
        $this->assertSame($url, $export['errorurl']);
        // Message of Brandable as is; the same prompt can be sent again once credits are bought.
        $this->assertSame('Message of Brandable', $export['errormessage']);
        $this->assertTrue($export['retryable']);

        $this->assertSame('plan', generation_manager::export(generation_manager::submit(
            'p',
            null,
            $this->refused('plan_required')
        ))['action']);
        $export = generation_manager::export(generation_manager::submit('p', null, $this->refused('site_mismatch')));
        $this->assertSame('relink', $export['action']);
        $this->assertFalse($export['retryable']);
        $this->assertSame('', generation_manager::export(generation_manager::submit(
            'p',
            null,
            $this->refused('generation_failed')
        ))['action']);
    }

    public function test_refused_key_shows_the_connection_screen(): void {
        \theme_brandable\local\account\connection::store_key('bsk_' . str_repeat('a', 43));
        $export = generation_manager::export(generation_manager::submit('p', null, $this->refused('site_not_connected')));
        $this->assertSame('connect', $export['action']);
        $this->assertFalse(\theme_brandable\local\account\connection::is_connected());
    }
}
