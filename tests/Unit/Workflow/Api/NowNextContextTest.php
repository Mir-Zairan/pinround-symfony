<?php

declare(strict_types=1);

namespace App\Tests\Unit\Workflow\Api;

use App\Tests\Unit\Workflow\Application\Turn\NowNextFixtures as F;
use App\Workflow\Api\NowNextContext;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The first-name rule the Now and Next sentences, the ticket page's pill and What happened share.
 */
final class NowNextContextTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string|null}>
     */
    public static function displayNames(): iterable
    {
        yield 'first and last name' => ['Omar Lee', 'Omar'];
        yield 'padded with spaces' => ['  Lena Lee  ', 'Lena'];
        yield 'one word' => ['Mia', 'Mia'];
        yield 'multibyte first name' => ['Léna Ångström', 'Léna'];
        yield 'empty' => ['', null];
        yield 'only spaces' => ['   ', null];
    }

    #[Test]
    #[DataProvider('displayNames')]
    public function firstNameFromTakesTheFirstWordOrNullForABlankName(string $displayName, ?string $expected): void
    {
        self::assertSame($expected, NowNextContext::firstNameFrom($displayName));
    }

    #[Test]
    public function firstNameOfUsesTheSameRuleAndIsNullForSomeoneWithoutAName(): void
    {
        $context = F::context(names: [F::OMAR => '  Omar Lee', F::MIA => ' ']);

        self::assertSame('Omar', $context->firstNameOf(F::user(F::OMAR)));
        self::assertNull($context->firstNameOf(F::user(F::MIA)));
        self::assertNull($context->firstNameOf(F::user(F::LENA)));
    }

    #[Test]
    public function aContextForNowOnlyHasNoTeamAndRefusesToGiveOneForNext(): void
    {
        $context = NowNextContext::forNowOnly('In progress', [F::OMAR => 'Omar'], F::user(F::OMAR), F::user(F::LENA));

        self::assertNull($context->team);
        self::assertSame('In progress', $context->stepName);
        self::assertSame(F::OMAR, $context->viewerId?->toString());
        self::assertSame(F::LENA, $context->askerId?->toString());
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('The Next sentence needs the team; this context was made for Now only.');

        $context->teamForNext();
    }

    #[Test]
    public function aContextWithATeamGivesThatTeamForNext(): void
    {
        $context = F::context();

        self::assertSame($context->team, $context->teamForNext());
    }
}
