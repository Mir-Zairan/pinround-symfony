<?php

declare(strict_types=1);

namespace App\Tests\Unit\Assist\Application;

use App\Assist\Application\AiDraft;
use App\Assist\Application\AiTask;
use App\Assist\Domain\AiFeature;
use App\SharedKernel\Domain\TicketId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AiTaskTest extends TestCase
{
    private const string CHECKOUT_TICKET = '0197a3c0-0000-7000-8000-0000000c7f31';
    private const string SHOP_PROJECT = '0197a3c0-0000-7000-8000-0000000c7f41';

    #[Test]
    public function anIntakeTaskIsAboutADraftAndOtherTasksAreAboutATicket(): void
    {
        $intake = new AiTask(AiFeature::Intake, draft: self::draft());
        $summary = new AiTask(AiFeature::Summary, new TicketId(self::CHECKOUT_TICKET));
        $explain = new AiTask(AiFeature::Explain, new TicketId(self::CHECKOUT_TICKET), 'Why is it blocked?');

        self::assertSame([null, 'Pay does nothing'], [$intake->ticketId, $intake->draft?->title]);
        self::assertSame([self::CHECKOUT_TICKET, null], [$summary->ticketId?->toString(), $summary->draft]);
        self::assertSame([self::CHECKOUT_TICKET, 'Why is it blocked?', null], [$explain->ticketId?->toString(), $explain->userInput, $explain->draft]);
    }

    /**
     * @return iterable<string, array{\Closure(): AiTask, string}>
     */
    public static function tasksWithTheWrongSubject(): iterable
    {
        yield 'intake without a draft' => [static fn (): AiTask => new AiTask(AiFeature::Intake, null, 'Pay does nothing.'), 'An intake task needs a draft report and no ticket.'];
        yield 'intake with a ticket as well' => [static fn (): AiTask => new AiTask(AiFeature::Intake, new TicketId(self::CHECKOUT_TICKET), null, self::draft()), 'An intake task needs a draft report and no ticket.'];
        yield 'summary without a ticket' => [static fn (): AiTask => new AiTask(AiFeature::Summary), 'A summary task needs a ticket and no draft report.'];
        yield 'summary of a draft' => [static fn (): AiTask => new AiTask(AiFeature::Summary, null, null, self::draft()), 'A summary task needs a ticket and no draft report.'];
        yield 'summary of a ticket and a draft' => [static fn (): AiTask => new AiTask(AiFeature::Summary, new TicketId(self::CHECKOUT_TICKET), null, self::draft()), 'A summary task needs a ticket and no draft report.'];
        yield 'explain of a draft' => [static fn (): AiTask => new AiTask(AiFeature::Explain, null, 'Why?', self::draft()), 'A explain task needs a ticket and no draft report.'];
    }

    /**
     * @param \Closure(): AiTask $task
     */
    #[Test]
    #[DataProvider('tasksWithTheWrongSubject')]
    public function aTaskWithTheWrongSubjectIsRefused(\Closure $task, string $expectedMessage): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($expectedMessage);

        $task();
    }

    private static function draft(): AiDraft
    {
        return new AiDraft(self::SHOP_PROJECT, 'Pay does nothing', 'Pressing Pay on the last step does nothing.');
    }
}
