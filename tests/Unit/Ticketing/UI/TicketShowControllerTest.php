<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ticketing\UI;

use App\Ticketing\UI\TicketShowController;
use App\Web\UI\Component\Group;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The ask's 700-character cut and the status pill's group, which the ticket page builds itself.
 */
final class TicketShowControllerTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string|null}>
     */
    public static function asks(): iterable
    {
        $words = str_repeat('pay now ', 87);
        yield 'empty' => ['', null];
        yield 'exactly 700 characters' => [$words.'pays', null];
        yield '700 multibyte characters are not cut by their bytes' => [str_repeat('ü', 700), null];
        yield 'the cut falls inside a word' => [$words.'checkout total', rtrim($words).' …'];
        yield 'the cut falls on a space' => [$words.'pays more', $words.'pays …'];
        yield 'one word longer than the limit' => [str_repeat('a', 701), str_repeat('a', 700).' …'];
        yield 'multibyte words' => [str_repeat('größe ', 116).'zahlung', rtrim(str_repeat('größe ', 116)).' …'];
        yield 'a CR LF before the cut' => [str_repeat('x', 690)."\r\n".str_repeat('y', 20), str_repeat('x', 690).' …'];
    }

    #[Test]
    #[DataProvider('asks')]
    public function anAskLongerThan700CharactersIsCutAtTheLastWordEnd(string $description, ?string $expected): void
    {
        self::assertSame($expected, TicketShowController::shortAskOf($description));
    }

    #[Test]
    public function aCutAskKeepsAtMost700CharactersOfTheAsk(): void
    {
        $shortAsk = TicketShowController::shortAskOf(str_repeat('word ', 300));

        self::assertNotNull($shortAsk);
        self::assertSame(699, mb_strlen($shortAsk) - mb_strlen(TicketShowController::ASK_CUT_MARK));
    }

    #[Test]
    public function everyWorkflowGroupHasAStatusPillGroup(): void
    {
        $pillGroups = array_map(TicketShowController::pillGroupOf(...), ['todo', 'doing', 'check', 'wait', 'done']);

        self::assertSame(array_map(static fn (Group $group): string => $group->value, Group::cases()), $pillGroups);
    }

    #[Test]
    public function anUnknownGroupHasNoStatusPill(): void
    {
        $this->expectExceptionObject(new \LogicException('A step group "checking" has no status pill.'));

        TicketShowController::pillGroupOf('checking');
    }
}
