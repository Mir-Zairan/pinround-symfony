<?php

declare(strict_types=1);

namespace App\Tests\Unit\Assist\Api;

use App\Assist\Api\IntakeSuggestion;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class IntakeSuggestionTest extends TestCase
{
    private const array CHECKED_DATA = [
        'summary' => 'Pay does nothing on the last step.',
        'type' => 'Bug',
        'priority' => 'Major',
        'duplicates' => ['SHOP-7', 'SHOP-12'],
        'missing' => true,
        'risky' => false,
    ];

    #[Test]
    public function itKeepsTheSixFieldsOfTheIntakeSchemaWithItsModelAndPromptVersion(): void
    {
        $suggestion = IntakeSuggestion::fromCheckedData(self::CHECKED_DATA, 'meta-llama/Llama-3.1-8B-Instruct', 'intake.v1');

        self::assertSame(
            ['Pay does nothing on the last step.', 'Bug', 'Major', ['SHOP-7', 'SHOP-12'], true, false, 'meta-llama/Llama-3.1-8B-Instruct', 'intake.v1'],
            [$suggestion->summary, $suggestion->type, $suggestion->priority, $suggestion->duplicateKeys, $suggestion->isMissingDetails, $suggestion->isRisky, $suggestion->model, $suggestion->promptVersion],
        );
        self::assertSame(self::CHECKED_DATA, $suggestion->result());
    }

    /** The AI never sets a visibility (ADR-0020), whatever else a stored result or an answer carries. */
    #[Test]
    public function itDropsAVisibilityAndAnyOtherFieldTheSchemaDoesNotName(): void
    {
        $suggestion = IntakeSuggestion::fromCheckedData([...self::CHECKED_DATA, 'visibility' => 'team', 'assignee' => 'Lena'], 'm', 'intake.v1');

        self::assertSame(self::CHECKED_DATA, $suggestion->result());
        self::assertArrayNotHasKey('visibility', $suggestion->result());
    }

    #[Test]
    public function itReadsBackWhatItsResultHolds(): void
    {
        $suggestion = IntakeSuggestion::fromCheckedData(self::CHECKED_DATA, 'm', 'intake.v1');

        self::assertEquals($suggestion, IntakeSuggestion::fromCheckedData($suggestion->result(), 'm', 'intake.v1'));
    }

    #[Test]
    public function itKeepsAnEmptyListOfDuplicates(): void
    {
        self::assertSame([], IntakeSuggestion::fromCheckedData([...self::CHECKED_DATA, 'duplicates' => []], 'm', 'intake.v1')->duplicateKeys);
    }

    /**
     * @return iterable<string, array{array<mixed>, string}>
     */
    public static function unreadableData(): iterable
    {
        $duplicatesMessage = 'An intake suggestion lists its duplicate keys as strings.';
        $fieldsMessage = 'An intake suggestion has a summary, a type, a priority and two flags.';
        $without = static function (string $field): array {
            $data = self::CHECKED_DATA;
            unset($data[$field]);

            return $data;
        };

        yield 'no duplicates' => [$without('duplicates'), $duplicatesMessage];
        yield 'duplicates as a string' => [[...self::CHECKED_DATA, 'duplicates' => 'SHOP-7'], $duplicatesMessage];
        yield 'duplicates keyed by name' => [[...self::CHECKED_DATA, 'duplicates' => ['first' => 'SHOP-7']], $duplicatesMessage];
        yield 'a duplicate that is a number' => [[...self::CHECKED_DATA, 'duplicates' => ['SHOP-7', 7]], $duplicatesMessage];
        yield 'no summary' => [$without('summary'), $fieldsMessage];
        yield 'no type' => [$without('type'), $fieldsMessage];
        yield 'no priority' => [$without('priority'), $fieldsMessage];
        yield 'no missing flag' => [$without('missing'), $fieldsMessage];
        yield 'no risky flag' => [$without('risky'), $fieldsMessage];
        yield 'a summary that is a number' => [[...self::CHECKED_DATA, 'summary' => 7], $fieldsMessage];
        yield 'a missing flag as a string' => [[...self::CHECKED_DATA, 'missing' => 'true'], $fieldsMessage];
        yield 'a risky flag as a number' => [[...self::CHECKED_DATA, 'risky' => 0], $fieldsMessage];
    }

    /**
     * @param array<mixed> $data
     */
    #[Test]
    #[DataProvider('unreadableData')]
    public function itRefusesDataWithAFieldMissingOrOfAnotherType(array $data, string $expectedMessage): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage($expectedMessage);

        IntakeSuggestion::fromCheckedData($data, 'm', 'intake.v1');
    }
}
