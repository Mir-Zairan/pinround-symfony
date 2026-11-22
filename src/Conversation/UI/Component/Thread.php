<?php

declare(strict_types=1);

namespace App\Conversation\UI\Component;

use App\Conversation\Api\ConversationPermission;
use App\Conversation\Api\MessageView;
use App\Conversation\Api\ReplyView;
use App\Conversation\Api\ThreadQueries;
use App\Conversation\UI\AskablePerson;
use App\Conversation\UI\ThreadController;
use App\Conversation\UI\ThreadMessage;
use App\Conversation\UI\ThreadReply;
use App\Conversation\UI\Voter\MessageOnTicket;
use App\Identity\Api\CurrentTimeZone;
use App\Identity\Api\CurrentUser;
use App\Identity\Api\UserQueries;
use App\Identity\Api\UserView;
use App\SharedKernel\Domain\UserId;
use App\SharedKernel\UI\RelativeTime;
use App\Ticketing\Api\TicketPageView;
use Psr\Clock\ClockInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * The ticket's Questions and comments (ticket-page.md, Questions and comments): the thread and the composer, which
 * ThreadController serves into ThreadFrame's lazy frame. Shown only on a ticket the viewer may see, and seeing it is
 * what ConversationPermission COMMENT grants, so the composer is shown to every viewer; ThreadController checks the
 * voter on every request. The composer's Ask a question is on only for a viewer ConversationPermission ASK grants,
 * with someone else on the ticket to ask, while no question pauses the ticket.
 *
 * Every message offers Reply, or Answer to the person an open question asks; Resolve only where MessageVoter grants it,
 * and a resolved comment folds into one line.
 *
 * Reads the thread in one query, every message's replies in one more, the names of authors outside the ticket's people
 * in one more, and the viewer's roles once when an open comment is one the viewer wrote or leads, so it needs the
 * request's transaction under the tenant.
 */
#[AsTwigComponent(name: 'Thread', template: '@Conversation/components/Thread.html.twig')]
final class Thread
{
    /** The Turbo Frame ThreadController's thread route fills. */
    public const string FRAME_ID = 'ticket-thread';
    /**
     * The message list, the only element a Turbo Stream replaces after a comment, an edit or a removal: the composer
     * stays, so the text being typed and the focus are not lost.
     */
    public const string MESSAGES_ID = 'ticket-thread-messages';
    public const string EMPTY_THREAD = 'Nothing yet. Ask a question when you need someone’s answer, or leave a comment.';
    /** The name of an author Identity no longer knows. */
    public const string UNKNOWN_AUTHOR = 'Someone';
    public const string COMMENT_PLACEHOLDER = 'Write a comment';
    public const string COMMENT_NOTE = 'A comment does not change the status and sends no notification.';
    public const string COMMENT_BUTTON = 'Comment';
    /** The toast when My reports' "Add more info" opens the ticket on the composer (thread-composer's ?write=comment). */
    public const string WRITE_INFO_TOAST = 'Write your info in the comment box.';
    public const string ASK_PLACEHOLDER = 'What do you need to know?';
    /** With the first name of the person asked. */
    public const string ASK_NOTE = 'A question pauses the ticket until %s answers. They get one notification.';
    /** With the first name of the person asked. */
    public const string ASK_BUTTON = 'Ask %s';
    public const string WAIT_FOR_THE_OPEN_QUESTION = 'Wait for the open question first.';
    /** With the name of the ticket's step, which a pause leaves unchanged. */
    public const string ANSWER_NOTE = 'Your answer closes the question. The ticket goes back to %s.';
    public const string REPLY_PLACEHOLDER = 'Write a reply';
    /** With the first name of the message's author. */
    public const string REPLY_NOTE = 'Replies stay under this message. %s gets one notification.';
    /** A reply to the viewer's own message notifies nobody. */
    public const string REPLY_NOTE_ON_YOUR_OWN = 'Replies stay under this message.';
    public const string REMOVED_MESSAGE = 'Message removed';
    public const string REMOVED_REPLY = 'Reply removed';
    /** How many characters of a resolved comment its folded line shows. */
    public const int RESOLVED_PREVIEW_LENGTH = 60;

    public string $key = '';

    /** The step the ticket goes back to when its question is answered. */
    public string $stepName = '';

