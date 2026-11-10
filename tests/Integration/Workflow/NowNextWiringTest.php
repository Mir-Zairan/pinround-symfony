<?php

declare(strict_types=1);

namespace App\Tests\Integration\Workflow;

use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\UserId;
use App\Workflow\Api\NeedsYouKind;
use App\Workflow\Api\NowNextContext;
use App\Workflow\Api\TeamFacts;
use App\Workflow\Api\TicketState;
use App\Workflow\Api\Turn;
use App\Workflow\Application\Turn\PlainNowNext;
use App\Workflow\Application\Turn\PlainVerbFor;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The Api's NowNext and VerbFor as the container gives them to another module.
 */
final class NowNextWiringTest extends KernelTestCase
{
    private const string LENA = '0190a000-0000-7000-8000-00000000000a';
    private const string OMAR = '0190a000-0000-7000-8000-00000000000b';

    #[Test]
    public function theApiResolvesToTheCatalogueSentencesAndNamesTheTeamsReviewer(): void
    {
        $nowNext = self::getContainer()->get('test.workflow_now_next');
        self::assertInstanceOf(PlainNowNext::class, $nowNext);
        $lena = new UserId(self::LENA);
        $omar = new UserId(self::OMAR);
        $ticket = new TicketState('0190a000-0000-7000-8000-0000000000f3', 'progress', 'doing', new TeamId('0190a000-0000-7000-8000-0000000000f2'), assigneeId: $omar);
        $context = new NowNextContext('In progress', [self::LENA => 'Lena', self::OMAR => 'Omar'], new TeamFacts($lena, 'Lena'), true);

        self::assertSame('Omar builds it', $nowNext->now($ticket, new Turn($omar, null, NeedsYouKind::Build), $context));
        self::assertSame('Lena reviews the code, then Lena tests it', $nowNext->next($ticket, new Turn($omar, null, NeedsYouKind::Build), $context));
    }

    #[Test]
    public function theApiResolvesVerbForToThePlainOne(): void
    {
        $verbFor = self::getContainer()->get('test.workflow_verb_for');
        self::assertInstanceOf(PlainVerbFor::class, $verbFor);
        $lena = new UserId(self::LENA);
        $ticket = new TicketState('0190a000-0000-7000-8000-0000000000f3', 'test', 'check', new TeamId('0190a000-0000-7000-8000-0000000000f2'));
        $context = new NowNextContext('Testing', [], new TeamFacts($lena, 'Lena'), true);

        self::assertSame('Test it', $verbFor->person($ticket, new Turn($lena, null, NeedsYouKind::Test), $context));
    }
}
