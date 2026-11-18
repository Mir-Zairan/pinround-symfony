<?php

declare(strict_types=1);

namespace App\Tests\Unit\Review\Api;

use App\Review\Api\RoundRefused;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RoundRefusedTest extends TestCase
{
    /**
     * @return iterable<string, array{RoundRefused, array{bool, bool, bool}}>
     */
    public static function refusalsAndTheirKinds(): iterable
    {
        yield 'no such draft' => [new RoundRefused(RoundRefused::NOT_FOUND), [true, false, false]];
        yield 'no such picture' => [new RoundRefused(RoundRefused::SCREENSHOT_NOT_FOUND), [true, false, false]];
        yield 'the not-found text in another case' => [new RoundRefused(strtoupper(RoundRefused::NOT_FOUND)), [false, false, false]];
        yield 'a draft another request changed' => [new RoundRefused(RoundRefused::DRAFT_CHANGED), [false, false, false]];
        yield 'a number another round took' => [new RoundRefused(RoundRefused::NUMBER_TAKEN), [false, false, false]];
        yield 'a shared draft' => [RoundRefused::readOnly('This draft round is shared, so it no longer changes.'), [false, true, false]];
        yield 'a summary over its cap' => [RoundRefused::invalidContent('A picture summary holds at most 600 characters.'), [false, false, true]];
        yield 'an unknown verdict' => [RoundRefused::invalidContent(RoundRefused::UNKNOWN_VERDICT), [false, false, true]];
    }

    /**
     * @param array{bool, bool, bool} $kinds isNotFound, isReadOnly and isInvalidContent
     */
    #[Test]
    #[DataProvider('refusalsAndTheirKinds')]
    public function eachRefusalAnswersOnlyToItsOwnKind(RoundRefused $refused, array $kinds): void
    {
        self::assertSame($kinds, [$refused->isNotFound(), $refused->isReadOnly(), $refused->isInvalidContent()]);
    }

    #[Test]
    public function aRefusalOfAKindKeepsTheReasonToShowAndTheCause(): void
    {
        $sharedAlready = new \DomainException('This draft round is shared, so it no longer changes.');
        $tooLong = new \InvalidArgumentException('A picture summary holds at most 600 characters.');

        $readOnly = RoundRefused::readOnly($sharedAlready->getMessage(), $sharedAlready);
        $invalidContent = RoundRefused::invalidContent($tooLong->getMessage(), $tooLong);

        self::assertSame('This draft round is shared, so it no longer changes.', $readOnly->getMessage());
        self::assertSame($sharedAlready, $readOnly->getPrevious());
        self::assertSame('A picture summary holds at most 600 characters.', $invalidContent->getMessage());
        self::assertSame($tooLong, $invalidContent->getPrevious());
        self::assertNull(RoundRefused::readOnly('Closed.')->getPrevious());
    }
}
