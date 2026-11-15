<?php

declare(strict_types=1);

namespace App\Tests\Unit\Conversation\Application;

use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Api\TicketAlreadyPaused;
use App\Ticketing\Api\TicketNotFound;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The double refuses as LockedTicketPauses does: each class and message here is the one the real port throws, as
 * TicketPausesTest pins them on the database or LockedTicketPauses and Ticket write them.
 */
final class InMemoryTicketPausesTest extends TestCase
{
    private const string TICKET = '0197a3c0-0000-7000-8000-00000000e904';
    private const string PAUSED_TICKET = '0197a3c0-0000-7000-8000-00000000e905';
    private const string UNKNOWN_TICKET = '0197a3c0-0000-7000-8000-00000000e906';
    private const string LENA = '0197a3c0-0000-7000-8000-00000000e911';
    private const string OMAR = '0197a3c0-0000-7000-8000-00000000e912';

    private InMemoryTicketPauses $ticketPauses;

    protected function setUp(): void
    {
        $this->ticketPauses = new InMemoryTicketPauses([self::TICKET, self::PAUSED_TICKET], [self::PAUSED_TICKET]);
    }

    #[Test]
    public function aQuestionPausesTheTicketAndEndingThePauseResumesIt(): void
    {
        $this->ticketPauses->pauseForQuestion(new TicketId(self::TICKET), new UserId(self::OMAR), new UserId(self::LENA));
        self::assertSame([self::TICKET, self::PAUSED_TICKET], $this->ticketPauses->pausedTicketIds());

        $this->ticketPauses->endQuestionPause(new TicketId(self::PAUSED_TICKET));

        self::assertSame([self::TICKET], $this->ticketPauses->pausedTicketIds());
    }

    #[Test]
    public function pausingATicketTheOrganizationDoesNotHaveIsNotFound(): void
    {
        try {
            $this->ticketPauses->pauseForQuestion(new TicketId(self::UNKNOWN_TICKET), new UserId(self::OMAR), new UserId(self::LENA));
            self::fail('A ticket the organization does not have was paused.');
        } catch (TicketNotFound $refusal) {
            self::assertSame('The organization has no ticket with this id.', $refusal->getMessage());
        }

        self::assertSame([self::PAUSED_TICKET], $this->ticketPauses->pausedTicketIds());
    }

    #[Test]
    public function endingThePauseOfATicketTheOrganizationDoesNotHaveIsNotFound(): void
    {
        try {
            $this->ticketPauses->endQuestionPause(new TicketId(self::UNKNOWN_TICKET));
            self::fail('The pause of a ticket the organization does not have was ended.');
        } catch (TicketNotFound $refusal) {
            self::assertSame('The organization has no ticket with this id.', $refusal->getMessage());
        }

        self::assertSame([self::PAUSED_TICKET], $this->ticketPauses->pausedTicketIds());
    }

    #[Test]
    public function aPausedTicketRefusesASecondQuestion(): void
    {
        try {
            $this->ticketPauses->pauseForQuestion(new TicketId(self::PAUSED_TICKET), new UserId(self::OMAR), new UserId(self::LENA));
            self::fail('A paused ticket took a second question.');
        } catch (TicketAlreadyPaused $refusal) {
            self::assertSame('This ticket already waits for an answer.', $refusal->getMessage());
        }

        self::assertSame([self::PAUSED_TICKET], $this->ticketPauses->pausedTicketIds());
    }

    #[Test]
    public function nobodyPausesATicketToAskThemselves(): void
    {
        try {
            $this->ticketPauses->pauseForQuestion(new TicketId(self::TICKET), new UserId(self::LENA), new UserId(self::LENA));
            self::fail('Lena paused the ticket to ask herself.');
        } catch (\InvalidArgumentException $refusal) {
            self::assertSame(\InvalidArgumentException::class, $refusal::class);
            self::assertSame('A person cannot ask themselves a question.', $refusal->getMessage());
        }

        self::assertSame([self::PAUSED_TICKET], $this->ticketPauses->pausedTicketIds());
    }

    #[Test]
    public function endingAPauseTheTicketDoesNotHaveIsRefused(): void
    {
        try {
            $this->ticketPauses->endQuestionPause(new TicketId(self::TICKET));
            self::fail('A pause the ticket does not have was ended.');
        } catch (\LogicException $refusal) {
            self::assertSame(\LogicException::class, $refusal::class, 'TicketNotFound is a LogicException too');
            self::assertSame('This ticket waits for no answer.', $refusal->getMessage());
        }

        self::assertSame([self::PAUSED_TICKET], $this->ticketPauses->pausedTicketIds());
    }

    #[Test]
    public function aPausedTicketMustBeOneOfTheTickets(): void
    {
        $this->expectExceptionObject(new \InvalidArgumentException('A paused ticket must be one of the tickets.'));

        new InMemoryTicketPauses([self::TICKET], [self::UNKNOWN_TICKET]);
    }
}
