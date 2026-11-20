<?php

declare(strict_types=1);

namespace App\Tests\Unit\AgentApi\Infrastructure\Mcp;

use App\AgentApi\Infrastructure\Mcp\ScreenshotContextArguments;
use App\AgentApi\Infrastructure\Mcp\ToolCallOutcome;
use App\AgentApi\Infrastructure\Mcp\ToolRefusal;
use Mcp\Capability\Discovery\SchemaValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * ScreenshotContextArguments: the picture schema it makes stricter, and which titles and summaries it refuses as
 * missing or blank, read as the client sent them.
 */
final class ScreenshotContextArgumentsTest extends TestCase
{
    private const array PICTURE_SCHEMA = [
        'type' => 'object',
        'properties' => [
            'upload_id' => ['type' => 'string', 'minLength' => 1],
            'title' => ['type' => 'string', 'maxLength' => 120],
            'summary' => ['type' => 'string', 'maxLength' => 600],
        ],
        'required' => ['upload_id'],
        'additionalProperties' => false,
    ];

    #[Test]
    public function theStricterSchemaRequiresBothKeepsWhatItRequiredAndTheCapsAndTakesAtLeastOneCharacter(): void
    {
        $schema = ScreenshotContextArguments::requiredIn(self::PICTURE_SCHEMA);

        self::assertSame(['upload_id', 'title', 'summary'], $schema['required'] ?? null);
        self::assertSame([
            'upload_id' => ['type' => 'string', 'minLength' => 1],
            'title' => ['type' => 'string', 'maxLength' => 120, 'minLength' => 1],
            'summary' => ['type' => 'string', 'maxLength' => 600, 'minLength' => 1],
        ], $schema['properties'] ?? null);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, bool}>
     */
    public static function picturesAgainstTheStricterSchema(): iterable
    {
        yield 'both given' => [['upload_id' => 'u1', 'title' => 'Cart', 'summary' => 'The total.'], true];
        yield 'no title' => [['upload_id' => 'u1', 'summary' => 'The total.'], false];
        yield 'no summary' => [['upload_id' => 'u1', 'title' => 'Cart'], false];
        yield 'an empty summary' => [['upload_id' => 'u1', 'title' => 'Cart', 'summary' => ''], false];
    }

    /**
     * @param array<string, mixed> $picture
     */
    #[Test]
    #[DataProvider('picturesAgainstTheStricterSchema')]
    public function theGatesValidatorReadsTheStricterSchemaAsRequired(array $picture, bool $passes): void
    {
        $problems = new SchemaValidator()->validateAgainstJsonSchema(
            json_decode(json_encode($picture, \JSON_THROW_ON_ERROR), false, flags: \JSON_THROW_ON_ERROR),
            ScreenshotContextArguments::requiredIn(self::PICTURE_SCHEMA),
        );

        self::assertSame($passes, [] === $problems);
    }

    #[Test]
    public function aSchemaWithoutASummaryCannotBeMadeToRequireIt(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('A picture schema without "summary" cannot require it.');

        ScreenshotContextArguments::requiredIn(['type' => 'object', 'properties' => ['title' => ['type' => 'string']]]);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function picturesWithoutContext(): iterable
    {
        yield 'no title' => [['summary' => 'The total.'], '/title'];
        yield 'no summary' => [['title' => 'Cart'], '/summary'];
        yield 'neither, the title named first' => [[], '/title'];
        yield 'a null summary' => [['title' => 'Cart', 'summary' => null], '/summary'];
        yield 'an empty title' => [['title' => '', 'summary' => 'The total.'], '/title'];
        yield 'a title of spaces' => [['title' => '   ', 'summary' => 'The total.'], '/title'];
        yield 'a summary of CR LF and a tab' => [['title' => 'Cart', 'summary' => "\r\n\t\r\n"], '/summary'];
        yield 'a summary of a no-break and an ideographic space' => [['title' => 'Cart', 'summary' => "\u{00A0}\u{3000}"], '/summary'];
        yield 'a title of a BOM and a zero-width space' => [['title' => "\u{FEFF}\u{200B}", 'summary' => 'The total.'], '/title'];
        yield 'a summary of a line separator' => [['title' => 'Cart', 'summary' => "\u{2028}"], '/summary'];
    }

    /**
     * @param array<string, mixed> $picture
     */
    #[Test]
    #[DataProvider('picturesWithoutContext')]
    public function aMissingOrBlankTitleOrSummaryIsRefusedAsInvalidInputNamingIt(array $picture, string $name): void
    {
        try {
            ScreenshotContextArguments::refuseMissingIn($picture, '/screenshots/1');
            self::fail('The picture was not refused.');
        } catch (ToolRefusal $refusal) {
            self::assertSame(ToolCallOutcome::InvalidInput, $refusal->outcome);
            self::assertSame(\sprintf(ToolRefusal::INVALID_INPUT, \sprintf(ScreenshotContextArguments::MISSING_OR_BLANK, '/screenshots/1'.$name)), $refusal->getMessage());
        }
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function picturesLeftToTheSchemaOrGiven(): iterable
    {
        yield 'CR LF and multibyte text' => [['title' => "Cart\r\nPanier · 価格", 'summary' => "\u{FEFF}Der Gesamtbetrag ist doppelt.\r\n合計が二重です。"]];
        yield 'one letter each between spaces' => [['title' => ' a ', 'summary' => "\tb\n"]];
        yield 'a title that is no string' => [['title' => 42, 'summary' => 'The total.']];
        yield 'a picture that is no object' => ['here'];
    }

    #[Test]
    #[DataProvider('picturesLeftToTheSchemaOrGiven')]
    public function aGivenTitleAndSummaryOrAPictureOfAnotherShapeIsNotRefusedHere(mixed $picture): void
    {
        ScreenshotContextArguments::refuseMissingIn($picture, '');

        $this->addToAssertionCount(1);
    }
}
