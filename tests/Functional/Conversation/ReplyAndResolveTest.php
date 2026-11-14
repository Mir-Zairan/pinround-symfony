<?php

declare(strict_types=1);

namespace App\Tests\Functional\Conversation;

use App\Conversation\Api\MessageView;
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
 * Replying and resolving in the ticket's thread, POST /t/{key}/messages/{id}/replies and /resolve, on Acme Foods'
 * SHOP-1 (reported by Lena, the lead of Shop's team, assigned to Omar; Mia views the team, Sara is an admin, Raj a
 * manager with no team role) through the real firewall, voters, session and database. The clock is fixed on
 * 6 Nov 2026, before today. Every refusal proves the reason and that the replies, the messages and the outbox are
 * unchanged.
 */
final class ReplyAndResolveTest extends WebTestCase
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
    private const array TURBO_FORM_HEADERS = ['HTTP_ACCEPT' => 'text/vnd.turbo-stream.html, text/html, application/xhtml+xml'];
    private const string TOAST_REGION_ID = 'toast-region';
    private const string COMMENT = 'The total is off by the shipping fee.';
    private const string QUESTION = 'Which build shows the wrong sum?';
    private const string REPLY = 'I see it on the tablet too.';

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
     * Mia, a viewer, replies to Omar's comment from a Turbo form: the reply is saved under it as a plain reply, one
     * ReplyPosted goes to the outbox, and the stream carries the toast.
     */
    #[Test]
    public function aViewerRepliesToAComment(): void
    {
        $this->addTicket();
        $commentId = $this->addMessage('Omar', 'comment', self::COMMENT);
        $this->logIn($this->client, $this->people['Mia']);

        $this->client->request('POST', $this->messagePath($commentId, 'replies'), $this->formFields(self::REPLY), [], self::TURBO_FORM_HEADERS);

        self::assertSame(ThreadController::REPLY_ADDED, $this->toastOf($this->streamsOfTheAnswer(200)));
        self::assertSame([[$commentId, $this->people['Mia']->id()->toString(), self::REPLY, false]], $this->replyRows());
        self::assertSame(1, $this->outboxCount());
    }

    /**
     * Lena, the person asked, replies to the open question: a reply is no answer, so the question stays open.
     */
    #[Test]
    public function aReplyByThePersonAskedLeavesTheQuestionOpen(): void
    {
        $this->addTicket();
        $questionId = $this->addMessage('Omar', 'question', self::QUESTION);
        $this->logIn($this->client, $this->people['Lena']);

        $this->client->request('POST', $this->messagePath($questionId, 'replies'), $this->formFields(self::REPLY));

        self::assertSame(ThreadController::REPLY_ADDED, $this->toastAfterTheRedirect());
        self::assertSame([[$questionId, $this->people['Lena']->id()->toString(), self::REPLY, false]], $this->replyRows());
        self::assertSame([[$questionId, null, null]], $this->resolutionsOf([$questionId]));
    }

    /**
     * A reply's id is no message's id, so a reply to a reply is not found and nothing is written (T3 criterion 1).
     */
    #[Test]
    public function aReplyToAReplyIsNotFoundAndNoReplyIsWritten(): void
    {
        $this->addTicket();
        $commentId = $this->addMessage('Omar', 'comment', self::COMMENT);
        $replyId = $this->addReply($commentId, 'Lena', 'An earlier reply.');
        $repliesBefore = $this->replyRows();
        self::assertCount(1, $repliesBefore);
        $this->logIn($this->client, $this->people['Mia']);

        $this->client->request('POST', $this->messagePath($replyId, 'replies'), $this->formFields(self::REPLY), [], self::TURBO_FORM_HEADERS);

        self::assertResponseStatusCodeSame(404);
        self::assertSame($repliesBefore, $this->replyRows());
        self::assertSame(0, $this->outboxCount());
    }

    /**
     * @return iterable<string, array{string, string, string}> the message's author and kind, whether it is removed
     *                                                         ('removed') and the refusal
     */
    public static function repliesOfNoUse(): iterable
    {
        yield 'to a removed comment' => ['comment', 'removed', 'This message was removed, so you cannot reply to it.'];
        yield 'to a withdrawn question' => ['question', 'removed', 'This message was removed, so you cannot reply to it.'];
        yield 'with a blank body' => ['comment', 'kept', 'A reply needs a body.'];
    }

    #[Test]
    #[DataProvider('repliesOfNoUse')]
    public function aReplyOfNoUseIsRefusedWithTheReasonAndNothingIsWritten(string $kind, string $removal, string $expectedToast): void
    {
        $this->addTicket();
        $messageId = $this->addMessage('Omar', $kind, self::COMMENT, 'removed' === $removal);
        $this->logIn($this->client, $this->people['Mia']);

        $this->client->request('POST', $this->messagePath($messageId, 'replies'), $this->formFields('kept' === $removal ? " \n " : self::REPLY), [], self::TURBO_FORM_HEADERS);

        // Only the toast: a new message list would close the open Reply box and lose its draft.
        self::assertSame($expectedToast, $this->toastOf($this->streamsOfTheAnswer(422, isToastOnly: true)));
        self::assertSame([], $this->replyRows());
        self::assertSame(0, $this->outboxCount());
    }

    #[Test]
    public function aReplyRefusedFromAFormWithoutTurboRedirectsToTheThreadWithTheReason(): void
    {
        $this->addTicket();
        $messageId = $this->addMessage('Omar', 'comment', self::COMMENT, isRemoved: true);
        $this->logIn($this->client, $this->people['Mia']);

        $this->client->request('POST', $this->messagePath($messageId, 'replies'), $this->formFields(self::REPLY));

        self::assertSame('This message was removed, so you cannot reply to it.', $this->toastAfterTheRedirect());
        self::assertSame([], $this->replyRows());
        self::assertSame(0, $this->outboxCount());
    }

    /**
     * @return iterable<string, array{string}> who resolves Omar's comment
     */
    public static function peopleWhoMayResolve(): iterable
    {
        yield 'Omar, its author' => ['Omar'];
        yield 'Lena, the lead of the ticket\'s team' => ['Lena'];
    }

    #[Test]
    #[DataProvider('peopleWhoMayResolve')]
    public function theAuthorOrTheLeadResolvesAComment(string $resolver): void
    {
        $this->addTicket();
        $commentId = $this->addMessage('Omar', 'comment', self::COMMENT);
        $otherCommentId = $this->addMessage('Omar', 'comment', 'Another comment.');
        $this->logIn($this->client, $this->people[$resolver]);

        $this->client->request('POST', $this->messagePath($commentId, 'resolve'), $this->formFields(''), [], self::TURBO_FORM_HEADERS);

        $streams = $this->streamsOfTheAnswer(200);
        self::assertSame(ThreadController::COMMENT_RESOLVED, $this->toastOf($streams));
        self::assertSame(
            [[$commentId, 1], [$otherCommentId, 0]],
            $streams[Thread::MESSAGES_ID]->filter('.thread__message')->each(static fn (Crawler $message): array => [
                (string) $message->attr('data-message-id'),
                $message->filter('details.thread__fold')->count(),
            ]),
        );
        self::assertSame(
            [[$commentId, '2026-11-06 12:00:00+00', $this->people[$resolver]->id()->toString()], [$otherCommentId, null, null]],
            $this->resolutionsOf([$commentId, $otherCommentId]),
        );
        self::assertSame(1, $this->outboxCount());
    }

    /**
     * @return iterable<string, array{string}> who may see the ticket but neither wrote the comment nor leads its team
     */
    public static function peopleWhoMayNotResolve(): iterable
    {
        yield 'Mia, a viewer of the team' => ['Mia'];
        yield 'Sara, an admin' => ['Sara'];
    }

    #[Test]
    #[DataProvider('peopleWhoMayNotResolve')]
    public function someoneElseIsForbiddenToResolveAndTheCommentStaysOpen(string $person): void
    {
        $this->addTicket();
        $commentId = $this->addMessage('Omar', 'comment', self::COMMENT);
        $this->logIn($this->client, $this->people[$person]);

        $this->client->request('POST', $this->messagePath($commentId, 'resolve'), $this->formFields(''), [], self::TURBO_FORM_HEADERS);

        self::assertResponseStatusCodeSame(403);
        self::assertSame([[$commentId, null, null]], $this->resolutionsOf([$commentId]));
        self::assertSame(0, $this->outboxCount());
    }

    /**
     * Omar asked the question, and Lena leads the team: the voter lets both by, and the domain refuses either, as only
     * an answer closes a question (T3 criterion 2).
     */
    #[Test]
    #[DataProvider('peopleWhoMayResolve')]
    public function aQuestionIsRefusedResolveAndStaysOpen(string $resolver): void
    {
        $this->addTicket();
        $questionId = $this->addMessage('Omar', 'question', self::QUESTION);
        $this->logIn($this->client, $this->people[$resolver]);

        $this->client->request('POST', $this->messagePath($questionId, 'resolve'), $this->formFields(''), [], self::TURBO_FORM_HEADERS);

        self::assertSame('A question is closed by its answer, not by Resolve.', $this->toastOf($this->streamsOfTheAnswer(422)));
        self::assertSame([[$questionId, null, null]], $this->resolutionsOf([$questionId]));
        self::assertSame(0, $this->outboxCount());
    }

    #[Test]
    public function aResolvedCommentIsRefusedASecondResolve(): void
    {
        $this->addTicket();
        $commentId = $this->addMessage('Omar', 'comment', self::COMMENT);
        $this->logIn($this->client, $this->people['Omar']);
        $this->client->request('POST', $this->messagePath($commentId, 'resolve'), $this->formFields(''));
        self::assertResponseStatusCodeSame(303);
        $resolutionBefore = $this->resolutionsOf([$commentId]);
        self::assertNotNull($resolutionBefore[0][1]);
        $this->logIn($this->client, $this->people['Lena']);

        $this->client->request('POST', $this->messagePath($commentId, 'resolve'), $this->formFields(''), [], self::TURBO_FORM_HEADERS);

        self::assertSame('This comment is already resolved.', $this->toastOf($this->streamsOfTheAnswer(422)));
        self::assertSame($resolutionBefore, $this->resolutionsOf([$commentId]));
        self::assertSame(1, $this->outboxCount());
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
    public function someoneWhoMayNotSeeTheTicketGetsNotFoundToReplyingAndResolvingAndNothingChanges(string $person, string $visibility): void
    {
        $this->addTicket($visibility);
        $commentId = $this->addMessage('Omar', 'comment', self::COMMENT);
        $this->addBen();
        $this->logIn($this->client, $this->people[$person]);

        foreach (['replies', 'resolve'] as $action) {
            $this->client->request('POST', $this->messagePath($commentId, $action), $this->formFields(self::REPLY));
            self::assertResponseStatusCodeSame(404, $action);
            self::assertStringNotContainsString(self::COMMENT, (string) $this->client->getResponse()->getContent());
        }

        self::assertSame([], $this->replyRows());
        self::assertSame([[$commentId, null, null]], $this->resolutionsOf([$commentId]));
        self::assertSame(0, $this->outboxCount());
    }

    /**
     * @return iterable<string, array{string}> a message id no message of SHOP-1 has
     */
    public static function messageIdsOfNoMessage(): iterable
    {
        yield 'malformed' => ['not-a-uuid'];
        yield 'unknown' => ['0197a3c0-0000-7000-8000-00000000dead'];
        yield 'Omar\'s comment on SHOP-2, a team-only ticket Lena may see' => ['other-ticket'];
    }

    /**
     * Lena leads the team and may reply to and resolve Omar's comment on SHOP-2, but not through SHOP-1's path.
     */
    #[Test]
    #[DataProvider('messageIdsOfNoMessage')]
    public function aMessageIdOfNoMessageOfTheTicketIsNotFoundToReplyingAndResolving(string $messageId): void
    {
        $this->addTicket();
        $this->addMessage('Omar', 'comment', self::COMMENT);
        $this->addTicket('team', self::OTHER_KEY);
        $otherTicketsCommentId = $this->addMessage('Omar', 'comment', self::COMMENT, key: self::OTHER_KEY);
        $messageId = 'other-ticket' === $messageId ? $otherTicketsCommentId : $messageId;
        $this->logIn($this->client, $this->people['Lena']);

        foreach (['replies', 'resolve'] as $action) {
            $this->client->request('POST', $this->messagePath($messageId, $action), $this->formFields(self::REPLY), [], self::TURBO_FORM_HEADERS);
            self::assertResponseStatusCodeSame(404, $action);
        }

        self::assertSame([], $this->replyRows());
        self::assertSame([[$otherTicketsCommentId, null, null]], $this->resolutionsOf([$otherTicketsCommentId]));
        self::assertSame(0, $this->outboxCount());
    }

    #[Test]
    public function aMissingFormTokenIsRefusedOnBothRoutesAndNothingChanges(): void
    {
        $this->addTicket();
        $commentId = $this->addMessage('Omar', 'comment', self::COMMENT);
        $this->logIn($this->client, $this->people['Omar']);

        foreach (['replies', 'resolve'] as $action) {
            $this->client->request('POST', $this->messagePath($commentId, $action), [ThreadController::BODY_FIELD => self::REPLY], [], self::TURBO_FORM_HEADERS);
            self::assertSame(ThreadController::FORM_EXPIRED, $this->toastOf($this->streamsOfTheAnswer(422, isToastOnly: 'replies' === $action)), $action);
        }

        self::assertSame([], $this->replyRows());
        self::assertSame([[$commentId, null, null]], $this->resolutionsOf([$commentId]));
        self::assertSame(0, $this->outboxCount());
    }

    /**
     * Sara is outside the ticket's people and still named. A removed reply keeps its meta but not its text, and no
     * reply has an action: replies are one level only.
     */
    #[Test]
    public function theThreadShowsEachReplyUnderItsMessageWithNoActionOnIt(): void
    {
        $this->addTicket();
        $commentId = $this->addMessage('Omar', 'comment', self::COMMENT);
        $this->addReply($commentId, 'Sara', self::REPLY);
        $this->addReply($commentId, 'Lena', 'Removed later.', isRemoved: true);
        $otherCommentId = $this->addMessage('Lena', 'comment', 'Another comment.');

        $frame = $this->threadFrameAs('Mia');

        $messages = $frame->filter('.thread__message');
        self::assertSame(
            [['Sara · now', self::REPLY], ['Lena · now', 'Reply removed']],
            $messages->eq(0)->filter('.thread__reply')->each(static fn (Crawler $reply): array => [
                self::oneLineOf($reply->filter('.thread__meta')->text()),
                trim($reply->filter('.thread__body')->text()),
            ]),
        );
        self::assertCount(1, $messages->eq(0)->filter('.thread__reply .thread__body--removed'));
        self::assertCount(0, $messages->eq(1)->filter('.thread__reply'));
        self::assertCount(0, $frame->filter('.thread__reply form, .thread__reply summary, .thread__reply button'));
        self::assertSame(
            [$this->messagePath($commentId, 'replies'), $this->messagePath($otherCommentId, 'replies')],
            $frame->filter('.thread__reply-box form')->each(static fn (Crawler $form): string => (string) $form->attr('action')),
        );
    }

    /**
     * @return iterable<string, array{string, list<list<string>>}> the viewer and the actions each message offers:
     *                                                             Omar's open comment, his question to Lena, his
     *                                                             removed comment, his resolved comment, Lena's comment
     */
    public static function actionsByViewer(): iterable
    {
        yield 'Omar, the author' => ['Omar', [['Reply', 'Resolve'], ['Reply'], [], ['Reply'], ['Reply']]];
        yield 'Lena, the lead and the person asked' => ['Lena', [['Reply', 'Resolve'], ['Answer'], [], ['Reply'], ['Reply', 'Resolve']]];
        yield 'Mia, a viewer of the team' => ['Mia', [['Reply'], ['Reply'], [], ['Reply'], ['Reply']]];
        yield 'Sara, an admin' => ['Sara', [['Reply'], ['Reply'], [], ['Reply'], ['Reply']]];
    }

    /**
     * One reply button per message, Answer for the person an open question asks; Resolve only on an open comment and
     * only for its author or the team's lead (T3 criterion 2: never on a question).
     *
     * @param list<list<string>> $expectedActions
     */
    #[Test]
    #[DataProvider('actionsByViewer')]
    public function eachMessageOffersReplyOrAnswerAndResolveOnlyToTheAuthorOrTheLead(string $viewer, array $expectedActions): void
    {
        $this->addTicket();
        $openCommentId = $this->addMessage('Omar', 'comment', self::COMMENT);
        $this->addMessage('Omar', 'question', self::QUESTION);
        $this->addMessage('Omar', 'comment', 'Removed comment.', isRemoved: true);
        $resolvedCommentId = $this->addMessage('Omar', 'comment', 'Resolved comment.');
        $this->resolve($resolvedCommentId, 'Lena');
        $leadsCommentId = $this->addMessage('Lena', 'comment', 'Another comment.');

        $frame = $this->threadFrameAs($viewer);

        self::assertSame(
            $expectedActions,
            $frame->filter('.thread__message')->each(static fn (Crawler $message): array => $message
                ->filter('.thread__answer > summary, .thread__reply-box > summary, .thread__resolve-form button')
                ->each(static fn (Crawler $action): string => trim($action->text()))),
        );
        $expectedResolvePaths = array_values(array_filter([
            [] !== array_intersect(['Resolve'], $expectedActions[0]) ? $this->messagePath($openCommentId, 'resolve') : null,
            [] !== array_intersect(['Resolve'], $expectedActions[4]) ? $this->messagePath($leadsCommentId, 'resolve') : null,
        ], static fn (?string $path): bool => null !== $path));
        self::assertSame($expectedResolvePaths, $frame->filter('.thread__resolve-form')->each(static fn (Crawler $form): string => (string) $form->attr('action')));
    }

    /**
     * A resolved comment folds into one closed line for everyone, its text cut after 60 characters on one line;
     * opened, it shows the message and its replies. An open comment does not fold.
     */
    #[Test]
    public function aResolvedCommentFoldsIntoOneLineWithItsReplyCount(): void
    {
        $this->addTicket();
        // The 60th character is the space after "has", which the line drops before its ellipsis.
        $longCommentId = $this->addMessage('Omar', 'comment', "The cart total is off by the shipping fee\nwhen an order has two items in it.");
        $this->addReply($longCommentId, 'Lena', self::REPLY);
        $this->addReply($longCommentId, 'Mia', 'Removed later.', isRemoved: true);
        $this->resolve($longCommentId, 'Omar');
        $shortCommentId = $this->addMessage('Lena', 'comment', 'Fixed in build 42.');
        $this->addReply($shortCommentId, 'Omar', 'Thanks.');
        $this->resolve($shortCommentId, 'Lena');
        $silentCommentId = $this->addMessage('Omar', 'comment', 'Café menu is fine.');
        $this->resolve($silentCommentId, 'Omar');
        $this->addMessage('Omar', 'comment', self::COMMENT);
        $removedCommentId = $this->addMessage('Omar', 'comment', 'Removed after it was resolved.', isRemoved: true);
        $this->resolve($removedCommentId, 'Omar');
        // 60 and 61 characters, with "é" taking two bytes, so a byte count would cut the first one too.
        $sixtyCharacters = 'Café orders over fifty euros fail at checkout on most Friday';
        $sixtyOneCharacters = $sixtyCharacters.'s';
        $this->resolve($this->addMessage('Omar', 'comment', $sixtyCharacters), 'Omar');
        $this->resolve($this->addMessage('Omar', 'comment', $sixtyOneCharacters), 'Omar');

        $frame = $this->threadFrameAs('Mia');

        $folds = $frame->filter('.thread__message details.thread__fold');
        self::assertSame(
            [
                ['✓ Resolved: The cart total is off by the shipping fee when an order has… · 2 replies · show', 'Hide', null],
                ['✓ Resolved: Fixed in build 42. · 1 reply · show', 'Hide', null],
                ['✓ Resolved: Café menu is fine. · show', 'Hide', null],
                ['✓ Resolved: Message removed · show', 'Hide', null],
                ['✓ Resolved: Café orders over fifty euros fail at checkout on most Friday · show', 'Hide', null],
                ['✓ Resolved: Café orders over fifty euros fail at checkout on most Friday… · show', 'Hide', null],
            ],
            $folds->each(static fn (Crawler $fold): array => [
                self::oneLineOf($fold->filter('summary .thread__fold-line')->text()),
                trim($fold->filter('summary .thread__fold-hide')->text()),
                $fold->attr('open'),
            ]),
        );
        self::assertSame(self::REPLY, trim($folds->eq(0)->filter('.thread__reply .thread__body')->first()->text()));
        self::assertCount(0, $frame->filter('.thread__message')->eq(3)->filter('.thread__fold'));
        self::assertSame(self::COMMENT, trim($frame->filter('.thread__message')->eq(3)->filter('.thread__body')->text()));
    }

    /**
     * @return iterable<string, array{string, string}> the viewer and the note under the reply box of Omar's comment
     */
    public static function replyNotesByViewer(): iterable
    {
        yield 'Mia, replying to Omar' => ['Mia', 'Replies stay under this message. Omar gets one notification.'];
        yield 'Omar, replying to himself' => ['Omar', 'Replies stay under this message.'];
    }

    #[Test]
    #[DataProvider('replyNotesByViewer')]
    public function theReplyNoteNamesTheAuthorUnlessTheViewerWroteTheMessage(string $viewer, string $expectedNote): void
    {
        $this->addTicket();
        $this->addMessage('Omar', 'comment', self::COMMENT);

        $frame = $this->threadFrameAs($viewer);

        self::assertSame([$expectedNote], $frame->filter('.thread__reply-box .thread__note')->each(static fn (Crawler $note): string => trim($note->text())));
    }

    /**
     * MessageView::REPLY_LIMIT replies are listed, the newest; a message with more says so, one with fewer does not.
     */
    #[Test]
    public function aMessageWithMoreRepliesThanTheLimitShowsTheNewestAndSaysSo(): void
    {
        $this->addTicket();
        $busyCommentId = $this->addMessage('Omar', 'comment', self::COMMENT);
        $this->addNumberedReplies($busyCommentId, MessageView::REPLY_LIMIT + 1);
        $quietCommentId = $this->addMessage('Lena', 'comment', 'Another comment.');
        $this->addReply($quietCommentId, 'Omar', self::REPLY);

        $frame = $this->threadFrameAs('Mia');

        $messages = $frame->filter('.thread__message');
        $replyBodies = $messages->eq(0)->filter('.thread__reply .thread__body')->each(static fn (Crawler $body): string => trim($body->text()));
        self::assertCount(MessageView::REPLY_LIMIT, $replyBodies);
        self::assertSame(['Reply 2', 'Reply 51'], [$replyBodies[0], $replyBodies[MessageView::REPLY_LIMIT - 1]]);
        self::assertSame(['Showing the latest 50 replies.'], $messages->eq(0)->filter('.thread__earlier-replies')->each(static fn (Crawler $note): string => trim($note->text())));
        self::assertCount(1, $messages->eq(1)->filter('.thread__reply'));
        self::assertCount(0, $messages->eq(1)->filter('.thread__earlier-replies'));
    }

    private function threadFrameAs(string $viewer): Crawler
    {
        $this->logIn($this->client, $this->people[$viewer]);
        $frame = $this->client->request('GET', '/t/'.self::KEY.'/thread', [], [], ['HTTP_TURBO_FRAME' => Thread::FRAME_ID]);
        self::assertResponseStatusCodeSame(200);

        return $frame;
    }

    private static function oneLineOf(string $text): string
    {
        return (string) preg_replace('/\s+/u', ' ', trim($text));
    }

    /**
     * Asserts the answer is a 303 to the thread's page and returns the one toast that page shows.
     */
    private function toastAfterTheRedirect(): string
    {
        self::assertResponseStatusCodeSame(303);
        self::assertSame('/t/'.self::KEY.'/thread', $this->client->getResponse()->headers->get('Location'));
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
     * @return array<string, string>
     */
    private function formFields(string $body): array
    {
        return [
            ThreadController::CSRF_FIELD => $this->csrfTokenFor($this->client, ThreadController::csrfIdOf(self::KEY)),
            ThreadController::BODY_FIELD => $body,
        ];
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

    /**
     * A comment, or a question to Lena, by $author a minute before now, on SHOP-1 unless $key says otherwise. Seeded
     * without pausing the ticket: replying and resolving read no pause.
     *
     * @return string its id
     */
    private function addMessage(string $author, string $kind, string $body, bool $isRemoved = false, string $key = self::KEY): string
    {
        $messageId = Uuid::v7()->toRfc4122();
        $parameters = [
            $messageId, $this->people[$author]->id()->toString(), $kind, 'question' === $kind ? $this->people['Lena']->id()->toString() : null, $body,
            $isRemoved ? '2026-11-06 11:59:30+00' : null, $this->acmeFoods->toString(), $key,
        ];
        $this->committedAs($this->acmeFoods, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            "INSERT INTO cnv_message (organization_id, id, ticket_id, author_id, kind, to_user_id, body, removed_at, created_at)
             SELECT organization_id, ?, id, ?, ?, ?, ?, ?, TIMESTAMPTZ '2026-11-06 11:59:00+00' FROM tkt_ticket WHERE organization_id = ? AND key = ?",
            $parameters,
        ));

        return $messageId;
    }

    /**
     * @return string its id
     */
    private function addReply(string $messageId, string $author, string $body, bool $isRemoved = false): string
    {
        $replyId = Uuid::v7()->toRfc4122();
        $parameters = [$this->acmeFoods->toString(), $replyId, $messageId, $this->people[$author]->id()->toString(), $body, $isRemoved ? '2026-11-06 11:59:40+00' : null];
        $this->committedAs($this->acmeFoods, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            "INSERT INTO cnv_reply (organization_id, id, message_id, author_id, body, is_answer, removed_at, created_at)
             VALUES (?, ?, ?, ?, ?, false, ?, TIMESTAMPTZ '2026-11-06 11:59:20+00')",
            $parameters,
        ));

        return $replyId;
    }

    /**
     * Replies "Reply 1" to "Reply <count>" by Lena, a second apart and oldest first, in one statement.
     */
    private function addNumberedReplies(string $messageId, int $count): void
    {
        $parameters = [$this->acmeFoods->toString(), $messageId, $this->people['Lena']->id()->toString(), $count];
        $this->committedAs($this->acmeFoods, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            "INSERT INTO cnv_reply (organization_id, id, message_id, author_id, body, is_answer, created_at)
             SELECT ?::uuid, gen_random_uuid(), ?::uuid, ?::uuid, 'Reply ' || n, false, TIMESTAMPTZ '2026-11-06 11:00:00+00' + n * INTERVAL '1 second'
             FROM generate_series(1, ?::int) AS n",
            $parameters,
        ));
    }

    private function resolve(string $messageId, string $resolver): void
    {
        $parameters = [$this->people[$resolver]->id()->toString(), $this->acmeFoods->toString(), $messageId];
        $this->committedAs($this->acmeFoods, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            "UPDATE cnv_message SET resolved_at = TIMESTAMPTZ '2026-11-06 11:59:50+00', resolved_by = ? WHERE organization_id = ? AND id = ?",
            $parameters,
        ));
    }

    /**
     * @return list<array{string, string, string, bool}> message id, author id, body and whether it is the answer
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
     * @param list<string> $messageIds
     *
     * @return list<array{string, string|null, string|null}> each message's id, when it was resolved in UTC and by whom,
     *                                                       in the order of $messageIds
     */
    private function resolutionsOf(array $messageIds): array
    {
        $organizationId = $this->acmeFoods->toString();
        $rows = [];
        $this->committedAs($this->acmeFoods, static function (Connection $migrator) use ($organizationId, &$rows): int {
            $rows = $migrator->fetchAllAssociativeIndexed(
                "SELECT id::text, to_char(resolved_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS') || '+00' AS resolved_at, resolved_by::text AS resolved_by
                 FROM cnv_message WHERE organization_id = ?",
                [$organizationId],
            );

            return 0;
        });

        return array_map(static function (string $messageId) use ($rows): array {
            self::assertArrayHasKey($messageId, $rows);
            $resolvedAt = $rows[$messageId]['resolved_at'];
            $resolvedBy = $rows[$messageId]['resolved_by'];
            \assert((null === $resolvedAt || \is_string($resolvedAt)) && (null === $resolvedBy || \is_string($resolvedBy)));

            return [$messageId, $resolvedAt, $resolvedBy];
        }, $messageIds);
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
