<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\UI\Controller;

use App\Identity\Domain\ApiToken;
use App\Identity\Domain\ApiTokenScope;
use App\Identity\UI\Controller\TokenController;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The words and choices the tokens page offers. The page throws when a scope has no explanation; this test fails first,
 * when a scope is added to ApiTokenScope without one.
 */
final class TokenControllerTest extends TestCase
{
    #[Test]
    public function everyScopeHasAnExplanationAndNoExplanationNamesAScopeThatDoesNotExist(): void
    {
        self::assertSame(
            self::valuesOf(ApiTokenScope::cases()),
            array_keys(self::explanations()),
        );
    }

    /**
     * Plain English for the person who ticks the box (ux-principles.md): a short sentence, never a code name such as
     * "rounds:write".
     */
    #[Test]
    public function eachExplanationIsAShortPlainSentence(): void
    {
        $explanations = self::explanations();
        self::assertNotSame([], $explanations);

        foreach ($explanations as $scopeName => $explanation) {
            self::assertMatchesRegularExpression('/\A[A-Z][^:\n]{9,99}\.\z/', $explanation, (string) $scopeName);
        }
        self::assertCount(\count($explanations), array_unique($explanations), 'two scopes share an explanation');
    }

    /**
     * The words of the phase 13 design notes, which name both clients that use the scope.
     */
    #[Test]
    public function notebooksWriteSaysThatClaudeCodeAndTheExtensionWriteToYourNotebooks(): void
    {
        self::assertSame(
            'Let Claude Code and the extension write to your notebooks.',
            self::explanations()[ApiTokenScope::NotebooksWrite->value] ?? null,
        );
    }

    /**
     * The longest one ApiToken allows is offered, and none longer; the default sits among the offers.
     */
    #[Test]
    public function theLifetimesOfferedLieWithinWhatATokenAllows(): void
    {
        $choices = self::lifetimes(TokenController::LIFETIME_DAY_CHOICES);

        self::assertNotSame([], $choices);
        self::assertSame(ApiToken::MAX_LIFETIME_DAYS, max($choices));
        self::assertContains(ApiToken::DEFAULT_LIFETIME_DAYS, $choices);
        self::assertSame([30, 90, 365], $choices);
    }

    /**
     * Widened, so PHPStan does not read the constant's keys and values as already known and call the checks true.
     *
     * @return array<int|string, string>
     */
    private static function explanations(): array
    {
        return self::texts(TokenController::SCOPE_EXPLANATIONS);
    }

    /**
     * @param array<mixed> $explanations
     *
     * @return array<int|string, string>
     */
    private static function texts(array $explanations): array
    {
        return array_filter($explanations, \is_string(...));
    }

    /**
     * @param array<mixed> $choices
     *
     * @return list<int>
     */
    private static function lifetimes(array $choices): array
    {
        return array_values(array_filter($choices, \is_int(...)));
    }

    /**
     * @param list<\BackedEnum> $cases
     *
     * @return list<int|string>
     */
    private static function valuesOf(array $cases): array
    {
        return array_map(static fn (\BackedEnum $case): int|string => $case->value, $cases);
    }
}
