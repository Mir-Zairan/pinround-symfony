<?php

declare(strict_types=1);

namespace App\Tests\Unit\Workflow\Api;

use App\SharedKernel\Domain\UserId;
use App\Workflow\Api\RuleNote;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RuleNoteTest extends TestCase
{
    private const string LENA = '0190a000-0000-7000-8000-00000000000a';
    private const string OMAR = '0190a000-0000-7000-8000-00000000000b';

    #[Test]
    public function renderPutsEachPersonsNameInTheirPlaceholder(): void
    {
        $note = new RuleNote('{reviewer} reviews it, then {tester} tests it.', ['reviewer' => new UserId(self::LENA), 'tester' => new UserId(self::OMAR)]);

        self::assertSame('Lena reviews it, then Omar tests it.', $note->render(self::nameOf(...)));
    }

    #[Test]
    public function aNoteWithoutPeopleRendersAsWritten(): void
    {
        $note = new RuleNote('Team rule: code review comes before testing.');

        self::assertSame('Team rule: code review comes before testing.', $note->render(self::nameOf(...)));
    }

    #[Test]
    public function aPlaceholderUsedTwiceGetsTheSameNameTwice(): void
    {
        $note = new RuleNote('{asked} asked; wait for {asked}.', ['asked' => new UserId(self::LENA)]);

        self::assertSame('Lena asked; wait for Lena.', $note->render(self::nameOf(...)));
    }

    #[Test]
    public function aNameThatLooksLikeAPlaceholderIsNotReplacedAgain(): void
    {
        $note = new RuleNote('{reviewer} and {tester}', ['reviewer' => new UserId(self::LENA), 'tester' => new UserId(self::OMAR)]);

        self::assertSame('{tester} and Omar', $note->render(static fn (UserId $userId): string => self::LENA === $userId->toString() ? '{tester}' : 'Omar'));
    }

    #[Test]
    public function aPlaceholderWithoutAPersonIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Every placeholder of a rule note needs exactly one person.');

        new RuleNote('Paused until {asked} answers the question below.');
    }

    #[Test]
    public function aPersonWithoutAPlaceholderIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Every placeholder of a rule note needs exactly one person.');

        new RuleNote('Team rule: only the tester moves a ticket to Done.', ['tester' => new UserId(self::OMAR)]);
    }

    private static function nameOf(UserId $userId): string
    {
        return [self::LENA => 'Lena', self::OMAR => 'Omar'][$userId->toString()] ?? 'someone else';
    }
}
