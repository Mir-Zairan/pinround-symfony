<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ticketing\Domain;

use App\SharedKernel\Domain\UserId;
use App\Ticketing\Domain\InvalidWaitingOn;
use App\Ticketing\Domain\WaitingOn;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WaitingOnTest extends TestCase
{
    #[Test]
    public function aPersonHasNoLabel(): void
    {
        $waitingOn = WaitingOn::ofPerson(new UserId('0197a3c0-0000-7000-8000-0000000032b1'));

        self::assertSame(['0197a3c0-0000-7000-8000-0000000032b1', null], [$waitingOn->userId()?->toString(), $waitingOn->label()]);
    }

    #[Test]
    public function aLabelOf100CharactersIsKeptTrimmedAndHasNoPerson(): void
    {
        $label = str_repeat('é', WaitingOn::LABEL_MAX_LENGTH);

        $waitingOn = WaitingOn::ofLabel(" \t".$label." \n");

        self::assertSame([null, $label], [$waitingOn->userId(), $waitingOn->label()]);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function refusedLabels(): iterable
    {
        yield 'empty' => ['', 'A ticket waits on a person or a label; the label is blank.'];
        yield 'only spaces and a CRLF' => ["  \r\n ", 'A ticket waits on a person or a label; the label is blank.'];
        yield '101 characters' => [str_repeat('é', 101), 'A waiting-on label has at most 100 characters.'];
        yield 'a CRLF inside' => ["Customer\r\nProduct owner", 'A waiting-on label is one line of text.'];
        yield 'an ESC' => ["Customer\e[31m", 'A waiting-on label is one line of text.'];
        yield 'a line separator' => ["Customer\u{2028}Product owner", 'A waiting-on label is one line of text.'];
        yield 'a byte order mark' => ["\u{FEFF}Customer", 'A waiting-on label is one line of text.'];
        yield 'not UTF-8' => ["Customer \xC3", 'A waiting-on label is one line of text.'];
    }

    #[Test]
    #[DataProvider('refusedLabels')]
    public function aLabelThatIsNoLineOfTextIsRefused(string $label, string $expectedMessage): void
    {
        $this->expectException(InvalidWaitingOn::class);
        $this->expectExceptionMessage($expectedMessage);
        WaitingOn::ofLabel($label);
    }
}
