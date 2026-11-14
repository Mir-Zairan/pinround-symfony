<?php

declare(strict_types=1);

namespace App\Tests\Functional\Conversation;

use App\Conversation\UI\Component\Thread;
use App\Conversation\UI\ThreadController;
use App\Identity\Domain\User;
use App\SharedKernel\Domain\OrganizationId;
use App\Tests\Functional\Identity\LogsInUsers;
use App\Tests\Functional\Web\CreatesAcmeFoodsTeams;
use App\Tests\Functional\Web\CreatesTeams;
use App\Tests\Functional\Web\IssuesCsrfTokens;
use App\Ticketing\Infrastructure\Scheduler\AddTicketEventPartitions;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;

/**
 * Auto-clear on the thread's actions (FR-PNG-05, P04.T6): a comment, a reply, an answer and a question clear the
 * author's open pings on that ticket, mark their bell rows done, and say how many in the toast. Each test seeds pings
 * the action must leave alone: one from Mia to Lena on SHOP-1, one to Omar on SHOP-2, and one to Omar already cleared by
 * Done. The clock is fixed on 6 Nov 2026, before today.
 */
final class ThreadClearsPingsTest extends WebTestCase
{
    use CreatesAcmeFoodsTeams;
    use CreatesTeams;
    use Factories;
    use IssuesCsrfTokens;
    use LogsInUsers {
        tearDown as private removeUsersAndOrganizations;
    }

    private const string NOW = '2026-11-06 12:00:00 UTC';
    private const string KEY = 'SHOP-1';
    private const string TICKET_PAGE = '/t/SHOP-1';
    private const string THREAD_PAGE = '/t/SHOP-1/thread';
    private const array TURBO_FORM_HEADERS = ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html, text/html, application/xhtml+xml'];
    private const string TOAST_REGION_ID = 'toast-region';
    private const string BELL_BADGE = 'header .bell__trigger .bell__badge';

    private KernelBrowser $client;

    /** @var array<string, User> */
    private array $people;

    private string $shopProject;

    private string $stepId;

    /** @var array<string, string> ticket id by key */
    private array $tickets = [];

    /** @var array<string, array{ping: string, notification: string}> the seeded ping's and its bell row's ids, by what it is */
    private array $pings = [];

