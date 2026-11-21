<?php

declare(strict_types=1);

namespace App\Tests\Functional\Conversation;

use App\Conversation\UI\Component\Thread;
use App\Conversation\UI\Component\ThreadFrame;
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
 * The ticket page's Questions and comments, its GET /t/{key}/thread, and its POST /t/{key}/comments,
 * /t/{key}/messages/{id}/edit and /t/{key}/messages/{id}/remove, on Acme Foods' SHOP-1 through the real firewall, voter, session and database. The
 * clock is fixed on 6 Nov 2026, before today: a clock ahead of the login would end the session as idle. Every refusal
 * also proves the thread and the outbox unchanged.
 */
final class TicketThreadTest extends WebTestCase
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
    private const string OTHER_KEY = 'SHOP-2';
    private const string OTHER_TICKETS_COMMENT_ID = '0197a3c0-0000-7000-8000-0000000000c2';
    private const string TICKET_PAGE = '/t/SHOP-1';
    private const string THREAD_PAGE = '/t/SHOP-1/thread';
    private const string COMMENT_PATH = '/t/SHOP-1/comments';
    /** What Turbo sends when it loads the thread's frame. */
    private const array TURBO_FRAME_HEADERS = ['HTTP_TURBO_FRAME' => Thread::FRAME_ID];
    /** What Turbo sends with a form it posts. */
    private const array TURBO_FORM_HEADERS = ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html, text/html, application/xhtml+xml'];
    private const string TOAST_REGION_ID = 'toast-region';
    private const string SCRIPT_BODY = "<script>alert('x')</script>\nSee https://acme.test/build for the build";
    private const string EARLIER_BODY = 'The cart total is right again';
    private const string EDIT_WINDOW_PASSED = 'You can edit a message for 15 minutes after you post it.';

    private KernelBrowser $client;

    /** @var array<string, User> */
    private array $people;

    private string $shopProject;

    private string $stepId;

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
    }

    protected function tearDown(): void
    {
        foreach ($this->createdOrganizationIds as $organizationId) {
            $this->committedAs(new OrganizationId($organizationId), static function (Connection $migrator) use ($organizationId): int {
                foreach (['cnv_message', 'tkt_ticket_event', 'ins_ticket_turn', 'ins_project_count_ticket', 'ins_project_counts', 'tkt_ticket', 'wf_step', 'wf_rule_set'] as $table) {
                    $migrator->executeStatement(\sprintf('DELETE FROM %s WHERE organization_id = ?', $table), [$organizationId]);
                }
                $migrator->executeStatement("DELETE FROM messenger_outbox WHERE queue_name = 'outbox' AND position(? in body) > 0", [$organizationId]);

                return 0;
            });
        }
        $this->removeCommittedProjects();
        $this->removeUsersAndOrganizations();
    }

    /**
     * The page holds the section's heading and a lazy frame with a link for when JavaScript is off, and reads nothing
     * of the thread: no query touches cnv_message, while the frame's request reads it in one.
     */
    #[Test]
    public function theTicketPageHoldsTheThreadAsALazyFrameAndReadsNoneOfIt(): void
    {
        $this->addTicket();
        $this->addMessage('Raj', self::EARLIER_BODY, '2026-11-06 11:59:00+00');
        $this->logIn($this->client, $this->people['Mia']);

        $pageQueries = $this->queriesOf(self::TICKET_PAGE);

        $page = $this->client->getCrawler();
        $section = $page->filter('section[aria-labelledby="ticket-thread-heading"]');
        self::assertSame('Questions and comments', trim($section->filter('h2#ticket-thread-heading')->text()));
        $frame = $section->filter('turbo-frame#'.Thread::FRAME_ID);
        self::assertSame(self::THREAD_PAGE, $frame->attr('src'));
        self::assertSame('lazy', $frame->attr('loading'));
        self::assertSame([[ThreadFrame::SHOW_THREAD, self::THREAD_PAGE]], $frame->filter('a')->each(static fn (Crawler $link): array => [trim($link->text()), (string) $link->attr('href')]));
        self::assertStringNotContainsString(self::EARLIER_BODY, (string) $this->client->getResponse()->getContent());
        self::assertCount(1, $page->filter('link[rel="stylesheet"][href*="/styles/thread"]'));
        self::assertSame([], self::threadQueriesAmong($pageQueries));
        self::assertCount(1, self::threadQueriesAmong($this->queriesOf(self::THREAD_PAGE, self::TURBO_FRAME_HEADERS)));
    }

    #[Test]
    public function theFrameRequestGetsTheBareFrameWithTheEmptyThreadAndTheComposer(): void
    {
        $this->addTicket();
        $this->logIn($this->client, $this->people['Mia']);

        $page = $this->client->request('GET', self::THREAD_PAGE, [], [], self::TURBO_FRAME_HEADERS);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['Turbo-Frame'], $this->client->getResponse()->getVary());
        self::assertCount(0, $page->filter('main, header'), 'A frame request gets no layout.');
        $thread = $page->filter('turbo-frame#'.Thread::FRAME_ID);
        self::assertCount(1, $thread);
        self::assertStringContainsString(Thread::EMPTY_THREAD, $thread->filter('#'.Thread::MESSAGES_ID)->text());
        self::assertSame(self::COMMENT_PATH, $thread->filter('form.thread__composer')->attr('action'));
        self::assertSame(
            ['thread-composer-'.self::KEY, ''],
            [$thread->filter('form.thread__composer')->attr('id'), $thread->filter('form.thread__composer')->attr('data-turbo-permanent')],
            'A live reload of the frame keeps the draft, and the id names the ticket so no other ticket\'s page keeps this form.',
        );
        self::assertSame('Write a comment', $thread->filter('form.thread__composer textarea')->attr('placeholder'));
        self::assertStringContainsString('A comment does not change the status and sends no notification.', $thread->text());
        self::assertSame(
            [['Comment', 'true', false], ['Ask a question', 'false', true]],
            $thread->filter('.thread__kinds button.chip[type="button"]')->each(static fn (Crawler $chip): array => [trim($chip->text()), $chip->attr('aria-pressed'), null !== $chip->attr('disabled')]),
        );
        self::assertSame('thread-composer ask-choices', $thread->filter('.thread__content')->attr('data-controller'));
        self::assertSame(
            'turbo:submit-start->thread-composer#rememberMessage turbo:submit-end->thread-composer#settleComposer',
            $thread->filter('.thread__content')->attr('data-action'),
            'Focus goes back to the composer or the changed message after a thread form.',
        );
        self::assertCount(1, $thread->filter('.thread__content #'.Thread::MESSAGES_ID.'[data-thread-composer-target="messages"][data-ask-choices-target="messages"]'));
        self::assertCount(1, $thread->filter('form.thread__composer .thread__ask-choices[data-ask-choices-target="choices"]'), 'ask-choices replaces the composer\'s people after a live reload.');
        self::assertNotNull($thread->filter('form.thread__composer textarea[data-thread-composer-target="body"]')->attr('required'));
        self::assertSame('Comment', trim($thread->filter('form.thread__composer button[data-thread-composer-target="submit"]')->text()));
    }

    /**
     * P14.T7: thread.css folds an unused composer to one line, its kinds beside a one-line field, until it holds focus
     * or text. It keys on the field's placeholder (:placeholder-shown matches only a field with one) and on the field,
     * the note and the button being the form's own children; the note and the button stay in the markup, so a screen
     * reader reads them and Tab reaches the button, which opens the composer.
     */
    #[Test]
    public function theComposersFieldNoteAndButtonAreTheFormsOwnPartsAndTheFieldIsLabelledWithAPlaceholder(): void
    {
        $this->addTicket();
        $this->logIn($this->client, $this->people['Mia']);

        $page = $this->client->request('GET', self::THREAD_PAGE, [], [], self::TURBO_FRAME_HEADERS);

        self::assertResponseStatusCodeSame(200);
        $composer = $page->filter('turbo-frame#'.Thread::FRAME_ID.' form.thread__composer');
        self::assertCount(1, $composer);
        self::assertSame(
            ['input', 'div.thread__kinds', 'div.thread__ask-choices', 'label.visually-hidden', 'textarea.text-field thread__composer-body', 'p.thread__note thread__composer-note', 'button.btn btn-filled thread__composer-send'],
            $composer->children()->each(static fn (Crawler $part): string => $part->nodeName().(null === $part->attr('class') ? '' : '.'.$part->attr('class'))),
        );
        $body = $composer->filter('textarea.thread__composer-body');
        self::assertSame('thread-composer-body', $body->attr('id'));
        self::assertSame('Write a comment', $composer->filter('label[for="thread-composer-body"]')->text());
        self::assertSame(Thread::COMMENT_PLACEHOLDER, $body->attr('placeholder'));
        self::assertSame('3', $body->attr('rows'), 'Opened, the composer is three lines, as before it folded.');
        self::assertSame('', $body->text(), 'An empty composer shows its placeholder, so it starts folded.');
        self::assertSame(Thread::COMMENT_NOTE, $composer->filter('.thread__composer-note')->text());
        self::assertSame(Thread::COMMENT_BUTTON, trim($composer->filter('button.thread__composer-send')->text()));
        self::assertSame('submit', $composer->filter('button.thread__composer-send')->attr('type'));
    }

    /**
     * Without JavaScript the frame's link opens the thread as a page of its own, with a way back to the ticket.
     */
    #[Test]
    public function aRequestOutsideAFrameGetsTheThreadAsAPageOfItsOwn(): void
    {
        $this->addTicket();
        $this->addMessage('Omar', self::EARLIER_BODY, '2026-11-06 11:59:00+00');
        $this->logIn($this->client, $this->people['Mia']);

        $page = $this->client->request('GET', self::THREAD_PAGE);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['Turbo-Frame'], $this->client->getResponse()->getVary());
        self::assertSame('Questions and comments', trim($page->filter('main h1')->text()));
        self::assertSame(self::TICKET_PAGE, $page->filter('main a.ticket__back')->attr('href'));
        self::assertSame([self::EARLIER_BODY], $page->filter('main turbo-frame#'.Thread::FRAME_ID.' .thread__body')->each(static fn (Crawler $body): string => trim($body->text())));
        self::assertCount(1, $page->filter('main form.thread__composer'));
        self::assertCount(1, $page->filter('link[rel="stylesheet"][href*="/styles/thread"]'));
    }

    #[Test]
    #[DataProvider('peopleWhoMayNotSeeTheTicket')]
    public function someoneWhoMayNotSeeTheTicketGetsNotFoundForTheThreadAndSeesNoneOfIt(string $person, string $visibility): void
    {
        $this->addTicket($visibility);
        $this->addMessage('Omar', self::EARLIER_BODY, '2026-11-06 11:59:00+00');
        $rowsBefore = $this->messageRows();
        $this->addBen();
        $this->logIn($this->client, $this->people[$person]);

        foreach ([[], self::TURBO_FRAME_HEADERS] as $headers) {
            $this->client->request('GET', self::THREAD_PAGE, [], [], $headers);
            self::assertResponseStatusCodeSame(404);
            self::assertStringContainsString(ThreadController::NOT_FOUND, (string) $this->client->getResponse()->getContent());
            self::assertStringNotContainsString(self::EARLIER_BODY, (string) $this->client->getResponse()->getContent());
        }

        self::assertSame($rowsBefore, $this->messageRows());
    }

    /**
     * @return iterable<string, array{string}> a key, as in the URL, of no ticket
     */
    public static function keysOfNoTicket(): iterable
    {
        yield 'a number no ticket has' => ['SHOP-99'];
        yield 'the key in lower case' => ['shop-1'];
        yield 'no number' => ['SHOP-'];
        yield 'a trailing newline' => ['SHOP-1%0A'];
        yield 'a number past the integer range' => ['SHOP-99999999999999999999'];
    }

    #[Test]
    #[DataProvider('keysOfNoTicket')]
    public function aKeyOfNoTicketGetsNotFoundForTheThread(string $key): void
    {
        $this->addTicket();
        $this->addMessage('Omar', self::EARLIER_BODY, '2026-11-06 11:59:00+00');
        $this->logIn($this->client, $this->people['Omar']);

        $this->client->request('GET', '/t/'.$key.'/thread', [], [], self::TURBO_FRAME_HEADERS);

        self::assertResponseStatusCodeSame(404);
        self::assertStringContainsString(ThreadController::NOT_FOUND, (string) $this->client->getResponse()->getContent());
        self::assertStringNotContainsString(self::EARLIER_BODY, (string) $this->client->getResponse()->getContent());
    }

    #[Test]
    public function anAnonymousVisitorAskingForTheThreadIsSentToLogIn(): void
    {
        $this->addTicket();
        $this->addMessage('Omar', self::EARLIER_BODY, '2026-11-06 11:59:00+00');

        $this->client->request('GET', self::THREAD_PAGE, [], [], self::TURBO_FRAME_HEADERS);

        self::assertResponseStatusCodeSame(302);
        self::assertStringEndsWith('/login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    /**
     * Lena reported SHOP-1 and Omar has it, so the page knows their names; Raj and Sara, outside the ticket's people,
     * cost one query for both, however many messages they wrote.
     */
    #[Test]
    public function theFrameNamesAuthorsOutsideTheTicketsPeopleInOneQuery(): void
    {
        $this->addTicket();
        $this->addMessage('Omar', 'Looking at it', '2026-11-06 09:00:00+00');
        $this->addMessage('Lena', 'Thanks', '2026-11-06 09:05:00+00');
        $this->logIn($this->client, $this->people['Mia']);
        // logIn() leaves the user in the entity manager, and the first request fills the caches.
        $this->queriesOf(self::THREAD_PAGE, self::TURBO_FRAME_HEADERS);
        $this->queriesOf(self::THREAD_PAGE, self::TURBO_FRAME_HEADERS);
        $queryCountWithTheTicketsPeople = \count($this->queriesOf(self::THREAD_PAGE, self::TURBO_FRAME_HEADERS));
        $this->addMessage('Raj', 'Is this in 2.4?', '2026-11-06 10:00:00+00');
        $this->addMessage('Sara', 'Yes, it is', '2026-11-06 10:05:00+00');
        $this->addMessage('Raj', 'Good', '2026-11-06 10:10:00+00');

        $queryCountWithOutsideAuthors = \count($this->queriesOf(self::THREAD_PAGE, self::TURBO_FRAME_HEADERS));

        self::assertSame(
            ['Omar', 'Lena', 'Raj', 'Sara', 'Raj'],
            $this->client->getCrawler()->filter('.thread__author')->each(static fn (Crawler $author): string => trim($author->text())),
        );
        self::assertSame($queryCountWithTheTicketsPeople + 1, $queryCountWithOutsideAuthors);
    }

    /**
     * Raj, Sara and Mia are outside the ticket's people, asked and answering: the replies of every message, answers
     * included, cost one query already read for the comments, and the names of everyone the questions bring one more,
     * however many questions there are.
     */
    #[Test]
    public function theFrameReadsAnswersInTheRepliesQueryAndNamesAnsweringAndAskedPeopleOutsideTheTicketsPeopleInOneMore(): void
    {
        $this->addTicket();
        $this->addMessage('Omar', 'Looking at it', '2026-11-06 09:00:00+00');
        $this->addMessage('Lena', 'Thanks', '2026-11-06 09:05:00+00');
        $this->logIn($this->client, $this->people['Mia']);
        // logIn() leaves the user in the entity manager, and the first request fills the caches.
        $this->queriesOf(self::THREAD_PAGE, self::TURBO_FRAME_HEADERS);
        $this->queriesOf(self::THREAD_PAGE, self::TURBO_FRAME_HEADERS);
        $queryCountWithTheTicketsPeople = \count($this->queriesOf(self::THREAD_PAGE, self::TURBO_FRAME_HEADERS));
        $this->addAnsweredQuestion('Omar', 'Raj', '2026-11-06 10:00:00+00');
        $this->addAnsweredQuestion('Lena', 'Sara', '2026-11-06 10:10:00+00');
        $this->addAnsweredQuestion('Omar', 'Mia', '2026-11-06 10:20:00+00');

        $queries = $this->queriesOf(self::THREAD_PAGE, self::TURBO_FRAME_HEADERS);

        $frame = $this->client->getCrawler();
        $metaLines = $frame->filter('.thread__message-main > .thread__meta')->each(static fn (Crawler $meta): string => (string) preg_replace('/\s+/', ' ', trim($meta->text())));
        self::assertSame(
            ['Omar · 2 h ago · question to Raj', 'Lena · 1 h ago · question to Sara', 'Omar · 1 h ago · question to Mia'],
            array_values(array_filter($metaLines, static fn (string $metaLine): bool => str_contains($metaLine, 'question to'))),
        );
        self::assertSame(
            ['Raj', 'Sara', 'Mia'],
            $frame->filter('.thread__reply--answer .thread__author')->each(static fn (Crawler $author): string => trim($author->text())),
        );
        self::assertCount(1, array_filter($queries, static fn (string $query): bool => str_contains($query, 'cnv_reply')));
        self::assertSame($queryCountWithTheTicketsPeople + 1, \count($queries));
    }

    /**
     * @return iterable<string, array{string, int}> a viewer MessageVoter grants Resolve on some comments, and how many
     *                                              of the eight comments it grants
     */
    public static function viewersWhoMayResolve(): iterable
    {
        yield 'Omar, the author of his four' => ['Omar', 4];
        yield 'Lena, the lead, of all eight' => ['Lena', 8];
    }

    /**
     * Whether Resolve is offered is asked once for the thread, not per message, and every message's replies come in the
     * one replies query: more comments and replies by the same people cost no query.
     */
    #[Test]
    #[DataProvider('viewersWhoMayResolve')]
    public function moreCommentsAndRepliesByTheSamePeopleCostNoQuery(string $viewer, int $expectedResolveCount): void
    {
        $this->addTicket();
        $this->addMessage('Omar', 'Looking at it', '2026-11-06 09:00:00+00');
        $this->addMessage('Lena', 'Thanks', '2026-11-06 09:05:00+00');
        $this->logIn($this->client, $this->people[$viewer]);
        // logIn() leaves the user in the entity manager, and the first request fills the caches.
        $this->queriesOf(self::THREAD_PAGE, self::TURBO_FRAME_HEADERS);
        $this->queriesOf(self::THREAD_PAGE, self::TURBO_FRAME_HEADERS);
        $queryCountWithTwoComments = \count($this->queriesOf(self::THREAD_PAGE, self::TURBO_FRAME_HEADERS));
        foreach (['10:00', '10:05', '10:10'] as $time) {
            $omarsCommentId = $this->addMessage('Omar', 'Still on it', '2026-11-06 '.$time.':00+00');
            $this->addReply($omarsCommentId, 'Lena', 'Good');
            $leadsCommentId = $this->addMessage('Lena', 'One more thing', '2026-11-06 '.$time.':30+00');
            $this->addReply($leadsCommentId, 'Omar', 'Noted');
        }

        $queryCountWithMore = \count($this->queriesOf(self::THREAD_PAGE, self::TURBO_FRAME_HEADERS));

        self::assertSame(8, $this->client->getCrawler()->filter('.thread__message')->count());
        self::assertSame(6, $this->client->getCrawler()->filter('.thread__reply')->count());
        self::assertSame($expectedResolveCount, $this->client->getCrawler()->filter('.thread__resolve-form')->count());
        self::assertSame($queryCountWithTwoComments, $queryCountWithMore);
    }

    /**
     * Lena leads the ticket, so she may resolve every comment: authors she has not seen before cost no query, as
     * MessageVoter is asked once for the whole thread, not once per author.
     */
    #[Test]
    public function commentsByMoreAuthorsCostTheLeadNoQuery(): void
    {
        $this->addTicket();
        $this->addMessage('Omar', 'Looking at it', '2026-11-06 09:00:00+00');
        $this->addMessage('Lena', 'Thanks', '2026-11-06 09:05:00+00');
        $this->addMessage('Raj', 'Any news?', '2026-11-06 09:10:00+00');
        $this->logIn($this->client, $this->people['Lena']);
        // logIn() leaves the user in the entity manager, and the first request fills the caches.
        $this->queriesOf(self::THREAD_PAGE, self::TURBO_FRAME_HEADERS);
        $this->queriesOf(self::THREAD_PAGE, self::TURBO_FRAME_HEADERS);
        $queryCountWithThreeAuthors = \count($this->queriesOf(self::THREAD_PAGE, self::TURBO_FRAME_HEADERS));
        $this->addMessage('Sara', 'Checked the settings', '2026-11-06 10:00:00+00');
        $this->addMessage('Mia', 'Tested on staging', '2026-11-06 10:05:00+00');

        $queryCountWithFiveAuthors = \count($this->queriesOf(self::THREAD_PAGE, self::TURBO_FRAME_HEADERS));

        self::assertResponseStatusCodeSame(200);
        self::assertSame(5, $this->client->getCrawler()->filter('.thread__message')->count());
        self::assertSame(5, $this->client->getCrawler()->filter('.thread__resolve-form')->count());
        self::assertSame($queryCountWithThreeAuthors, $queryCountWithFiveAuthors);
    }

    /**
     * The meta line says how long ago in the words the tickets list uses, the date in Mia's zone from a day on: Lena's
     * 23:30 UTC on 2 Nov is 3 Nov in Berlin. Each message has its author's avatar, hidden from screen readers, which
     * read the name next to it.
     */
    #[Test]
    public function eachMessageShowsItsAuthorsAvatarAndHowLongAgoItWasPosted(): void
    {
        $this->addTicket();
        $this->addMessage('Sara', 'Same as last year', '2025-11-06 10:00:00+00');
        $this->addMessage('Lena', 'Seen again', '2026-11-02 23:30:00+00');
        $this->addMessage('Omar', 'Looking at it', '2026-11-06 09:00:00+00');
        $this->addMessage('Raj', 'Is this in 2.4?', '2026-11-06 11:15:00+00');
        $this->addMessage('Mia', 'Yes', '2026-11-06 11:59:30+00');
        $this->logIn($this->client, $this->people['Mia']);

        $page = $this->client->request('GET', self::THREAD_PAGE, [], [], self::TURBO_FRAME_HEADERS);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(
            [
                ['Sara', '6 Nov 2025', '2025-11-06T10:00:00+00:00'],
                ['Lena', '3 Nov', '2026-11-02T23:30:00+00:00'],
                ['Omar', '3 h ago', '2026-11-06T09:00:00+00:00'],
                ['Raj', '45 min ago', '2026-11-06T11:15:00+00:00'],
                ['Mia', 'now', '2026-11-06T11:59:30+00:00'],
            ],
            $page->filter('.thread__message')->each(static fn (Crawler $message): array => [
                trim($message->filter('.thread__author')->text()),
                trim($message->filter('time.thread__time')->text()),
                $message->filter('time.thread__time')->attr('datetime'),
            ]),
        );
        self::assertSame(
            [['S', 'true'], ['L', 'true'], ['O', 'true'], ['R', 'true'], ['M', 'true']],
            $page->filter('.thread__message > .thread__avatar.av')->each(static fn (Crawler $avatar): array => [trim($avatar->text()), $avatar->attr('aria-hidden')]),
        );
    }

    /**
     * EditMessage and RemoveMessage refuse a question: Omar's own open question shows neither, his comment both.
     */
    #[Test]
    public function theViewersOwnQuestionShowsNoEditOrRemove(): void
    {
        $this->addTicket();
        $this->addMessage('Omar', 'Is this in 2.4?', '2026-11-06 11:58:00+00', kind: 'question');
        $this->addMessage('Omar', 'Looking at it', '2026-11-06 11:59:00+00');
        $this->logIn($this->client, $this->people['Omar']);

        $page = $this->client->request('GET', self::THREAD_PAGE, [], [], self::TURBO_FRAME_HEADERS);

        self::assertResponseStatusCodeSame(200);
        $messages = $page->filter('.thread__message');
        self::assertSame(['question', 'comment'], $messages->each(static fn (Crawler $message): string => str_replace('thread__message thread__message--', '', (string) $message->attr('class'))));
        self::assertSame([], $this->actionsOn($messages->eq(0)));
        self::assertSame(['Edit', 'Remove'], $this->actionsOn($messages->eq(1)));
    }

    /**
     * Mia is only a viewer of Shop's team, and anyone who may see the ticket may comment. The text comes back escaped,
     * its line break as a <br> and its address as a link, and the comment writes one CommentPosted to the outbox.
     */
    #[Test]
    public function aViewerPostsACommentAndThePageShowsItsTextEscapedWithItsAddressLinked(): void
    {
        $this->addTicket();
        $this->logIn($this->client, $this->people['Mia']);

        $this->client->request('POST', self::COMMENT_PATH, $this->formFields(self::SCRIPT_BODY));

        self::assertSame(ThreadController::COMMENT_ADDED, $this->toastAfterTheRedirect());
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString("<script>alert('x')</script>", $html);
        self::assertStringContainsString('&lt;script&gt;alert(&apos;x&apos;)&lt;/script&gt;<br>', $html);
        $message = $this->client->getCrawler()->filter('#'.Thread::FRAME_ID.' .thread__message');
        self::assertCount(1, $message);
        self::assertSame(['https://acme.test/build'], $message->filter('.thread__body a')->each(static fn (Crawler $link): ?string => $link->attr('href')));
        self::assertSame('Mia · now · comment', trim($message->filter('.thread__meta')->text()));
        self::assertSame([[self::SCRIPT_BODY, $this->people['Mia']->id()->toString(), null, null]], $this->messageRows());
        self::assertSame(1, $this->outboxCount());
    }

    #[Test]
    public function aTurboCommentAnswersAStreamOfTheThreadAndTheToast(): void
    {
        $this->addTicket();
        $this->logIn($this->client, $this->people['Omar']);

        $this->client->request('POST', self::COMMENT_PATH, $this->formFields('Fixed on staging'), [], self::TURBO_FORM_HEADERS);

        $streams = $this->streamsOfTheAnswer(200);
        self::assertSame(ThreadController::COMMENT_ADDED, $this->toastOf($streams));
        self::assertSame('Fixed on staging', trim($streams[Thread::MESSAGES_ID]->filter('#'.Thread::MESSAGES_ID.' .thread__message .thread__body')->text()));
        self::assertCount(0, $streams[Thread::MESSAGES_ID]->filter('form.thread__composer'), 'The stream leaves the composer and its focus alone.');
        self::assertCount(1, $this->messageRows());
    }

    /**
     * The message list carries whom the viewer may ask, for thread-composer to copy into the permanent composer after
     * a live reload or a Turbo Stream: the ticket's people in their order (assignee, reporter, reviewer, tester, lead),
     * each once, without the viewer. Lena reports and leads; Omar, the assignee, views; Raj is on no ticket role.
     */
    #[Test]
    public function theMessageListCarriesTheTicketsPeopleOnceWithoutTheViewer(): void
    {
        $this->addTicket();
        $this->setReviewerAndTester('Sara', 'Mia');
        $this->logIn($this->client, $this->people['Omar']);
        $expected = array_map(fn (string $name): array => [$name, $this->people[$name]->id()->toString()], ['Lena', 'Sara', 'Mia']);

        $frame = $this->client->request('GET', self::THREAD_PAGE, [], [], self::TURBO_FRAME_HEADERS);

        self::assertResponseStatusCodeSame(200);
        $carried = (string) $frame->filter('#'.Thread::MESSAGES_ID)->attr('data-ask-choices');
        self::assertSame($expected, self::askChoicesIn($carried));
        self::assertSame($expected, self::askChoicesIn($frame->filter('form.thread__composer .thread__ask-choices')->html()), 'The composer starts with the same people.');
        self::assertSame($expected[0][1], new Crawler('<!DOCTYPE html><html><body>'.$carried.'</body></html>')->filter('input[name="'.ThreadController::ASKED_USER_FIELD.'"]')->attr('value'));

        $this->client->request('POST', self::COMMENT_PATH, $this->formFields('Fixed on staging'), [], self::TURBO_FORM_HEADERS);

        $streams = $this->streamsOfTheAnswer(200);
        self::assertSame($expected, self::askChoicesIn((string) $streams[Thread::MESSAGES_ID]->filter('#'.Thread::MESSAGES_ID)->attr('data-ask-choices')));
    }

    /**
     * Sara is an Admin with no team role; she may see the team-only ticket, so she may comment on it.
     */
    #[Test]
    public function anAdminWithoutATeamRoleCommentsOnATeamOnlyTicket(): void
    {
        $this->addTicket('team');
        $this->logIn($this->client, $this->people['Sara']);

        $this->client->request('POST', self::COMMENT_PATH, $this->formFields('Please check the logs'));

        self::assertSame(ThreadController::COMMENT_ADDED, $this->toastAfterTheRedirect());
        self::assertSame([['Please check the logs', $this->people['Sara']->id()->toString(), null, null]], $this->messageRows());
    }

    /**
     * @return iterable<string, array{string, string}> who posts, and the ticket's visibility
     */
    public static function peopleWhoMayNotSeeTheTicket(): iterable
    {
        yield 'a manager with no team role, on a team-only ticket' => ['Raj', 'team'];
        yield 'an admin of another organization, on a ticket for everyone' => ['Ben', 'all'];
    }

    #[Test]
    #[DataProvider('peopleWhoMayNotSeeTheTicket')]
    public function someoneWhoMayNotSeeTheTicketGetsNotFoundToEveryActionAndNothingIsWritten(string $person, string $visibility): void
    {
        $this->addTicket($visibility);
        $messageId = $this->addMessage('Omar', self::EARLIER_BODY, '2026-11-06 11:59:00+00');
        $rowsBefore = $this->messageRows();
        $this->addBen();
        $this->logIn($this->client, $this->people[$person]);

        foreach ([self::COMMENT_PATH, $this->messagePath($messageId, 'edit'), $this->messagePath($messageId, 'remove')] as $path) {
            $this->client->request('POST', $path, $this->formFields('Let me in'));
            self::assertResponseStatusCodeSame(404, $path);
            self::assertStringNotContainsString(self::EARLIER_BODY, (string) $this->client->getResponse()->getContent());
        }

        self::assertSame($rowsBefore, $this->messageRows());
        self::assertSame(0, $this->outboxCount());
    }

    #[Test]
    public function anAnonymousVisitorIsSentToLogInAndNothingIsWritten(): void
    {
        $this->addTicket();

        $this->client->request('POST', self::COMMENT_PATH, ['body' => 'Hello']);

        self::assertResponseStatusCodeSame(302);
        self::assertStringEndsWith('/login', (string) $this->client->getResponse()->headers->get('Location'));
        self::assertSame([], $this->messageRows());
    }

    /**
     * @return iterable<string, array{string, string}> the body sent, and the refusal
     */
    public static function bodiesOfNoUse(): iterable
    {
        yield 'blank' => [" \n ", 'A message needs a body.'];
        yield 'one character too long' => [str_repeat('é', 10001), 'A message can have at most 10000 characters.'];
    }

    #[Test]
    #[DataProvider('bodiesOfNoUse')]
    public function aBodyOfNoUseIsRefusedWithTheReasonAndNothingIsWritten(string $body, string $expectedToast): void
    {
        $this->addTicket();
        $this->logIn($this->client, $this->people['Omar']);

        $this->client->request('POST', self::COMMENT_PATH, $this->formFields($body), [], self::TURBO_FORM_HEADERS);

        self::assertSame($expectedToast, $this->toastOf($this->streamsOfTheAnswer(422)));
        self::assertSame([], $this->messageRows());
        self::assertSame(0, $this->outboxCount());
    }

    /**
     * @return iterable<string, array{string}> the token sent
     */
    public static function formTokensOfNoUse(): iterable
    {
        yield 'missing' => [''];
        yield 'made up' => ['not-a-token'];
    }

    #[Test]
    #[DataProvider('formTokensOfNoUse')]
    public function aFormTokenOfNoUseIsRefusedAndNothingIsWritten(string $token): void
    {
        $this->addTicket();
        $messageId = $this->addMessage('Omar', self::EARLIER_BODY, '2026-11-06 11:59:00+00');
        $rowsBefore = $this->messageRows();
        $this->logIn($this->client, $this->people['Omar']);
        $fields = [ThreadController::CSRF_FIELD => $token, ThreadController::BODY_FIELD => 'Changed'];

        $editPath = $this->messagePath($messageId, 'edit');
        foreach ([self::COMMENT_PATH, $editPath, $this->messagePath($messageId, 'remove')] as $path) {
            $this->client->request('POST', $path, $fields, [], self::TURBO_FORM_HEADERS);
            self::assertSame(ThreadController::FORM_EXPIRED, $this->toastOf($this->streamsOfTheAnswer(422, isToastOnly: $editPath === $path)), $path);
        }

        self::assertSame($rowsBefore, $this->messageRows());
        self::assertSame(0, $this->outboxCount());
    }

    /**
     * The window holds up to and including 15 minutes after posting, and the page shows Edit just as long.
     */
    #[Test]
    public function theAuthorEditsWithinFifteenMinutesAndTheMessageIsMarkedEdited(): void
    {
        $this->addTicket();
        $messageId = $this->addMessage('Omar', self::EARLIER_BODY, '2026-11-06 11:45:00+00');
        $this->logIn($this->client, $this->people['Omar']);
        $page = $this->client->request('GET', self::THREAD_PAGE);
        self::assertResponseStatusCodeSame(200);
        self::assertSame(['Edit', 'Remove'], $this->actionsOn($page));

        $this->client->request('POST', $this->messagePath($messageId, 'edit'), $this->formFields('The cart total is right on staging'));

        self::assertSame(ThreadController::MESSAGE_EDITED, $this->toastAfterTheRedirect());
        self::assertSame([['The cart total is right on staging', $this->people['Omar']->id()->toString(), '2026-11-06 12:00:00+00', null]], $this->messageRows());
        self::assertStringContainsString('· edited', $this->client->getCrawler()->filter('#'.Thread::FRAME_ID.' .thread__meta')->text());
    }

    #[Test]
    public function anEditAfterFifteenMinutesIsRefusedWithTheReasonAndTheBodyIsUnchanged(): void
    {
        $this->addTicket();
        $messageId = $this->addMessage('Omar', self::EARLIER_BODY, '2026-11-06 11:44:59+00');
        $rowsBefore = $this->messageRows();
        $this->logIn($this->client, $this->people['Omar']);
        $page = $this->client->request('GET', self::THREAD_PAGE);
        self::assertResponseStatusCodeSame(200);
        self::assertSame(['Remove'], $this->actionsOn($page));

        $this->client->request('POST', $this->messagePath($messageId, 'edit'), $this->formFields('Too late'), [], self::TURBO_FORM_HEADERS);

        // Only the toast: a new message list would close the open Edit box and lose its text.
        self::assertSame(self::EDIT_WINDOW_PASSED, $this->toastOf($this->streamsOfTheAnswer(422, isToastOnly: true)));
        self::assertSame($rowsBefore, $this->messageRows());
    }

    #[Test]
    public function anEditRefusedFromAFormWithoutTurboRedirectsToTheThreadWithTheReason(): void
    {
        $this->addTicket();
        $messageId = $this->addMessage('Omar', self::EARLIER_BODY, '2026-11-06 11:44:59+00');
        $rowsBefore = $this->messageRows();
        $this->logIn($this->client, $this->people['Omar']);

        $this->client->request('POST', $this->messagePath($messageId, 'edit'), $this->formFields('Too late'));

        self::assertSame(self::EDIT_WINDOW_PASSED, $this->toastAfterTheRedirect());
        self::assertSame($rowsBefore, $this->messageRows());
    }

    /**
     * Lena leads the team, and still may not edit or remove Omar's message; she is shown no Edit or Remove for it.
     */
    #[Test]
    public function someoneOtherThanTheAuthorIsRefusedAnEditAndARemovalAndNothingChanges(): void
    {
        $this->addTicket();
        $messageId = $this->addMessage('Omar', self::EARLIER_BODY, '2026-11-06 11:59:00+00');
        $rowsBefore = $this->messageRows();
        $this->logIn($this->client, $this->people['Lena']);
        $page = $this->client->request('GET', self::THREAD_PAGE);
        self::assertResponseStatusCodeSame(200);
        self::assertSame([], $this->actionsOn($page));

        $this->client->request('POST', $this->messagePath($messageId, 'edit'), $this->formFields('Lena was here'), [], self::TURBO_FORM_HEADERS);
        self::assertSame('Only the person who wrote a message can edit it.', $this->toastOf($this->streamsOfTheAnswer(422, isToastOnly: true)));
        $this->client->request('POST', $this->messagePath($messageId, 'remove'), $this->formFields(''), [], self::TURBO_FORM_HEADERS);
        self::assertSame('Only the person who wrote a message can remove it.', $this->toastOf($this->streamsOfTheAnswer(422)));

        self::assertSame($rowsBefore, $this->messageRows());
    }

    /**
     * FR-CNV-06: removal has no time limit. The removed message keeps its row and its text in the database, and the
     * page shows "Message removed" in its place, never the text.
     */
    #[Test]
    public function theAuthorRemovesAnOldMessageAndThePageShowsMessageRemovedWithoutItsText(): void
    {
        $this->addTicket();
        $messageId = $this->addMessage('Omar', self::EARLIER_BODY, '2026-11-03 09:00:00+00');
        $this->addMessage('Lena', 'Thanks, closing this', '2026-11-03 10:00:00+00');
        $this->logIn($this->client, $this->people['Omar']);

        $this->client->request('POST', $this->messagePath($messageId, 'remove'), $this->formFields(''));

        self::assertSame(ThreadController::MESSAGE_REMOVED, $this->toastAfterTheRedirect());
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString(self::EARLIER_BODY, $html);
        $bodies = $this->client->getCrawler()->filter('#'.Thread::FRAME_ID.' .thread__body')->each(static fn (Crawler $body): string => trim($body->text()));
        self::assertSame(['Message removed', 'Thanks, closing this'], $bodies);
        self::assertSame([], $this->actionsOn($this->client->getCrawler()->filter('#'.Thread::FRAME_ID.' .thread__message')->first()));
        self::assertSame('2026-11-06 12:00:00+00', $this->messageRows()[0][3]);
        self::assertSame(self::EARLIER_BODY, $this->messageRows()[0][0]);
    }

    #[Test]
    public function aRemovedMessageIsRefusedASecondRemovalAndAnEdit(): void
    {
        $this->addTicket();
        $messageId = $this->addMessage('Omar', self::EARLIER_BODY, '2026-11-06 11:59:00+00', '2026-11-06 11:59:30+00');
        $rowsBefore = $this->messageRows();
        $this->logIn($this->client, $this->people['Omar']);

        $this->client->request('POST', $this->messagePath($messageId, 'remove'), $this->formFields(''), [], self::TURBO_FORM_HEADERS);
        self::assertSame('This message is already removed.', $this->toastOf($streams = $this->streamsOfTheAnswer(422)));
        self::assertStringNotContainsString(self::EARLIER_BODY, $streams[Thread::MESSAGES_ID]->html());
        $this->client->request('POST', $this->messagePath($messageId, 'edit'), $this->formFields('Back again'), [], self::TURBO_FORM_HEADERS);
        self::assertSame('This message was removed, so it cannot be edited.', $this->toastOf($this->streamsOfTheAnswer(422, isToastOnly: true)));

        self::assertSame($rowsBefore, $this->messageRows());
    }

    /**
     * @return iterable<string, array{string}> a message id no message of the ticket has
     */
    public static function messageIdsOfNoMessage(): iterable
    {
        yield 'malformed' => ['not-a-uuid'];
        yield 'unknown' => ['0197a3c0-0000-7000-8000-00000000dead'];
        yield 'Omar\'s own comment on SHOP-2' => [self::OTHER_TICKETS_COMMENT_ID];
    }

    /**
     * Omar may edit and remove his comments on SHOP-1 and SHOP-2: an id under SHOP-1's path that names no message of
     * SHOP-1 changes neither.
     */
    #[Test]
    #[DataProvider('messageIdsOfNoMessage')]
    public function aMessageIdOfNoMessageIsNotFound(string $messageId): void
    {
        $this->addTicket();
        $this->addMessage('Omar', self::EARLIER_BODY, '2026-11-06 11:59:00+00');
        $this->addTicket(key: self::OTHER_KEY);
        $this->addMessage('Omar', self::EARLIER_BODY, '2026-11-06 11:59:00+00', key: self::OTHER_KEY, messageId: self::OTHER_TICKETS_COMMENT_ID);
        $rowsBefore = $this->messageRows();
        self::assertCount(2, $rowsBefore);
        $this->logIn($this->client, $this->people['Omar']);

        foreach (['edit', 'remove'] as $action) {
            $this->client->request('POST', $this->messagePath($messageId, $action), $this->formFields('Changed'));
            self::assertResponseStatusCodeSame(404, $action);
        }

        self::assertSame($rowsBefore, $this->messageRows());
    }

    /**
     * @return array<string, string> the thread's form token, then the body
     */
    private function formFields(string $body): array
    {
        return [
            ThreadController::CSRF_FIELD => $this->csrfTokenFor($this->client, ThreadController::csrfIdOf(self::KEY)),
            ThreadController::BODY_FIELD => $body,
        ];
    }

    /**
     * @param array<string, string> $server
     *
     * @return list<string> the SQL of every query the GET ran, after asserting it answered 200
     */
    private function queriesOf(string $uri, array $server = []): array
    {
        $debugData = self::getContainer()->get('doctrine.debug_data_holder');
        $debugData->reset();
        $this->client->request('GET', $uri, [], [], $server);
        self::assertResponseStatusCodeSame(200);

        $queries = [];
        foreach ($debugData->getData() as $queriesOfOneConnection) {
            foreach ($queriesOfOneConnection as $query) {
                self::assertIsString($query['sql']);
                $queries[] = $query['sql'];
            }
        }

        return $queries;
    }

    /**
     * @param list<string> $queries
     *
     * @return list<string> those that read the thread's table
     */
    private static function threadQueriesAmong(array $queries): array
    {
        return array_values(array_filter($queries, static fn (string $query): bool => str_contains($query, 'cnv_message')));
    }

    private function messagePath(string $messageId, string $action): string
    {
        return '/t/'.self::KEY.'/messages/'.$messageId.'/'.$action;
    }

    private function addBen(): void
    {
        $betaBakery = $this->createOrganization('Beta Bakery');
        $this->people['Ben'] = $this->createUserWithoutOrganization('Ben');
        $this->addMembership($this->people['Ben'], $betaBakery);
        $this->setOrgRoles($this->people['Ben'], $betaBakery, '{admin}');
    }

    /**
     * @return list<string> the labels of the Edit and Remove of the messages shown
     */
    private function actionsOn(Crawler $page): array
    {
        return $page->filter('.thread__edit summary, .thread__remove-form button')->each(static fn (Crawler $action): string => trim($action->text()));
    }

    private function toastAfterTheRedirect(): string
    {
        self::assertResponseStatusCodeSame(303);
        self::assertSame(self::THREAD_PAGE, $this->client->getResponse()->headers->get('Location'));
        $page = $this->client->followRedirect();
        self::assertResponseStatusCodeSame(200);
        $toast = $page->filter('.toast-region [data-toast-message-value]');
        self::assertCount(1, $toast);

        return (string) $toast->attr('data-toast-message-value');
    }

    /**
     * Asserts the answer is a Turbo Stream of the status that replaces the message list and updates the toast region,
     * or, for a toast-only answer, only updates the toast region.
     *
     * @return array<string, Crawler> each stream's template content by its target
     */
    private function streamsOfTheAnswer(int $expectedStatus, bool $isToastOnly = false): array
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
        $streams = [];
        foreach ($matches as [, , $target, $content]) {
            $streams[$target] = new Crawler('<!DOCTYPE html><html><body>'.$content.'</body></html>');
        }

        return $streams;
    }

    /**
     * @param array<string, Crawler> $streams
     */
    private function toastOf(array $streams): string
    {
        $toast = $streams[self::TOAST_REGION_ID]->filter('[data-toast-message-value]');
        self::assertCount(1, $toast);

        return (string) $toast->attr('data-toast-message-value');
    }

    /**
     * A bug of Shop's team, SHOP-1 unless $key says otherwise, reported by Lena and assigned to Omar, for everyone or
     * for the team only.
     */
    private function addTicket(string $visibility = 'all', string $key = self::KEY): void
    {
        $parameters = [
            $this->acmeFoods->toString(), Uuid::v7()->toRfc4122(), $this->shopProject, $this->shop->toString(), (int) substr($key, \strlen('SHOP-')), $key, $this->stepId,
            $this->people['Lena']->id()->toString(), $this->people['Omar']->id()->toString(), $visibility,
        ];
        $this->committedAs($this->acmeFoods, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            "INSERT INTO tkt_ticket (organization_id, id, project_id, team_id, number, key, title, description, type, priority, priority_rank,
                 step_id, reporter_id, assignee_id, visibility, version, components, check_reasons, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, 'Cart total shows the wrong sum', '', 'bug', 'major', 2, ?, ?, ?, ?, 3, '{}', '{}',
                 TIMESTAMPTZ '2026-11-04 10:00:00+00', TIMESTAMPTZ '2026-11-05 10:00:00+00')",
            $parameters,
        ));
    }

    private function setReviewerAndTester(string $reviewer, string $tester): void
    {
        $parameters = [$this->people[$reviewer]->id()->toString(), $this->people[$tester]->id()->toString(), $this->acmeFoods->toString(), self::KEY];
        $this->committedAs($this->acmeFoods, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            'UPDATE tkt_ticket SET reviewer_id = ?, tester_id = ? WHERE organization_id = ? AND key = ?',
            $parameters,
        ));
    }

    /**
     * @return list<array{string, string}> the first name and user id of each person chip in the markup, in order
     */
    private static function askChoicesIn(string $markup): array
    {
        return new Crawler('<!DOCTYPE html><html><body>'.$markup.'</body></html>')
            ->filter('[data-thread-composer-target="person"]')
            ->each(static fn (Crawler $chip): array => [trim($chip->text()), (string) $chip->attr('data-user-id')]);
    }

    /**
     * A comment by the person on SHOP-1 unless $key says otherwise, removed at $removedAt when given; or an open
     * question to Lena.
     *
     * @param 'comment'|'question' $kind
     *
     * @return string its id, $messageId when given
     */
    private function addMessage(string $author, string $body, string $createdAt, ?string $removedAt = null, string $kind = 'comment', string $key = self::KEY, ?string $messageId = null): string
    {
        $messageId ??= Uuid::v7()->toRfc4122();
        $organizationId = $this->acmeFoods->toString();
        $authorId = $this->people[$author]->id()->toString();
        $toUserId = 'question' === $kind ? $this->people['Lena']->id()->toString() : null;
        $this->committedAs($this->acmeFoods, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            'INSERT INTO cnv_message (organization_id, id, ticket_id, author_id, kind, to_user_id, body, created_at, removed_at)
             SELECT organization_id, ?, id, ?, ?, ?, ?, ?, ? FROM tkt_ticket WHERE organization_id = ? AND key = ?',
            [$messageId, $authorId, $kind, $toUserId, $body, $createdAt, $removedAt, $organizationId, $key],
        ));

        return $messageId;
    }

    /**
     * A question on SHOP-1 by $asker to $asked, who answered it ten minutes later.
     */
    private function addAnsweredQuestion(string $asker, string $asked, string $askedAt): void
    {
        $questionId = Uuid::v7()->toRfc4122();
        $answeredAt = new \DateTimeImmutable($askedAt)->modify('+10 minutes')->format('Y-m-d H:i:sP');
        $parameters = [
            'organization' => $this->acmeFoods->toString(), 'question' => $questionId, 'answer' => Uuid::v7()->toRfc4122(),
            'asker' => $this->people[$asker]->id()->toString(), 'asked' => $this->people[$asked]->id()->toString(),
            'askedAt' => $askedAt, 'answeredAt' => $answeredAt,
        ];
        $this->committedAs($this->acmeFoods, static function (Connection $migrator) use ($parameters): int {
            $migrator->executeStatement(
                "INSERT INTO cnv_message (organization_id, id, ticket_id, author_id, kind, to_user_id, body, created_at, resolved_at, resolved_by)
                 SELECT organization_id, :question, id, :asker, 'question', :asked, 'Which build?', :askedAt, :answeredAt, :asked
                 FROM tkt_ticket WHERE organization_id = :organization AND key = 'SHOP-1'",
                $parameters,
            );

            return (int) $migrator->executeStatement(
                "INSERT INTO cnv_reply (organization_id, id, message_id, author_id, body, is_answer, created_at)
                 VALUES (:organization, :answer, :question, :asked, 'Build 42.', true, :answeredAt)",
                $parameters,
            );
        });
    }

    /**
     * A plain reply by $author a minute before now.
     */
    private function addReply(string $messageId, string $author, string $body): void
    {
        $parameters = [$this->acmeFoods->toString(), Uuid::v7()->toRfc4122(), $messageId, $this->people[$author]->id()->toString(), $body];
        $this->committedAs($this->acmeFoods, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            "INSERT INTO cnv_reply (organization_id, id, message_id, author_id, body, is_answer, created_at)
             VALUES (?, ?, ?, ?, ?, false, TIMESTAMPTZ '2026-11-06 11:59:00+00')",
            $parameters,
        ));
    }

    /**
     * Acme Foods' messages, oldest first.
     *
     * @return list<array{string, string, string|null, string|null}> body, author id, edited at and removed at (UTC)
     */
    private function messageRows(): array
    {
        $organizationId = $this->acmeFoods->toString();
        $rows = [];
        $this->committedAs($this->acmeFoods, static function (Connection $migrator) use ($organizationId, &$rows): int {
            $migrator->executeStatement("SET LOCAL TIME ZONE 'UTC'");
            $rows = $migrator->fetchAllNumeric(
                'SELECT body, author_id::text, edited_at::text, removed_at::text FROM cnv_message WHERE organization_id = ? ORDER BY created_at, id',
                [$organizationId],
            );

            return 0;
        });

        return array_map(static function (array $row): array {
            [$body, $authorId, $editedAt, $removedAt] = $row;
            \assert(\is_string($body) && \is_string($authorId) && (null === $editedAt || \is_string($editedAt)) && (null === $removedAt || \is_string($removedAt)));

            return [$body, $authorId, $editedAt, $removedAt];
        }, $rows);
    }

    private function outboxCount(): int
    {
        $organizationId = $this->acmeFoods->toString();
        $count = 0;
        $this->committedAs($this->acmeFoods, static function (Connection $migrator) use ($organizationId, &$count): int {
            $outboxRows = $migrator->fetchOne("SELECT count(*) FROM messenger_outbox WHERE queue_name = 'outbox' AND position(? in body) > 0", [$organizationId]);
            self::assertIsNumeric($outboxRows);
            $count = (int) $outboxRows;

            return 0;
        });

        return $count;
    }
}
