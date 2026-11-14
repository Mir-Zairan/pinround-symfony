<?php

declare(strict_types=1);

namespace App\Conversation\Application\AskQuestion;

use App\Conversation\Api\Event\QuestionAsked;
use App\Conversation\Api\MessageBodyRefused;
use App\Conversation\Api\QuestionRefused;
use App\Conversation\Application\ConversationEvents;
use App\Conversation\Domain\Message;
use App\Conversation\Domain\Messages;
use App\Conversation\Domain\QuestionAlreadyOpen;
use App\Insight\Api\TurnProjector;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\TicketTurnSnapshot;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Api\TicketAlreadyPaused;
use App\Ticketing\Api\TicketChangedMeanwhile;
use App\Ticketing\Api\TicketNotFound;
use App\Ticketing\Api\TicketPauses;
use App\Ticketing\Api\TicketTurnQueries;
use App\Ticketing\Api\TicketVisibilities;
use App\Ticketing\Api\TicketVisibilityFacts;

/**
 * Asks one person on the ticket a question (FR-CNV-02): the ticket pauses on its step and the turn goes to the person
 * asked until they answer or the asker withdraws the question. A ticket waits on one question at a time (FR-CNV-08).
 */
final readonly class AskQuestion
{
    public const string NOT_ON_THE_TICKET = 'You can ask only someone on this ticket.';
    public const string ALREADY_WAITING = 'This ticket already waits for an answer.';

    public function __construct(
        private TicketVisibilities $ticketVisibilities,
        private TicketTurnQueries $ticketTurnQueries,
        private TicketPauses $ticketPauses,
        private TurnProjector $turnProjector,
        private Messages $messages,
        private ConversationEvents $conversationEvents,
        private TenantContext $tenantContext,
    ) {
    }

    /**
     * Pauses the ticket, refreshes its turn, saves the question and writes QuestionAsked to the outbox, all in the open
     * transaction, whose tenant must be the ticket's organization. The ticket's row is locked first, so two asks
     * cannot both pass the one-question check. That the asker may ask at all (ConversationPermission ASK) is the
     * caller's check.
     *
     * @param TicketVisibilityFacts $ticket the visibility facts of the ticket $ticketId, as the ticket page read them
     *
     * @return string the new question's id
     *
     * @throws TicketNotFound         when the ticket is of another organization than the tenant, or the asker may not
     *                                see it; nothing is saved
     * @throws MessageBodyRefused     when the trimmed body is empty or longer than 10,000 characters; nothing is saved
     * @throws QuestionRefused        when the person asked is the asker or not on the ticket (its assignee, reporter,
     *                                reviewer, tester or team lead), or the ticket already waits for an answer; nothing
     *                                is saved, though after a refusal the database raised the caller must roll back
     * @throws TicketChangedMeanwhile when another request saved the ticket after this request read it; the caller rolls
     *                                back
     * @throws \LogicException        when no transaction is open; nothing is saved
     */
    public function ask(TicketId $ticketId, TicketVisibilityFacts $ticket, UserId $askerId, UserId $askedUserId, string $body, \DateTimeImmutable $askedAt): string
    {
        // The visibility predicate trusts the organization it is given: a ticket for everyone of any organization passes it.
        if (true !== $this->tenantContext->get()?->equals($ticket->organizationId)
            || !$this->ticketVisibilities->of($ticket->organizationId, $askerId)->isVisible($ticket)) {
            throw new TicketNotFound('That ticket does not exist.');
        }

        try {
            $question = Message::question($this->messages->nextIdentity(), $ticket->organizationId, $ticketId, $askerId, $askedUserId, trim($body), $askedAt);
        } catch (\InvalidArgumentException $invalidBody) {
            throw new MessageBodyRefused($invalidBody->getMessage(), 0, $invalidBody);
        }
        $facts = $this->ticketTurnQueries->factsOf($ticketId) ?? throw new TicketNotFound('That ticket does not exist.');
        if ($askedUserId->equals($askerId) || !self::isListedOn($facts, $askedUserId)) {
            throw new QuestionRefused(self::NOT_ON_THE_TICKET);
        }

        try {
            $this->ticketPauses->pauseForQuestion($ticketId, $askedUserId, $askerId);
        } catch (TicketAlreadyPaused $alreadyPaused) {
            throw new QuestionRefused(self::ALREADY_WAITING, 0, $alreadyPaused);
        }
        $this->turnProjector->refresh($ticketId);
        $this->conversationEvents->publish(new QuestionAsked(
            $ticket->organizationId->toString(),
            $ticketId->toString(),
            $question->id()->toString(),
            $askedUserId->toString(),
            $askerId->toString(),
            $askedAt,
        ));
        try {
            $this->messages->add($question);
        } catch (QuestionAlreadyOpen $alreadyOpen) {
            throw new QuestionRefused(self::ALREADY_WAITING, 0, $alreadyOpen);
        }

        return $question->id()->toString();
    }

    private static function isListedOn(TicketTurnSnapshot $facts, UserId $userId): bool
    {
        foreach ([$facts->assigneeId, $facts->reporterId, $facts->reviewerId, $facts->testerId, $facts->leadId] as $listedId) {
            if (null !== $listedId && $listedId->equals($userId)) {
                return true;
            }
        }

        return false;
    }
}