    /** @var list<AskablePerson> whom the viewer may ask, the first chosen by default */
    public array $askablePeople = [];

    /** True when ConversationPermission ASK grants the viewer a question on the ticket and someone is there to ask. */
    public bool $mayAsk = false;

    /** True while a question pauses the ticket: a second one is refused until it is answered. */
    public bool $hasOpenQuestion = false;

    /** @var list<ThreadMessage> oldest first */
    public array $messages = [];

    public bool $hasEarlierMessages = false;

    /** Renders only the message list, for a Turbo Stream. */
    public bool $isListOnly = false;

    public function __construct(
        private readonly ThreadQueries $threadQueries,
        private readonly UserQueries $userQueries,
        private readonly CurrentUser $currentUser,
        private readonly CurrentTimeZone $currentTimeZone,
        private readonly ClockInterface $clock,
        private readonly AuthorizationCheckerInterface $authorizationChecker,
    ) {
    }

    public function mount(TicketPageView $ticket): void
    {
        $thread = $this->threadQueries->threadOf($ticket->id);
        $names = $this->namesOfThePeopleIn($thread->messages, $ticket->displayNames);
        $viewerId = $this->currentUser->id();
        $resolvableComments = $this->resolvableCommentsAmong($thread->messages, $ticket, $viewerId);
        $now = $this->clock->now();
        $zone = $this->currentTimeZone->zone();
        $utc = new \DateTimeZone('UTC');

        $this->key = $ticket->key;
        $this->stepName = $ticket->stepName;
        $this->askablePeople = self::askablePeopleOf($ticket, $viewerId);
        $this->mayAsk = [] !== $this->askablePeople
            && $this->authorizationChecker->isGranted(ConversationPermission::ASK, $ticket->visibilityFacts);
        $this->hasOpenQuestion = null !== $ticket->turnSnapshot->askedUserId;
        $this->hasEarlierMessages = $thread->hasEarlierMessages;
        $this->messages = array_map(static function (MessageView $message) use ($names, $resolvableComments, $viewerId, $now, $zone, $utc): ThreadMessage {
            $isOwnMessage = null !== $viewerId && $viewerId->equals($message->authorId);
            // EditMessage and RemoveMessage refuse a question, so its author is offered neither.
            $isOwnComment = MessageView::KIND_COMMENT === $message->kind && $isOwnMessage;
            $isAnswerable = $message->isOpenQuestion && null !== $viewerId && null !== $message->toUserId && $viewerId->equals($message->toUserId);
            $authorName = $names[$message->authorId->toString()] ?? self::UNKNOWN_AUTHOR;
            $asked = null === $message->toUserId
                ? null
                : new UserView($message->toUserId, $names[$message->toUserId->toString()] ?? self::UNKNOWN_AUTHOR);

            return new ThreadMessage(
                $message->id,
                $authorName,
                $message->kind,
                $message->isRemoved ? null : $message->body,
                RelativeTime::sinceOf($message->createdAt, $now, $zone),
                $message->createdAt->setTimezone($utc)->format(\DateTimeInterface::ATOM),
                null !== $message->editedAt,
                $message->isRemoved,
                $isOwnComment && !$message->isRemoved && $now <= $message->editableUntil,
                $isOwnComment && !$message->isRemoved,
                $isAnswerable,
                $asked?->displayName,
                $asked?->firstName(),
                $message->isOpenQuestion,
                $message->isAnswered,
                array_map(static fn (ReplyView $reply): ThreadReply => new ThreadReply(
                    $reply->id,
                    $names[$reply->authorId->toString()] ?? self::UNKNOWN_AUTHOR,
                    $reply->isRemoved ? null : $reply->body,
                    RelativeTime::sinceOf($reply->createdAt, $now, $zone),
                    $reply->createdAt->setTimezone($utc)->format(\DateTimeInterface::ATOM),
                    $reply->isAnswer,
                    $reply->isRemoved,
                ), $message->replies),
                $message->hasEarlierReplies,
                !$message->isRemoved && !$isAnswerable,
                $isOwnMessage ? self::REPLY_NOTE_ON_YOUR_OWN : \sprintf(self::REPLY_NOTE, new UserView($message->authorId, $authorName)->firstName()),
                isset($resolvableComments[$message->id]),
                $message->isResolved ? self::resolvedSummaryOf($message) : null,
            );
        }, $thread->messages);
    }

