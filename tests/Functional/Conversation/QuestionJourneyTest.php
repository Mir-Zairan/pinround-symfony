<?php

declare(strict_types=1);

namespace App\Tests\Functional\Conversation;

use App\Conversation\Application\AskQuestion\AskQuestion;
use App\Conversation\UI\Component\Thread;
use App\Conversation\UI\ThreadController;
use App\Identity\Domain\User;
use App\SharedKernel\Domain\OrganizationId;
use App\Tests\Functional\Identity\LogsInUsers;
use App\Tests\Functional\Web\CreatesAcmeFoodsTeams;
use App\Tests\Functional\Web\CreatesTeams;
use App\Tests\Functional\Web\IssuesCsrfTokens;
use App\Ticketing\Api\TicketPauses;
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
 * Asking and answering in the ticket's thread, POST /t/{key}/questions and /t/{key}/messages/{id}/answer, on Acme
 * Foods' SHOP-1 (reported by Lena, assigned to Omar) through the real firewall, voters, session and database. The clock
 * is fixed on 6 Nov 2026, before today. Every refusal proves the reason and that the thread, the pause, the turn and
 * the outbox are unchanged.
 */
final class QuestionJourneyTest extends WebTestCase
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
    private const string OTHER_TICKETS_QUESTION_ID = '0197a3c0-0000-7000-8000-0000000000b2';
    private const string TICKET_PAGE = '/t/SHOP-1';
    private const string THREAD_PAGE = '/t/SHOP-1/thread';
    private const string ASK_PATH = '/t/SHOP-1/questions';
    private const array TURBO_FRAME_HEADERS = ['HTTP_TURBO_FRAME' => Thread::FRAME_ID];
    private const array TURBO_FORM_HEADERS = ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html, text/html, application/xhtml+xml'];
    private const string TOAST_REGION_ID = 'toast-region';
    private const string QUESTION = 'Which build shows the wrong sum?';
    private const string ANSWER = 'Build 42, on the cart page.';
    private const string NOT_THE_PERSON_ASKED = 'Only the person asked can answer this question.';

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

    /**
     * Omar asks Lena from a Turbo form: the ticket pauses and the turn is hers, and only she is offered Answer. She
     * answers from a plain form: the question closes with her answer under it, the pause ends and the turn is Omar's
     * again. Each change writes one event to the outbox and redirects to the ticket's page with its toast.
     */
    #[Test]
    public function omarAsksLenaAndHerAnswerResumesTheTicket(): void
    {
        $this->addTicket();
        $lena = $this->people['Lena']->id()->toString();
        $omar = $this->people['Omar']->id()->toString();
        $this->logIn($this->client, $this->people['Omar']);

        $this->client->request('POST', self::ASK_PATH, $this->askFields(self::QUESTION, $lena), [], self::TURBO_FORM_HEADERS);

        self::assertSame('Question sent. Lena gets one notification. Ticket paused.', $this->toastOnTheTicketPage());
        $questionId = $this->openQuestionId();
        self::assertSame([[self::QUESTION, $omar, $lena, null]], $this->questionRows());
        self::assertSame([$lena, $omar], $this->pause());
        self::assertSame([$lena, 'answer'], $this->turn());
        self::assertSame(1, $this->outboxCount());
        $frame = $this->threadFrame();
        self::assertCount(0, $frame->filter('.thread__answer'), 'Only the person asked is offered Answer.');
        self::assertSame(['Omar · now · question to Lena', 'Waiting on Lena', true], $this->questionShownIn($frame, $questionId));
        self::assertSame([true, true, 'true'], $this->askChipStateIn($frame), 'A second question waits for the first one\'s answer.');

        $this->logIn($this->client, $this->people['Lena']);
        $frame = $this->threadFrame();
        $answerForm = $frame->filter('#thread-message-'.$questionId.' form.thread__answer-form');
        self::assertCount(1, $answerForm);
        self::assertSame('/t/SHOP-1/messages/'.$questionId.'/answer', $answerForm->attr('action'));
        self::assertSame('_top', $answerForm->attr('data-turbo-frame'));
        self::assertSame('Your answer closes the question. The ticket goes back to To do.', trim($answerForm->filter('.thread__note')->text()));
        self::assertSame(
            [['Cancel', 'button', 'thread-composer#closeReplyBox'], ['Answer', 'submit', null]],
            $answerForm->filter('button')->each(static fn (Crawler $button): array => [trim($button->text()), $button->attr('type'), $button->attr('data-action')]),
        );

        $this->client->request('POST', $this->answerPath($questionId), $this->answerFields(self::ANSWER));

        self::assertSame('Answered. The ticket goes back to To do.', $this->toastOnTheTicketPage());
        self::assertSame([[self::QUESTION, $omar, $lena, $lena]], $this->questionRows());
        self::assertSame([[$questionId, $lena, self::ANSWER, true]], $this->replyRows());
        self::assertSame([null, null], $this->pause());
        self::assertSame([$omar, 'build'], $this->turn());
        self::assertSame(2, $this->outboxCount());
        $frame = $this->threadFrame();
        self::assertCount(0, $frame->filter('.thread__answer'), 'An answered question offers no Answer.');
        self::assertSame(['Omar · now · question to Lena', 'Answered', false], $this->questionShownIn($frame, $questionId));
        self::assertSame([false, false, 'false'], $this->askChipStateIn($frame), 'Once answered, Lena may ask again.');
        $answer = $frame->filter('#thread-message-'.$questionId.' .thread__reply--answer');
        self::assertCount(1, $answer);
        self::assertSame('Lena · now · answer', trim($answer->filter('.thread__meta')->text()));
        self::assertSame(self::ANSWER, trim($answer->filter('.thread__body')->text()));
    }

    #[Test]
    public function aSecondQuestionIsRefusedWhileOneWaitsAndNothingChanges(): void
    {
        $this->addTicket();
        $lena = $this->people['Lena']->id()->toString();
        $omar = $this->people['Omar']->id()->toString();
        $this->logIn($this->client, $this->people['Omar']);
        $this->client->request('POST', self::ASK_PATH, $this->askFields(self::QUESTION, $lena));
        self::assertResponseStatusCodeSame(303);
        $this->logIn($this->client, $this->people['Lena']);

        $this->client->request('POST', self::ASK_PATH, $this->askFields('Can you check the tablet too?', $omar), [], self::TURBO_FORM_HEADERS);

        self::assertSame(AskQuestion::ALREADY_WAITING, $this->toastOf($this->streamsOfTheAnswer(422)));
        self::assertSame([[self::QUESTION, $omar, $lena, null]], $this->questionRows());
        self::assertSame([$lena, $omar], $this->pause());
        self::assertSame([$lena, 'answer'], $this->turn());
        self::assertSame(1, $this->outboxCount());
    }

    /**
     * @return iterable<string, array{string, string, string}> who answers, the body, and the refusal
     */
    public static function answersOfNoUse(): iterable
    {
        yield 'Mia, a viewer who is not asked' => ['Mia', self::ANSWER, self::NOT_THE_PERSON_ASKED];
        yield 'Omar, who asked' => ['Omar', self::ANSWER, self::NOT_THE_PERSON_ASKED];
        yield 'Lena, with a blank answer' => ['Lena', " \n ", 'A reply needs a body.'];
    }

    #[Test]
    #[DataProvider('answersOfNoUse')]
    public function anAnswerOfNoUseIsRefusedWithTheReasonAndTheQuestionStaysOpen(string $person, string $body, string $expectedToast): void
    {
        $this->addTicket();
        $lena = $this->people['Lena']->id()->toString();
        $omar = $this->people['Omar']->id()->toString();
        $this->logIn($this->client, $this->people['Omar']);
        $this->client->request('POST', self::ASK_PATH, $this->askFields(self::QUESTION, $lena));
        self::assertResponseStatusCodeSame(303);
        $questionId = $this->openQuestionId();
        $this->logIn($this->client, $this->people[$person]);

        $this->client->request('POST', $this->answerPath($questionId), $this->answerFields($body), [], self::TURBO_FORM_HEADERS);

        self::assertSame($expectedToast, $this->toastOf($this->streamsOfTheAnswer(422)));
        self::assertSame([[self::QUESTION, $omar, $lena, null]], $this->questionRows());
        self::assertSame([], $this->replyRows());
        self::assertSame([$lena, $omar], $this->pause());
        self::assertSame([$lena, 'answer'], $this->turn());
        self::assertSame(1, $this->outboxCount());
    }

    /**
     * @return iterable<string, array{string, string, string}> whom Omar asks (a person's name or a raw field), the body,
     *                                                         and the refusal
     */
    public static function questionsOfNoUse(): iterable
    {
        yield 'Sara, who is not on the ticket' => ['Sara', self::QUESTION, AskQuestion::NOT_ON_THE_TICKET];
        yield 'himself' => ['Omar', self::QUESTION, AskQuestion::ASKING_YOURSELF];
        yield 'a malformed person id' => ['raw:not-a-uuid', self::QUESTION, AskQuestion::NOT_ON_THE_TICKET];
        yield 'no person id' => ['raw:', self::QUESTION, AskQuestion::NOT_ON_THE_TICKET];
        yield 'Lena, with a blank question' => ['Lena', " \n ", 'A message needs a body.'];
    }

    #[Test]
    #[DataProvider('questionsOfNoUse')]
    public function aQuestionOfNoUseIsRefusedWithTheReasonAndNothingIsWritten(string $asked, string $body, string $expectedToast): void
    {
        $this->addTicket();
        $askedField = str_starts_with($asked, 'raw:') ? substr($asked, 4) : $this->people[$asked]->id()->toString();
        $this->logIn($this->client, $this->people['Omar']);

        $this->client->request('POST', self::ASK_PATH, $this->askFields($body, $askedField), [], self::TURBO_FORM_HEADERS);

        self::assertSame($expectedToast, $this->toastOf($this->streamsOfTheAnswer(422)));
        $this->assertNothingIsWritten();
    }

    /**
     * Mia is a viewer of Shop's team: she may see and comment on the ticket, but ConversationPermission ASK denies her.
     */
    #[Test]
    public function aViewerOfTheTeamIsForbiddenToAskAndNothingIsWritten(): void
    {
        $this->addTicket();
        $this->logIn($this->client, $this->people['Mia']);

        $this->client->request('POST', self::ASK_PATH, $this->askFields(self::QUESTION, $this->people['Lena']->id()->toString()));

        self::assertResponseStatusCodeSame(403);
        $this->assertNothingIsWritten();
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
    public function someoneWhoMayNotSeeTheTicketGetsNotFoundToAskingAndAnsweringAndNothingChanges(string $person, string $visibility): void
    {
        $this->addTicket($visibility);
        $questionId = $this->addOpenQuestion();
        $rowsBefore = [$this->questionRows(), $this->pause()];
        $this->addBen();
        $this->logIn($this->client, $this->people[$person]);

        foreach ([self::ASK_PATH, $this->answerPath($questionId)] as $path) {
            $this->client->request('POST', $path, $this->askFields(self::ANSWER, $this->people['Lena']->id()->toString()));
            self::assertResponseStatusCodeSame(404, $path);
            self::assertStringNotContainsString(self::QUESTION, (string) $this->client->getResponse()->getContent());
        }

        self::assertSame($rowsBefore, [$this->questionRows(), $this->pause()]);
        self::assertSame([], $this->replyRows());
        self::assertSame(0, $this->outboxCount());
    }

    /**
     * @return iterable<string, array{string}> a message id no message of the ticket has
     */
    public static function messageIdsOfNoMessage(): iterable
    {
        yield 'malformed' => ['not-a-uuid'];
        yield 'unknown' => ['0197a3c0-0000-7000-8000-00000000dead'];
        yield 'an open question to Lena on SHOP-2, a team-only ticket she may see' => [self::OTHER_TICKETS_QUESTION_ID];
    }

    /**
     * Lena is asked on SHOP-1 and on SHOP-2: an id under SHOP-1's path that names no message of SHOP-1 answers neither
     * question and resumes neither ticket.
     */
    #[Test]
    #[DataProvider('messageIdsOfNoMessage')]
    public function anAnswerToAMessageIdOfNoMessageIsNotFound(string $messageId): void
    {
        $this->addTicket();
        $this->addOpenQuestion();
        $this->addTicket('team', self::OTHER_KEY);
        $this->addOpenQuestion(self::OTHER_KEY, self::OTHER_TICKETS_QUESTION_ID);
        $rowsBefore = [$this->questionRows(), $this->pause(), $this->pause(self::OTHER_KEY)];
        self::assertSame([[self::QUESTION, null], [self::QUESTION, null]], array_map(static fn (array $question): array => [$question[0], $question[3]], $rowsBefore[0]));
        $askedByOmarOfLena = [$this->people['Lena']->id()->toString(), $this->people['Omar']->id()->toString()];
        self::assertSame([$askedByOmarOfLena, $askedByOmarOfLena], [$rowsBefore[1], $rowsBefore[2]]);
        $this->logIn($this->client, $this->people['Lena']);

        $this->client->request('POST', $this->answerPath($messageId), $this->answerFields(self::ANSWER));

        self::assertResponseStatusCodeSame(404);
        self::assertSame($rowsBefore, [$this->questionRows(), $this->pause(), $this->pause(self::OTHER_KEY)]);
        self::assertSame([], $this->replyRows());
        self::assertSame(0, $this->outboxCount());
    }

    #[Test]
    public function aFormTokenOfNoUseIsRefusedAndNothingIsWritten(): void
    {
        $this->addTicket();
        $this->logIn($this->client, $this->people['Omar']);

        $this->client->request('POST', self::ASK_PATH, [
            ThreadController::CSRF_FIELD => 'not-a-token',
            ThreadController::BODY_FIELD => self::QUESTION,
            ThreadController::ASKED_USER_FIELD => $this->people['Lena']->id()->toString(),
        ], [], self::TURBO_FORM_HEADERS);

        self::assertSame(ThreadController::FORM_EXPIRED, $this->toastOf($this->streamsOfTheAnswer(422)));
        $this->assertNothingIsWritten();
    }

    /**
     * Another request saved the ticket after the answer was decided on: the answer, the closed question and its event
     * roll back with the refusal. The pause is seeded, as TicketPauses that refuses stands in for the real one.
     */
    #[Test]
    public function aTicketChangedMeanwhileRefusesTheAnswerAndRollsItBack(): void
    {
        $this->addTicket();
        $questionId = $this->addOpenQuestion();
        $rowsBefore = [$this->questionRows(), $this->pause()];
        self::getContainer()->set(TicketPauses::class, new ChangedMeanwhileTicketPauses());
        $this->logIn($this->client, $this->people['Lena']);

        $this->client->request('POST', $this->answerPath($questionId), $this->answerFields(self::ANSWER), [], self::TURBO_FORM_HEADERS);

        self::assertSame(ThreadController::TICKET_CHANGED_MEANWHILE, $this->toastOf($this->streamsOfTheAnswer(422)));
        self::assertSame($rowsBefore, [$this->questionRows(), $this->pause()]);
        self::assertSame([], $this->replyRows());
        self::assertSame(0, $this->outboxCount());
    }

    /**
     * Another request saved the ticket after the question was decided on: the asker reads a plain refusal, never a
     * server error, and no question, pause or event is left behind.
     */
    #[Test]
    public function aTicketChangedMeanwhileRefusesTheQuestionAndNothingIsWritten(): void
    {
        $this->addTicket();
        self::getContainer()->set(TicketPauses::class, new ChangedMeanwhileTicketPauses());
        $this->logIn($this->client, $this->people['Omar']);

        $this->client->request('POST', self::ASK_PATH, $this->askFields(self::QUESTION, $this->people['Lena']->id()->toString()), [], self::TURBO_FORM_HEADERS);

        self::assertSame(ThreadController::TICKET_CHANGED_MEANWHILE, $this->toastOf($this->streamsOfTheAnswer(422)));
        $this->assertNothingIsWritten();
    }

    /**
     * @return iterable<string, array{string, list<string>}> who views the thread, and the people offered in order
     */
    public static function peopleWhoMayAsk(): iterable
    {
        yield 'Omar, the assignee' => ['Omar', ['Lena', 'Sara']];
        yield 'Lena, the reporter and the team\'s lead' => ['Lena', ['Omar', 'Sara']];
    }

    /**
     * SHOP-1 has Omar as assignee, Lena as reporter and lead, Sara as reviewer and a tester Identity does not know. The
     * composer offers everyone on it but the viewer, each once, in the ticket's order, and leaves out the tester, who
     * would have no name. The first person is chosen; the person field is sent only once a question is chosen.
     *
     * @param list<string> $expectedPeople
     */
    #[Test]
    #[DataProvider('peopleWhoMayAsk')]
    public function theComposerOffersToAskEveryoneElseOnTheTicketWithTheFirstChosen(string $viewer, array $expectedPeople): void
    {
        $this->addTicket();
        $this->addReviewerSaraAndATesterNobodyKnows();
        $this->logIn($this->client, $this->people[$viewer]);

        $frame = $this->threadFrame();

        self::assertSame([false, false, 'false'], $this->askChipStateIn($frame));
        self::assertSame('true', $frame->filter('.thread__content')->attr('data-thread-composer-may-ask-value'));
        $askChip = $frame->filter('.thread__kinds [data-thread-composer-target="askChip"]');
        self::assertSame([self::ASK_PATH, 'What do you need to know?'], [$askChip->attr('data-form-action'), $askChip->attr('data-placeholder')]);
        $commentChip = $frame->filter('.thread__kinds [data-thread-composer-target="commentChip"]');
        self::assertSame(
            ['/t/SHOP-1/comments', 'Write a comment', 'A comment does not change the status and sends no notification.', 'Comment'],
            [$commentChip->attr('data-form-action'), $commentChip->attr('data-placeholder'), $commentChip->attr('data-note'), $commentChip->attr('data-button')],
        );
        $people = $frame->filter('.thread__people');
        self::assertNotNull($people->attr('hidden'), 'The people show once Ask a question is chosen.');
        $first = $expectedPeople[0];
        self::assertSame(
            array_map(fn (string $name): array => [
                $name, $name === $first ? 'true' : 'false', $this->people[$name]->id()->toString(),
                \sprintf('A question pauses the ticket until %s answers. They get one notification.', $name), 'Ask '.$name,
            ], $expectedPeople),
            $people->filter('button.chip')->each(static fn (Crawler $chip): array => [
                trim($chip->text()), $chip->attr('aria-pressed'), $chip->attr('data-user-id'), $chip->attr('data-note'), $chip->attr('data-button'),
            ]),
        );
        $askedUser = $frame->filter('form.thread__composer input[name="'.ThreadController::ASKED_USER_FIELD.'"]');
        self::assertSame([$this->people[$first]->id()->toString(), ''], [$askedUser->attr('value'), $askedUser->attr('disabled')]);
    }

    /**
     * Lena is the reporter, the assignee and the team's lead: there is nobody else to ask, so Ask a question is off.
     */
    #[Test]
    public function withNobodyElseOnTheTicketAskAQuestionIsOff(): void
    {
        $this->addTicket();
        $this->assignToLena();
        $this->logIn($this->client, $this->people['Lena']);

        $frame = $this->threadFrame();

        self::assertSame([true, false, 'false'], $this->askChipStateIn($frame));
        self::assertSame('false', $frame->filter('.thread__content')->attr('data-thread-composer-may-ask-value'));
        self::assertCount(0, $frame->filter('.thread__people, input[name="'.ThreadController::ASKED_USER_FIELD.'"]'));
    }

    /**
     * Mia, a viewer, may not ask: Ask a question is off and no note tells her to wait for the open question, which she
     * sees with its tag but may not answer.
     */
    #[Test]
    public function aViewerSeesTheOpenQuestionButIsOfferedNeitherAskingNorAnswering(): void
    {
        $this->addTicket();
        $questionId = $this->addOpenQuestion();
        $this->logIn($this->client, $this->people['Mia']);

        $frame = $this->threadFrame();

        self::assertSame([true, false, 'true'], $this->askChipStateIn($frame));
        self::assertSame('false', $frame->filter('.thread__content')->attr('data-thread-composer-may-ask-value'));
        self::assertCount(0, $frame->filter('.thread__people'));
        self::assertSame(['Omar · 1 min ago · question to Lena', 'Waiting on Lena', true], $this->questionShownIn($frame, $questionId));
        self::assertCount(0, $frame->filter('.thread__answer'));
    }

    private function assertNothingIsWritten(): void
    {
        self::assertSame([], $this->questionRows());
        self::assertSame([null, null], $this->pause());
        self::assertSame(0, $this->outboxCount());
    }

    /**
     * Asserts the answer is a 303 to the ticket's page and returns the one toast that page shows.
     */
    private function toastOnTheTicketPage(): string
    {
        self::assertResponseStatusCodeSame(303);
        self::assertSame(self::TICKET_PAGE, $this->client->getResponse()->headers->get('Location'));
        $page = $this->client->followRedirect();
        self::assertResponseStatusCodeSame(200);
        $toast = $page->filter('.toast-region [data-toast-message-value]');
        self::assertCount(1, $toast);

        return (string) $toast->attr('data-toast-message-value');
    }

    /**
     * @return array{string, string, bool} the question's meta line, its tag, and whether it is marked open
     */
    private function questionShownIn(Crawler $frame, string $questionId): array
    {
        $question = $frame->filter('#thread-message-'.$questionId);
        self::assertCount(1, $question);
        $tag = $question->filter('.thread__tags .tag');
        self::assertCount(1, $tag);

        return [
            (string) preg_replace('/\s+/', ' ', trim($question->filter('.thread__message-main > .thread__meta')->text())),
            trim($tag->text()),
            1 === $question->filter('.thread__message-main--open')->count(),
        ];
    }

    /**
     * @return array{bool, bool, ?string} whether Ask a question is off, whether the note to wait for the open question
     *                                    shows, and whether the message list tells the composer's script a question is
     *                                    open
     */
    private function askChipStateIn(Crawler $frame): array
    {
        $kindChips = $frame->filter('.thread__kinds button.chip');
        self::assertSame(['Comment', 'Ask a question'], $kindChips->each(static fn (Crawler $chip): string => trim($chip->text())));
        $waitNote = $frame->filter('[data-thread-composer-target="waitNote"]');
        $isWaitNoteShown = 1 === $waitNote->count() && null === $waitNote->attr('hidden');
        if ($isWaitNoteShown) {
            self::assertSame('Wait for the open question first.', trim($waitNote->text()));
        }

        return [
            null !== $kindChips->eq(1)->attr('disabled'),
            $isWaitNoteShown,
            $frame->filter('#'.Thread::MESSAGES_ID)->attr('data-has-open-question'),
        ];
    }

    private function threadFrame(): Crawler
    {
        $frame = $this->client->request('GET', self::THREAD_PAGE, [], [], self::TURBO_FRAME_HEADERS);
        self::assertResponseStatusCodeSame(200);

        return $frame;
    }

    /**
     * Asserts the answer is a Turbo Stream of the status that replaces the message list and updates the toast region.
     *
     * @return array<string, Crawler> each stream's template content by its target
     */
    private function streamsOfTheAnswer(int $expectedStatus): array
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
            [['replace', Thread::MESSAGES_ID], ['update', self::TOAST_REGION_ID]],
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
     * @return array<string, string>
     */
    private function askFields(string $body, string $askedUserId): array
    {
        return [...$this->answerFields($body), ThreadController::ASKED_USER_FIELD => $askedUserId];
    }

    /**
     * @return array<string, string>
     */
    private function answerFields(string $body): array
    {
        return [
            ThreadController::CSRF_FIELD => $this->csrfTokenFor($this->client, ThreadController::csrfIdOf(self::KEY)),
            ThreadController::BODY_FIELD => $body,
        ];
    }

    private function answerPath(string $messageId): string
    {
        return '/t/'.self::KEY.'/messages/'.$messageId.'/answer';
    }

    private function addBen(): void
    {
        $betaBakery = $this->createOrganization('Beta Bakery');
        $this->people['Ben'] = $this->createUserWithoutOrganization('Ben');
        $this->addMembership($this->people['Ben'], $betaBakery);
        $this->setOrgRoles($this->people['Ben'], $betaBakery, '{admin}');
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

    private function addReviewerSaraAndATesterNobodyKnows(): void
    {
        $parameters = [$this->people['Sara']->id()->toString(), Uuid::v7()->toRfc4122(), $this->acmeFoods->toString()];
        $this->committedAs($this->acmeFoods, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            "UPDATE tkt_ticket SET reviewer_id = ?, tester_id = ? WHERE organization_id = ? AND key = 'SHOP-1'",
            $parameters,
        ));
    }

    private function assignToLena(): void
    {
        $parameters = [$this->people['Lena']->id()->toString(), $this->acmeFoods->toString()];
        $this->committedAs($this->acmeFoods, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            "UPDATE tkt_ticket SET assignee_id = ? WHERE organization_id = ? AND key = 'SHOP-1'",
            $parameters,
        ));
    }

    /**
     * Omar's open question to Lena on SHOP-1 unless $key says otherwise, with the ticket paused on it as TicketPauses
     * leaves it.
     *
     * @return string its id, $messageId when given
     */
    private function addOpenQuestion(string $key = self::KEY, ?string $messageId = null): string
    {
        $messageId ??= Uuid::v7()->toRfc4122();
        $parameters = [$messageId, $this->people['Omar']->id()->toString(), $this->people['Lena']->id()->toString(), self::QUESTION, $this->acmeFoods->toString(), $key];
        $this->committedAs($this->acmeFoods, static function (Connection $migrator) use ($parameters): int {
            $migrator->executeStatement(
                "INSERT INTO cnv_message (organization_id, id, ticket_id, author_id, kind, to_user_id, body, created_at)
                 SELECT organization_id, ?, id, ?, 'question', ?, ?, TIMESTAMPTZ '2026-11-06 11:59:00+00' FROM tkt_ticket WHERE organization_id = ? AND key = ?",
                $parameters,
            );
            [, $askerId, $askedUserId, , $organizationId, $key] = $parameters;

            return (int) $migrator->executeStatement(
                'UPDATE tkt_ticket SET asked_user_id = ?, asker_id = ? WHERE organization_id = ? AND key = ?',
                [$askedUserId, $askerId, $organizationId, $key],
            );
        });

        return $messageId;
    }

    private function openQuestionId(): string
    {
        $organizationId = $this->acmeFoods->toString();
        $questionId = null;
        $this->committedAs($this->acmeFoods, static function (Connection $migrator) use ($organizationId, &$questionId): int {
            $questionId = $migrator->fetchOne(
                "SELECT id::text FROM cnv_message WHERE organization_id = ? AND kind = 'question' AND resolved_at IS NULL AND removed_at IS NULL",
                [$organizationId],
            );

            return 0;
        });
        self::assertIsString($questionId);

        return $questionId;
    }

    /**
     * Acme Foods' questions, oldest first.
     *
     * @return list<array{string, string, string, string|null}> body, asker id, asked id and who answered it
     */
    private function questionRows(): array
    {
        $organizationId = $this->acmeFoods->toString();
        $rows = [];
        $this->committedAs($this->acmeFoods, static function (Connection $migrator) use ($organizationId, &$rows): int {
            $rows = $migrator->fetchAllNumeric(
                "SELECT body, author_id::text, to_user_id::text, resolved_by::text FROM cnv_message WHERE organization_id = ? AND kind = 'question' ORDER BY created_at, id",
                [$organizationId],
            );

            return 0;
        });

        return array_map(static function (array $row): array {
            [$body, $askerId, $askedUserId, $resolvedBy] = $row;
            \assert(\is_string($body) && \is_string($askerId) && \is_string($askedUserId) && (null === $resolvedBy || \is_string($resolvedBy)));

            return [$body, $askerId, $askedUserId, $resolvedBy];
        }, $rows);
    }

    /**
     * @return list<array{string, string, string, bool}> question id, author id, body and whether it is the answer
     */
    private function replyRows(): array
    {
        $organizationId = $this->acmeFoods->toString();
        $rows = [];
        $this->committedAs($this->acmeFoods, static function (Connection $migrator) use ($organizationId, &$rows): int {
            $rows = $migrator->fetchAllNumeric(
                'SELECT message_id::text, author_id::text, body, is_answer FROM cnv_reply WHERE organization_id = ? ORDER BY created_at, id',
                [$organizationId],
            );

            return 0;
        });

        return array_map(static function (array $row): array {
            [$messageId, $authorId, $body, $isAnswer] = $row;
            \assert(\is_string($messageId) && \is_string($authorId) && \is_string($body) && \is_bool($isAnswer));

            return [$messageId, $authorId, $body, $isAnswer];
        }, $rows);
    }

    /**
     * @return array{string|null, string|null} the ticket's asked person and asker
     */
    private function pause(string $key = self::KEY): array
    {
        $organizationId = $this->acmeFoods->toString();
        $pause = [];
        $this->committedAs($this->acmeFoods, static function (Connection $migrator) use ($organizationId, $key, &$pause): int {
            $row = $migrator->fetchNumeric('SELECT asked_user_id::text, asker_id::text FROM tkt_ticket WHERE organization_id = ? AND key = ?', [$organizationId, $key]);
            self::assertIsArray($row, $key.' has no ticket row.');
            $pause = $row;

            return 0;
        });
        [$askedUserId, $askerId] = $pause;
        \assert((null === $askedUserId || \is_string($askedUserId)) && (null === $askerId || \is_string($askerId)));

        return [$askedUserId, $askerId];
    }

    /**
     * @return array{string|null, string} SHOP-1's saved turn: whose it is and its kind
     */
    private function turn(): array
    {
        $organizationId = $this->acmeFoods->toString();
        $turn = [];
        $this->committedAs($this->acmeFoods, static function (Connection $migrator) use ($organizationId, &$turn): int {
            $row = $migrator->fetchNumeric('SELECT turn_user_id::text, kind FROM ins_ticket_turn WHERE organization_id = ?', [$organizationId]);
            self::assertIsArray($row, 'SHOP-1 has no saved turn.');
            $turn = $row;

            return 0;
        });
        [$turnUserId, $kind] = $turn;
        \assert((null === $turnUserId || \is_string($turnUserId)) && \is_string($kind));

        return [$turnUserId, $kind];
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
