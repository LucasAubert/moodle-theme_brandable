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

namespace theme_brandable\local\cloud;

use theme_brandable\local\account\api_client;

#[\PHPUnit\Framework\Attributes\CoversClass(proposals::class)]
/**
 * Tests for the proposals of a version to another environment (contract 13.7, spec C15, C16).
 *
 * @package    theme_brandable
 * @category   test
 * @copyright  2026 Lucas Aubert
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \theme_brandable\local\cloud\proposals
 */
final class proposals_test extends \advanced_testcase {
    /**
     * A client recording the requests and answering with the same answer.
     *
     * @param array $answer Answer.
     * @return api_client
     */
    private function client(array $answer = []): api_client {
        return new class ($answer) extends api_client {
            /** @var array Requests: [method path, body]. */
            public array $requests = [];

            /**
             * Constructor.
             *
             * @param array $answer Answer.
             */
            public function __construct(
                /** @var array Answer. */
                private array $answer
            ) {
                parent::__construct('https://api.example.com/api/v1', 'bsk_test');
            }

            #[\Override]
            public function request(
                string $method,
                string $path,
                ?array $body = null,
                bool $authenticated = true,
                ?string $raw = null,
                string $contenttype = 'application/json'
            ): array {
                $this->requests[] = ["$method $path", $body];
                return $this->answer;
            }
        };
    }

    /**
     * A state of the project with a proposal for this environment.
     *
     * @param string $id Proposal.
     * @param string $status Status.
     * @param string $to Environment proposed to.
     * @return array
     */
    private function state(string $id = 'p1', string $status = 'pending', string $to = 'e-prod'): array {
        return ['access' => 'full', 'environment' => ['id' => 'e-prod', 'name' => 'Production'], 'environments' => [],
            'proposals' => [['id' => $id, 'version' => ['id' => 'v12', 'number' => 12, 'note' => 'Navy bar.'],
                'from' => ['id' => 'e-staging', 'name' => 'Staging'], 'to' => ['id' => $to, 'name' => 'Production'],
                'message' => 'Before the start of term', 'author' => ['name' => 'Paul Durand'],
                'createdAt' => '2026-11-05T09:15:00.000Z', 'status' => $status]]];
    }

    public function test_pending_proposal(): void {
        $this->assertSame([
            'id' => 'p1', 'versionid' => 'v12', 'number' => 12, 'note' => 'Navy bar.', 'from' => 'Staging',
            'author' => 'Paul Durand', 'message' => 'Before the start of term', 'createdat' => strtotime('2026-11-05T09:15:00Z'),
        ], proposals::pending($this->state()));
        // Not waiting any more, for another environment, or with an id out of the form: none.
        $this->assertNull(proposals::pending($this->state('p1', 'superseded')));
        $this->assertNull(proposals::pending($this->state('p1', 'pending', 'e-other')));
        $this->assertNull(proposals::pending($this->state('../p1')));
        $this->assertNull(proposals::pending(['proposals' => []]));
    }

    public function test_propose_and_dismiss(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user(['firstname' => 'Paul', 'lastname' => 'Durand']);
        $this->setUser($user);
        $client = $this->client(['id' => 'p2', 'to' => ['name' => 'Production']]);
        $proposal = proposals::propose('v12', 'e-prod', '  ' . str_repeat('é', 600) . ' ', $client);
        $this->assertSame('p2', $proposal['id']);
        [$request, $body] = $client->requests[0];
        $this->assertSame('POST /cloud/proposals', $request);
        $this->assertSame('v12', $body['versionId']);
        $this->assertSame('e-prod', $body['environmentId']);
        $this->assertSame(proposals::MESSAGE_LENGTH, \core_text::strlen($body['message']));
        $this->assertSame(['hash' => cloud::author_hash((int) $user->id), 'name' => 'Paul Durand'], $body['author']);
        // Without a message: none sent.
        proposals::propose('v12', 'e-prod', ' ', $client);
        $this->assertArrayNotHasKey('message', $client->requests[1][1]);

        proposals::dismiss('p2', $client);
        $this->assertSame('POST /cloud/proposals/p2/dismiss', $client->requests[2][0]);

        $this->expectException(\invalid_parameter_exception::class);
        proposals::dismiss('p2/../../account', $client);
    }

    public function test_notified_once(): void {
        $this->resetAfterTest();
        $editor = $this->getDataGenerator()->create_user();
        $role = $this->getDataGenerator()->create_role();
        assign_capability('theme/brandable:edit', CAP_ALLOW, $role, \context_system::instance());
        role_assign($role, $editor->id, \context_system::instance());
        $sink = $this->redirectMessages();

        // The administrator and the user who can edit the design.
        $this->assertSame(2, proposals::notify($this->state()));
        $messages = $sink->get_messages();
        $this->assertCount(2, $messages);
        $this->assertEqualsCanonicalizing([get_admin()->id, $editor->id], array_column($messages, 'useridto'));
        $this->assertSame(
            get_string('cloud:proposal:subject', 'theme_brandable', (object) ['number' => 12, 'from' => 'Staging']),
            $messages[0]->subject
        );
        $this->assertStringContainsString('Before the start of term', $messages[0]->fullmessage);
        $this->assertStringContainsString('/theme/brandable/editor.php', $messages[0]->fullmessage);

        // Once per proposal; a new proposal is notified.
        $sink->clear();
        $this->assertSame(0, proposals::notify($this->state()));
        $this->assertSame(0, proposals::notify(['proposals' => []]));
        $this->assertSame(2, proposals::notify($this->state('p2')));
        $this->assertCount(2, $sink->get_messages());
    }
}