    /**
     * The open comments the viewer wrote or leads, picked without a query, then one MessageVoter call for them all:
     * past its author-or-lead check only the ticket's visibility is left, which is the same for every message on it,
     * and each call costs a query. A question, a removed or a resolved comment is never offered.
     *
     * @param list<MessageView> $messages
     *
     * @return array<string, true> the ids of the comments ConversationPermission RESOLVE grants the viewer, by id string
     */
    private function resolvableCommentsAmong(array $messages, TicketPageView $ticket, ?UserId $viewerId): array
    {
        if (null === $viewerId) {
            return [];
        }
        $leadId = $ticket->turnSnapshot->leadId;
        $isLead = null !== $leadId && $viewerId->equals($leadId);
        $candidates = array_values(array_filter(
            $messages,
            static fn (MessageView $message): bool => MessageView::KIND_COMMENT === $message->kind && !$message->isRemoved && !$message->isResolved
                && ($isLead || $viewerId->equals($message->authorId)),
        ));
        // A deny-by-default backstop for the whole list: the page's own visibility check already covers it, and the
        // Resolve action itself is guarded by ResolveComment's check.
        if ([] === $candidates || !$this->authorizationChecker->isGranted(
            ConversationPermission::RESOLVE,
            new MessageOnTicket($ticket->visibilityFacts, $candidates[0]->authorId, $leadId),
        )) {
            return [];
        }

        return array_fill_keys(array_map(static fn (MessageView $message): string => $message->id, $candidates), true);
    }

    /**
     * "Resolved: <the first 60 characters on one line>… · N replies"; the reply count is left out when there is none.
     */
    private static function resolvedSummaryOf(MessageView $message): string
    {
        $text = null === $message->body ? self::REMOVED_MESSAGE : (string) preg_replace('/\s+/u', ' ', trim($message->body));
        $summary = 'Resolved: '.(mb_strlen($text) > self::RESOLVED_PREVIEW_LENGTH ? rtrim(mb_substr($text, 0, self::RESOLVED_PREVIEW_LENGTH)).'…' : $text);

        return match ($message->replyCount) {
            0 => $summary,
            1 => $summary.' · 1 reply',
            default => \sprintf('%s · %d replies', $summary, $message->replyCount),
        };
    }

    public function csrfId(): string
    {
        return ThreadController::csrfIdOf($this->key);
    }

    public function maxBodyLength(): int
    {
        return ThreadController::BODY_MAX_LENGTH;
    }

    /**
     * Whom AskQuestion lets the viewer ask: the ticket's people but the viewer, leaving out a person Identity no longer
     * knows, whose chip would have no name.
     *
     * @return list<AskablePerson>
     */
    private static function askablePeopleOf(TicketPageView $ticket, ?UserId $viewerId): array
    {
        $people = [];
        foreach ($ticket->turnSnapshot->peopleOnTheTicket() as $personId) {
            $name = $ticket->displayNames[$personId->toString()] ?? null;
            if (null !== $name && (null === $viewerId || !$viewerId->equals($personId))) {
                $people[] = new AskablePerson($personId->toString(), new UserView($personId, $name)->firstName());
            }
        }

        return $people;
    }

    /**
     * The ticket page's names first; one query for the authors of messages and replies and the people questions ask
     * outside the ticket's people, none when there are none.
     *
     * @param list<MessageView>     $messages
     * @param array<string, string> $displayNames by user id string
     *
     * @return array<string, string> display names by user id string
     */
    private function namesOfThePeopleIn(array $messages, array $displayNames): array
    {
        $missingIds = [];
        foreach ($messages as $message) {
            $replyAuthorIds = array_map(static fn (ReplyView $reply): UserId => $reply->authorId, $message->replies);
            foreach ([$message->authorId, $message->toUserId, ...$replyAuthorIds] as $personId) {
                if (null !== $personId && !isset($displayNames[$personId->toString()])) {
                    $missingIds[$personId->toString()] = $personId;
                }
            }
        }
        foreach ($this->userQueries->byIds(array_values($missingIds)) as $userId => $user) {
            $displayNames[$userId] = $user->displayName;
        }

        return $displayNames;
    }
}