    protected function setUp(): void
    {
        $this->client = self::createClient([], ['HTTPS' => 'on']);
        $this->client->disableReboot();
        $clock = new MockClock(new \DateTimeImmutable(self::NOW));
        self::getContainer()->set('clock', $clock);
        new AddTicketEventPartitions($this->migrator(), $clock)();
        $this->people = $this->createAcmeFoodsPeople();
        $this->shopProject = $this->addProject($this->shop, 'SHOP', 'Shop');
        $this->stepId = Uuid::v7()->toRfc4122();
        $organizationId = $this->acmeFoods->toString();
        $stepId = $this->stepId;
        $this->committedAs($this->acmeFoods, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            "INSERT INTO wf_step (organization_id, id, team_id, name, grp, core_code, origin, position) VALUES (?, ?, NULL, 'To do', 'todo', 'todo', 'core', 1)",
            [$organizationId, $stepId],
        ));
        $this->addTicket(1);
        $this->addTicket(2);
        $this->seedPings();
    }

    protected function tearDown(): void
    {
        foreach ($this->createdOrganizationIds as $organizationId) {
            $this->committedAs(new OrganizationId($organizationId), static function (Connection $migrator) use ($organizationId): int {
                // ntf_ping and ntf_notification go with their tickets (ON DELETE CASCADE).
                foreach (['cnv_reply', 'cnv_message', 'tkt_ticket_event', 'ins_ticket_turn', 'ins_project_count_ticket', 'ins_project_counts', 'tkt_ticket', 'wf_step', 'wf_rule_set'] as $table) {
                    $migrator->executeStatement(\sprintf('DELETE FROM %s WHERE organization_id = ?', $table), [$organizationId]);
                }
                $migrator->executeStatement("DELETE FROM messenger_outbox WHERE queue_name = 'outbox' AND position(? in body) > 0", [$organizationId]);

                return 0;
            });
        }
        $this->removeCommittedProjects();
        $this->removeUsersAndOrganizations();
    }

    #[Test]
    public function omarsCommentClearsHisTwoPingsOnTheTicketAndTheToastSaysHowMany(): void
    {
        $this->logIn($this->client, $this->people['Omar']);
        self::assertSame('3', $this->bellBadgeOnTheTicketPage());

        $this->client->request('POST', '/t/SHOP-1/comments', $this->formFields('Fixed in build 43.'));

        self::assertSame('Comment added. 2 pings on this ticket cleared.', $this->toastAfterTheRedirectTo(self::THREAD_PAGE));
        self::assertSame(1, $this->rowCountOf('cnv_message'));
        $this->assertOmarsPingsOnTheTicketAreClearedAndTheOthersOpen();
        self::assertSame('1', $this->bellBadgeOnTheTicketPage());
    }

    #[Test]
    public function omarsReplyClearsHisPingsOnTheTicketAndTheStreamedToastSaysHowMany(): void
    {
        $lenasComment = $this->addMessage('Lena', 'comment', null);
        $this->logIn($this->client, $this->people['Omar']);

        $this->client->request('POST', '/t/SHOP-1/messages/'.$lenasComment.'/replies', $this->formFields('Seen on build 42 too.'), [], self::TURBO_FORM_HEADERS);

        self::assertSame('Reply added. 2 pings on this ticket cleared.', $this->toastInTheStream(200, false));
        self::assertSame(1, $this->rowCountOf('cnv_reply'));
        $this->assertOmarsPingsOnTheTicketAreClearedAndTheOthersOpen();
    }

    #[Test]
    public function omarsAnswerClearsHisPingsOnTheTicketAndTheToastSaysHowMany(): void
    {
        $questionToOmar = $this->addMessage('Lena', 'question', 'Omar');
        $this->logIn($this->client, $this->people['Omar']);

        $this->client->request('POST', '/t/SHOP-1/messages/'.$questionToOmar.'/answer', $this->formFields('Build 42, on the cart page.'));

        self::assertSame('Answered. The ticket goes back to To do. 2 pings on this ticket cleared.', $this->toastAfterTheRedirectTo(self::TICKET_PAGE));
        self::assertSame(1, $this->rowCountOf('cnv_reply'));
        $this->assertOmarsPingsOnTheTicketAreClearedAndTheOthersOpen();
    }

    /**
     * FR-PNG-05 clears on any action on the ticket, and asking someone else is one.
     */
    #[Test]
    public function omarsQuestionClearsHisPingsOnTheTicketAndTheToastSaysHowMany(): void
    {
        $this->logIn($this->client, $this->people['Omar']);

        $this->client->request('POST', '/t/SHOP-1/questions', [
            ...$this->formFields('Which build shows the wrong sum?'),
            ThreadController::ASKED_USER_FIELD => $this->people['Lena']->id()->toString(),
        ]);

        self::assertSame('Question sent. Lena gets one notification. Ticket paused. 2 pings on this ticket cleared.', $this->toastAfterTheRedirectTo(self::TICKET_PAGE));
        self::assertSame(1, $this->rowCountOf('cnv_message'));
        $this->assertOmarsPingsOnTheTicketAreClearedAndTheOthersOpen();
    }

    #[Test]
    public function withAutoClearOffACommentClearsNothingAndTheToastHasNoCount(): void
    {
        $this->storeAutoClear(false);
        $pingsBefore = $this->stateOfPings();
        $this->logIn($this->client, $this->people['Omar']);

        $this->client->request('POST', '/t/SHOP-1/comments', $this->formFields('Fixed in build 43.'));

        self::assertSame(ThreadController::COMMENT_ADDED, $this->toastAfterTheRedirectTo(self::THREAD_PAGE));
        self::assertSame(1, $this->rowCountOf('cnv_message'));
        self::assertSame(['cleared' => null, 'done' => false], $pingsBefore['Mia to Omar on SHOP-1, older']);
        self::assertSame($pingsBefore, $this->stateOfPings());
        self::assertSame(0, $this->pingClearedEventsInTheOutbox());
        self::assertSame('3', $this->bellBadgeOnTheTicketPage());
    }

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function refusedPosts(): iterable
    {
        yield 'a blank comment' => ['comment', 'A message needs a body.', false];
        yield 'a reply to a removed comment' => ['reply to removed', 'This message was removed, so you cannot reply to it.', true];
    }

    #[Test]
    #[DataProvider('refusedPosts')]
    public function aRefusedPostClearsNothing(string $post, string $expectedToast, bool $isToastOnly): void
    {
        $removedComment = $this->addMessage('Lena', 'comment', null, true);
        $pingsBefore = $this->stateOfPings();
        $this->logIn($this->client, $this->people['Omar']);

        if ('comment' === $post) {
            $this->client->request('POST', '/t/SHOP-1/comments', $this->formFields(" \n\t "), [], self::TURBO_FORM_HEADERS);
        } else {
            $this->client->request('POST', '/t/SHOP-1/messages/'.$removedComment.'/replies', $this->formFields('Thanks.'), [], self::TURBO_FORM_HEADERS);
        }

        self::assertSame($expectedToast, $this->toastInTheStream(422, $isToastOnly));
        self::assertSame([1, 0], [$this->rowCountOf('cnv_message'), $this->rowCountOf('cnv_reply')]);
        self::assertSame(['cleared' => null, 'done' => false], $pingsBefore['Mia to Omar on SHOP-1, older']);
        self::assertSame($pingsBefore, $this->stateOfPings());
        self::assertSame(0, $this->pingClearedEventsInTheOutbox());
    }

    private function assertOmarsPingsOnTheTicketAreClearedAndTheOthersOpen(): void
    {
        self::assertSame(
            [
                'Mia to Omar on SHOP-1, already done' => ['cleared' => 'done', 'done' => true],
                'Mia to Omar on SHOP-1, older' => ['cleared' => 'acted', 'done' => true],
                'Mia to Omar on SHOP-1, newer' => ['cleared' => 'acted', 'done' => true],
                'Mia to Lena on SHOP-1' => ['cleared' => null, 'done' => false],
                'Mia to Omar on SHOP-2' => ['cleared' => null, 'done' => false],
            ],
            $this->stateOfPings(),
        );
        self::assertSame(2, $this->pingClearedEventsInTheOutbox());
    }

    /**
     * Two open pings from Mia to Omar on SHOP-1, older first, one more already cleared by Done, one to Lena on SHOP-1
     * and one to Omar on SHOP-2, each with its bell row.
     */
    private function seedPings(): void
    {
        $shop1 = $this->tickets['SHOP-1'];
        $shop2 = $this->tickets['SHOP-2'];
        $this->addPing('Mia to Omar on SHOP-1, already done', 'Omar', $shop1, 90, true);
        $this->addPing('Mia to Omar on SHOP-1, older', 'Omar', $shop1, 50, false);
        $this->addPing('Mia to Omar on SHOP-1, newer', 'Omar', $shop1, 20, false);
        $this->addPing('Mia to Lena on SHOP-1', 'Lena', $shop1, 30, false);
        $this->addPing('Mia to Omar on SHOP-2', 'Omar', $shop2, 10, false);
    }

    private function addPing(string $name, string $recipient, string $ticketId, int $minutesAgo, bool $isDone): void
    {
        $organizationId = $this->acmeFoods->toString();
        $createdAt = new \DateTimeImmutable(self::NOW)->modify(\sprintf('-%d minutes', $minutesAgo))->format('Y-m-d H:i:sP');
        $doneAt = $isDone ? new \DateTimeImmutable(self::NOW)->modify('-1 minute')->format('Y-m-d H:i:sP') : null;
        $ids = ['ping' => Uuid::v7()->toRfc4122(), 'notification' => Uuid::v7()->toRfc4122()];
        $ping = [
            'organization_id' => $organizationId,
            'id' => $ids['ping'],
            'ticket_id' => $ticketId,
            'from_id' => $this->people['Mia']->id()->toString(),
            'to_id' => $this->people[$recipient]->id()->toString(),
            'body' => 'Any news on this one?',
            'created_at' => $createdAt,
            'cleared_at' => $doneAt,
            'cleared_reason' => $isDone ? 'done' : null,
        ];
        $notification = [
            'organization_id' => $organizationId,
            'id' => $ids['notification'],
            'user_id' => $this->people[$recipient]->id()->toString(),
            'kind' => 'ping',
            'actor_id' => $this->people['Mia']->id()->toString(),
            'ticket_id' => $ticketId,
            'data' => json_encode(['actorName' => 'Mia', 'ticketKey' => array_search($ticketId, $this->tickets, true), 'pingId' => $ids['ping']], \JSON_THROW_ON_ERROR),
            'done_at' => $doneAt,
            'created_at' => $createdAt,
        ];
        $this->committedAs($this->acmeFoods, static function (Connection $migrator) use ($ping, $notification): int|string {
            $migrator->insert('ntf_ping', $ping);

            return $migrator->insert('ntf_notification', $notification);
        });
        $this->pings[$name] = $ids;
    }

    /**
     * As an admin saving the settings page would; the other settings stay at their defaults.
     */
    private function storeAutoClear(bool $isAutoClearOn): void
    {
        $this->committedAs($this->acmeFoods, fn (Connection $migrator): int|string => $migrator->executeStatement(
            "INSERT INTO org_settings (organization_id, data, created_at) VALUES (?, jsonb_build_object('auto_clear', ?::boolean), TIMESTAMPTZ '2026-11-01 08:00:00+00')
             ON CONFLICT (organization_id) DO UPDATE SET data = org_settings.data || EXCLUDED.data",
            [$this->acmeFoods->toString(), $isAutoClearOn ? 'true' : 'false'],
        ));
    }

    /**
     * @return array<string, array{cleared: string|null, done: bool}> each seeded ping's cleared reason and whether its bell
     *                                                                row is done, by what it is
     */
    private function stateOfPings(): array
    {
        self::assertNotSame([], $this->pings);
        $organizationId = $this->acmeFoods->toString();
        $rows = [];
        $this->committedAs($this->acmeFoods, static function (Connection $migrator) use ($organizationId, &$rows): int {
            $rows = $migrator->fetchAllAssociativeIndexed(
                'SELECT p.id, p.cleared_reason, n.done_at IS NOT NULL AS done
                 FROM ntf_ping p JOIN ntf_notification n ON n.organization_id = p.organization_id AND n.data->>\'pingId\' = p.id::text
                 WHERE p.organization_id = ?',
                [$organizationId],
            );

            return 0;
        });
        self::assertCount(\count($this->pings), $rows);
        $states = [];
        foreach ($this->pings as $name => $ids) {
            self::assertArrayHasKey($ids['ping'], $rows);
            $cleared = $rows[$ids['ping']]['cleared_reason'];
            self::assertTrue(null === $cleared || \is_string($cleared));
            $states[$name] = ['cleared' => $cleared, 'done' => true === $rows[$ids['ping']]['done']];
        }

        return $states;
    }

    private function pingClearedEventsInTheOutbox(): int
    {
        $organizationId = $this->acmeFoods->toString();
        $count = 0;
        $this->committedAs($this->acmeFoods, static function (Connection $migrator) use ($organizationId, &$count): int {
            $count = $migrator->fetchOne(
                "SELECT count(*) FROM messenger_outbox WHERE queue_name = 'outbox' AND position(? in body) > 0 AND position('PingCleared' in body) > 0",
                [$organizationId],
            );

            return 0;
        });
        self::assertIsInt($count);

        return $count;
    }

    /**
     * @param 'cnv_message'|'cnv_reply' $table
     */
    private function rowCountOf(string $table): int
    {
        $organizationId = $this->acmeFoods->toString();
        $count = 0;
        $this->committedAs($this->acmeFoods, static function (Connection $migrator) use ($table, $organizationId, &$count): int {
            $count = $migrator->fetchOne(\sprintf('SELECT count(*) FROM %s WHERE organization_id = ?', $table), [$organizationId]);

            return 0;
        });
        self::assertIsInt($count);

        return $count;
    }

    private function bellBadgeOnTheTicketPage(): string
    {
        $page = $this->client->request('GET', self::TICKET_PAGE);
        self::assertResponseStatusCodeSame(200);

        return $page->filter(self::BELL_BADGE)->text();
    }

    /**
     * @return array<string, string>
     */
    private function formFields(string $body): array
    {
        return [
            ThreadController::CSRF_FIELD => $this->csrfTokenFor($this->client, ThreadController::csrfIdOf(self::KEY)),
            ThreadController::BODY_FIELD => $body,
        ];
    }

    /**
     * Asserts the answer is a 303 to $page and returns the one toast that page shows.
     */
    private function toastAfterTheRedirectTo(string $page): string
    {
        self::assertResponseStatusCodeSame(303);
        self::assertSame($page, $this->client->getResponse()->headers->get('Location'));
        $crawler = $this->client->followRedirect();
        self::assertResponseStatusCodeSame(200);
        $toast = $crawler->filter('.toast-region [data-toast-message-value]');
        self::assertCount(1, $toast);

        return (string) $toast->attr('data-toast-message-value');
    }

    /**
     * Asserts the answer is a Turbo Stream of the status that replaces the message list and updates the toast region,
     * or, for a toast-only answer, only updates the toast region, and returns its toast.
     */
    private function toastInTheStream(int $expectedStatus, bool $isToastOnly): string
    {
        self::assertResponseStatusCodeSame($expectedStatus);
        self::assertSame('text/vnd.turbo-stream.html; charset=UTF-8', $this->client->getResponse()->headers->get('Content-Type'));
        preg_match_all(
            '#<turbo-stream action="([a-z]+)" target="([a-z-]+)"><template>(.*?)</template></turbo-stream>#s',
            (string) $this->client->getResponse()->getContent(),
            $matches,
            \PREG_SET_ORDER,
        );
        self::assertSame(
            $isToastOnly ? [['update', self::TOAST_REGION_ID]] : [['replace', Thread::MESSAGES_ID], ['update', self::TOAST_REGION_ID]],
            array_map(static fn (array $match): array => [$match[1], $match[2]], $matches),
        );
        $toastMatch = end($matches);
        self::assertNotFalse($toastMatch);
        $toastStream = new Crawler('<!DOCTYPE html><html><body>'.$toastMatch[3].'</body></html>');
        $toast = $toastStream->filter('[data-toast-message-value]');
        self::assertCount(1, $toast);

        return (string) $toast->attr('data-toast-message-value');
    }

    /**
     * A bug of Shop's team for everyone, SHOP-$number, reported by Lena and assigned to Omar.
     */
    private function addTicket(int $number): void
    {
        $key = 'SHOP-'.$number;
        $this->tickets[$key] = Uuid::v7()->toRfc4122();
        $parameters = [
            $this->acmeFoods->toString(), $this->tickets[$key], $this->shopProject, $this->shop->toString(), $number, $key, $this->stepId,
            $this->people['Lena']->id()->toString(), $this->people['Omar']->id()->toString(),
        ];
        $this->committedAs($this->acmeFoods, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            "INSERT INTO tkt_ticket (organization_id, id, project_id, team_id, number, key, title, description, type, priority, priority_rank,
                 step_id, reporter_id, assignee_id, visibility, version, components, check_reasons, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, 'Cart total shows the wrong sum', '', 'bug', 'major', 2, ?, ?, ?, 'all', 3, '{}', '{}',
                 TIMESTAMPTZ '2026-11-04 10:00:00+00', TIMESTAMPTZ '2026-11-05 10:00:00+00')",
            $parameters,
        ));
    }

    /**
     * A comment, or a question to $askedPerson with SHOP-1 paused on it as TicketPauses leaves it, by $author a minute
     * before now on SHOP-1.
     *
     * @return string its id
     */
    private function addMessage(string $author, string $kind, ?string $askedPerson, bool $isRemoved = false): string
    {
        $messageId = Uuid::v7()->toRfc4122();
        $organizationId = $this->acmeFoods->toString();
        $authorId = $this->people[$author]->id()->toString();
        $askedUserId = null === $askedPerson ? null : $this->people[$askedPerson]->id()->toString();
        $parameters = [$organizationId, $messageId, $this->tickets[self::KEY], $authorId, $kind, $askedUserId, 'Which build shows the wrong sum?', $isRemoved ? '2026-11-06 11:59:30+00' : null];
        $this->committedAs($this->acmeFoods, static function (Connection $migrator) use ($parameters, $organizationId, $authorId, $askedUserId): int {
            $migrator->executeStatement(
                "INSERT INTO cnv_message (organization_id, id, ticket_id, author_id, kind, to_user_id, body, removed_at, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, TIMESTAMPTZ '2026-11-06 11:59:00+00')",
                $parameters,
            );
            if (null === $askedUserId) {
                return 0;
            }

            return (int) $migrator->executeStatement(
                "UPDATE tkt_ticket SET asked_user_id = ?, asker_id = ? WHERE organization_id = ? AND key = 'SHOP-1'",
                [$askedUserId, $authorId, $organizationId],
            );
        });

        return $messageId;
    }
}
