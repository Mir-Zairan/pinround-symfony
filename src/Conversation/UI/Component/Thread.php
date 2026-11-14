<?php

declare(strict_types=1);

namespace App\Conversation\UI\Component;

use App\Conversation\Api\MessageView;
use App\Conversation\Api\ThreadQueries;
use App\Conversation\UI\ThreadController;
use App\Conversation\UI\ThreadMessage;
use App\Identity\Api\CurrentTimeZone;
use App\Identity\Api\CurrentUser;
use App\Identity\Api\UserQueries;
use App\SharedKernel\UI\RelativeTime;
use App\Ticketing\Api\TicketPageView;
use Psr\Clock\ClockInterface;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * The ticket's Questions and comments (ticket-page.md, Questions and comments): the thread and the composer, which
 * ThreadController serves into ThreadFrame's lazy frame. Shown only on a ticket the viewer may see, and seeing it is
 * what ConversationPermission COMMENT grants, so the composer is shown to every viewer; ThreadController checks the
 * voter on every request.
 *
 * Reads the thread in one query and the names of authors outside the ticket's people in one more, so it needs the
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

    public string $key = '';

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
    ) {
    }

    public function mount(TicketPageView $ticket): void
    {
        $thread = $this->threadQueries->threadOf($ticket->id);
        $names = $this->authorNamesOf($thread->messages, $ticket->displayNames);
        $viewerId = $this->currentUser->id();
        $now = $this->clock->now();
        $zone = $this->currentTimeZone->zone();
        $utc = new \DateTimeZone('UTC');

        $this->key = $ticket->key;
        $this->hasEarlierMessages = $thread->hasEarlierMessages;
        $this->messages = array_map(static function (MessageView $message) use ($names, $viewerId, $now, $zone, $utc): ThreadMessage {
            // EditMessage and RemoveMessage refuse a question, so its author is offered neither.
            $isOwnComment = MessageView::KIND_COMMENT === $message->kind
                && null !== $viewerId && $viewerId->equals($message->authorId);

            return new ThreadMessage(
                $message->id,
                $names[$message->authorId->toString()] ?? self::UNKNOWN_AUTHOR,
                $message->kind,
                $message->isRemoved ? null : $message->body,
                RelativeTime::sinceOf($message->createdAt, $now, $zone),
                $message->createdAt->setTimezone($utc)->format(\DateTimeInterface::ATOM),
                null !== $message->editedAt,
                $message->isRemoved,
                $isOwnComment && !$message->isRemoved && $now <= $message->editableUntil,
                $isOwnComment && !$message->isRemoved,
            );
        }, $thread->messages);
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
     * The ticket page's names first; one query for the authors outside the ticket's people, none when there are none.
     *
     * @param list<MessageView>     $messages
     * @param array<string, string> $displayNames by user id string
     *
     * @return array<string, string> display names by user id string
     */
    private function authorNamesOf(array $messages, array $displayNames): array
    {
        $missingIds = [];
        foreach ($messages as $message) {
            $authorId = $message->authorId->toString();
            if (!isset($displayNames[$authorId])) {
                $missingIds[$authorId] = $message->authorId;
            }
        }
        foreach ($this->userQueries->byIds(array_values($missingIds)) as $userId => $user) {
            $displayNames[$userId] = $user->displayName;
        }

        return $displayNames;
    }
}
