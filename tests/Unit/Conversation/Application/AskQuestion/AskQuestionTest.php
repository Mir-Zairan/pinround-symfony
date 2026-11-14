<?php

declare(strict_types=1);

namespace App\Tests\Unit\Conversation\Application\AskQuestion;

use App\Conversation\Api\QuestionRefused;
use App\Conversation\Application\AskQuestion\AskQuestion;
use App\Conversation\Application\VisibleTickets;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Unit\Conversation\Application\InMemoryMessages;
use App\Tests\Unit\Conversation\Application\InMemoryPingClearing;
use App\Tests\Unit\Conversation\Application\InMemoryTicketPauses;
use App\Tests\Unit\Conversation\Application\RecordingConversationEvents;
use App\Tests\Unit\Conversation\Application\TurnProjectorWithoutNotes;
use App\Tests\Unit\SharedKernel\Application\RecordingTenantSwitchGuard;
use App\Ticketing\Api\TicketNotFound;
use App\Ticketing\Api\TicketVisibilities;
use App\Ticketing\Api\TicketVisibility;
use App\Ticketing\Api\TicketVisibilityFacts;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What AskQuestion hands to PingClearing (FR-PNG-05); the pause and the turn are covered by QuestionUseCasesTest.
 */
final class AskQuestionTest extends TestCase
{
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-00000000e701';
    private const string TICKET = '0197a3c0-0000-7000-8000-00000000e704';
    private const string OTHER_TICKET = '0197a3c0-0000-7000-8000-00000000e705';
    private const string UNKNOWN_TICKET = '0197a3c0-0000-7000-8000-00000000e706';
    private const string LENA = '0197a3c0-0000-7000-8000-00000000e711';
    private const string OMAR = '0197a3c0-0000-7000-8000-00000000e712';
    private const string ASKED_AT = '2027-03-15 10:20:00+00:00';

    private InMemoryPingClearing $pingClearing;
    private AskQuestion $askQuestion;

    protected function setUp(): void
    {
        $acmeFoods = new OrganizationId(self::ACME_FOODS);
        $tenantContext = new TenantContext(new RecordingTenantSwitchGuard());
        $tenantContext->set($acmeFoods);
        $this->pingClearing = new InMemoryPingClearing();
        $this->pingClearing->addOpenPings($acmeFoods, new TicketId(self::TICKET), new UserId(self::OMAR), 2);
        $this->pingClearing->addOpenPings($acmeFoods, new TicketId(self::OTHER_TICKET), new UserId(self::OMAR), 4);
        $this->pingClearing->addOpenPings($acmeFoods, new TicketId(self::TICKET), new UserId(self::LENA), 1);
        $this->askQuestion = new AskQuestion(
            new VisibleTickets(self::everyoneSeesTicketsForEveryone(), $tenantContext),
            new InMemoryTicketPauses([self::TICKET, self::OTHER_TICKET]),
            new TurnProjectorWithoutNotes(),
            new InMemoryMessages(),
            new RecordingConversationEvents(),
            $this->pingClearing,
        );
    }

    #[Test]
    public function aQuestionClearsTheAskersPingsOnTheTicketAtTheAskTimeAndSaysHowMany(): void
    {
        $askedAt = new \DateTimeImmutable(self::ASKED_AT);

        $question = $this->askQuestion->ask(new TicketId(self::TICKET), self::ticketForEveryone(), new UserId(self::OMAR), new UserId(self::LENA), 'Which build shows the wrong sum?', $askedAt);

        self::assertSame(2, $question->clearedPingCount);
        self::assertEquals([[self::ACME_FOODS, self::TICKET, self::OMAR, $askedAt]], $this->pingClearing->calls());
    }

    #[Test]
    public function aQuestionRefusedBecauseTheTicketAlreadyWaitsClearsNothing(): void
    {
        $lenaAskedAt = new \DateTimeImmutable(self::ASKED_AT)->modify('-5 minutes');
        $this->askQuestion->ask(new TicketId(self::TICKET), self::ticketForEveryone(), new UserId(self::LENA), new UserId(self::OMAR), 'Which phone?', $lenaAskedAt);

        try {
            $this->askQuestion->ask(new TicketId(self::TICKET), self::ticketForEveryone(), new UserId(self::OMAR), new UserId(self::LENA), 'Which build?', new \DateTimeImmutable(self::ASKED_AT));
            self::fail('The second question was asked.');
        } catch (QuestionRefused $refusal) {
            self::assertSame(AskQuestion::ALREADY_WAITING, $refusal->getMessage());
        }

        self::assertEquals([[self::ACME_FOODS, self::TICKET, self::LENA, $lenaAskedAt]], $this->pingClearing->calls());
    }

    #[Test]
    public function aQuestionOnATicketTheOrganizationDoesNotHaveIsNotFoundAndClearsNothing(): void
    {
        try {
            $this->askQuestion->ask(new TicketId(self::UNKNOWN_TICKET), self::ticketForEveryone(), new UserId(self::OMAR), new UserId(self::LENA), 'Which build?', new \DateTimeImmutable(self::ASKED_AT));
            self::fail('The question was asked on a ticket the organization does not have.');
        } catch (TicketNotFound $notFound) {
            self::assertSame('That ticket does not exist.', $notFound->getMessage());
            self::assertInstanceOf(TicketNotFound::class, $notFound->getPrevious(), 'refused by the pause, not by visibility');
        }

        self::assertSame([], $this->pingClearing->calls());
    }

    private static function ticketForEveryone(): TicketVisibilityFacts
    {
        return new TicketVisibilityFacts(new OrganizationId(self::ACME_FOODS), TicketVisibilityFacts::FOR_EVERYONE, null, new UserId(self::LENA));
    }

    private static function everyoneSeesTicketsForEveryone(): TicketVisibilities
    {
        return new readonly class implements TicketVisibilities {
            public function of(OrganizationId $organizationId, UserId $viewerId): TicketVisibility
            {
                return TicketVisibility::for($organizationId, $viewerId, [], false, null);
            }
        };
    }
}
