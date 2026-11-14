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
        yield 'first and last name' => ['Omar Haddad', 'Omar'];
        yield 'padded with spaces' => ['  Lena Berg  ', 'Lena'];
        yield 'one word' => ['Mia', 'Mia'];
        yield 'multibyte first name' => ['Zoë Ångström', 'Zoë'];
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
        $context = F::context(names: [F::OMAR => '  Omar Haddad', F::MIA => ' ']);

        self::assertSame('Omar', $context->firstNameOf(F::user(F::OMAR)));
        self::assertNull($context->firstNameOf(F::user(F::MIA)));
        self::assertNull($context->firstNameOf(F::user(F::LENA)));
    }
}
