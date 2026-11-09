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

/**
 * Tests for the questions of the generator (needsClarification) and their answers.
 *
 * @package    theme_brandable
 * @category   test
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \theme_brandable\local\generator\generation_manager
 * @covers     \theme_brandable\local\generator\generation_context
 * @covers     \theme_brandable\local\generator\generation_result
 * @covers     \theme_brandable\local\generator\mock_generator
 */
#[\PHPUnit\Framework\Attributes\CoversClass(generation_manager::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(generation_context::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(generation_result::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(mock_generator::class)]
final class question_test extends \advanced_testcase {
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
     * Sends a prompt to the mock generator and polls its result.
     *
     * @param string $prompt Prompt.
     * @param int $questionid Question answered.
     * @param array|null $selection Targeted element.
     * @return \stdClass Generation record.
     */
    private function ask(string $prompt, int $questionid = 0, ?array $selection = null): \stdClass {
        $record = generation_manager::submit($prompt, null, null, $selection, $questionid);
        return generation_manager::refresh($record->id);
    }

    public function test_question_is_neither_validated_nor_repaired(): void {
        $question = $this->ask('Mets ça en rouge, c\'est ambigu');
        $this->assertSame(generation_manager::QUESTION, $question->status);
        $this->assertSame(get_string('mock:question', 'theme_brandable'), $question->summary);
        $this->assertEmpty($question->revisionid);
        $this->assertSame(0, (int) $question->repairs);
        $this->assertSame(0, revision_manager::get_draft());

        $export = generation_manager::export($question);
        $this->assertSame(['planning', 'clarification', 'done'], array_column($export['events'], 'type'));
        $this->assertSame('', $export['errormessage']);
        $this->assertFalse($export['retryable']);
    }

    public function test_answer_is_sent_with_the_question(): void {
        $question = $this->ask('Mets ça en rouge, c\'est ambigu');
        $answer = $this->ask('Les boutons', (int) $question->id);
        $this->assertSame(generation_manager::COMPLETED, $answer->status);
        $this->assertSame((int) $question->id, (int) $answer->questionid);
        // The mock read the prompt of the question from the history: red was asked there.
        $this->assertStringContainsString(
            get_string('mock:answered', 'theme_brandable', 'Mets ça en rouge, c\'est ambigu'),
            $answer->summary
        );
        $map = resolver::get_map(revision_manager::get_draft());
        $this->assertStringContainsString('#b42318', $map[change::TYPE_SCSS]['pre/brand-colors']['content']);

        // Later requests keep the exchange before the answer.
        $history = generation_context::build()->history;
        $this->assertSame([
            ['prompt' => 'Mets ça en rouge, c\'est ambigu', 'summary' => get_string('mock:question', 'theme_brandable')],
            ['prompt' => 'Les boutons', 'summary' => $answer->summary],
        ], $history);
    }

    public function test_answer_keeps_the_element_of_the_question(): void {
        $selection = ['html' => '<a class="x">Langue</a>', 'label' => 'Langue', 'ancestors' => [['tag' => 'body', 'id' => '']]];
        $record = generation_manager::submit('Masque ça, ambigu', 'http://example.com/my/courses.php', null, $selection);
        $question = generation_manager::refresh($record->id);
        $this->assertSame(generation_manager::QUESTION, $question->status);

        $answer = generation_manager::submit('Le lien', 'http://example.com/my/', null, null, (int) $question->id);
        $this->assertSame($question->selection, $answer->selection);
        $this->assertSame($question->pageurl, $answer->pageurl);

        // A new element targeted for the answer replaces it.
        $other = ['html' => '<b>y</b>', 'label' => 'Autre', 'ancestors' => [['tag' => 'body', 'id' => '']]];
        $answer = generation_manager::submit('Celui-ci', 'http://example.com/my/', null, $other, (int) $question->id);
        $this->assertSame('Autre', json_decode($answer->selection, true)['label']);
    }

    public function test_exchanges_are_followed_back(): void {
        global $DB;

        $first = $DB->insert_record('theme_brandable_generation', ['userid' => 2, 'generator' => 'mock', 'prompt' => 'A',
            'summary' => 'Which A?', 'status' => generation_manager::QUESTION, 'timecreated' => 1, 'timemodified' => 1]);
        $second = $DB->insert_record('theme_brandable_generation', ['userid' => 2, 'generator' => 'mock', 'prompt' => 'B',
            'summary' => 'Which B?', 'status' => generation_manager::QUESTION, 'questionid' => $first, 'timecreated' => 2,
            'timemodified' => 2]);
        $this->assertSame(
            [['prompt' => 'A', 'summary' => 'Which A?'], ['prompt' => 'B', 'summary' => 'Which B?']],
            generation_manager::get_exchange($second)
        );
        $context = generation_context::build(null, null, generation_manager::get_exchange($second));
        $this->assertSame('Which B?', array_slice($context->to_request('C')['history'], -1)[0]['summary']);
    }

    public function test_only_a_question_can_be_answered(): void {
        $record = $this->ask('Une navbar sombre');
        $this->assertSame(generation_manager::COMPLETED, $record->status);
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('error:noquestion', 'theme_brandable'));
        generation_manager::submit('Réponse', null, null, null, (int) $record->id);
    }

    public function test_contract_flag_is_read(): void {
        $status = generation_status::from_array(['status' => 'done', 'events' => [], 'result' => [
            'summary' => 'Which element?', 'changes' => [], 'needsClarification' => true,
        ]]);
        $this->assertTrue($status->result->needsclarification);
        $this->assertFalse(generation_result::from_array(['summary' => 's', 'changes' => []])->needsclarification);
    }
}
