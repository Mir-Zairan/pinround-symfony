<?php

declare(strict_types=1);

namespace App\Assist\Application\Intake;

use App\Assist\Api\IntakeSuggestion;

/**
 * Turns an intake answer the schema accepted, or a stored result, into an IntakeSuggestion, and an IntakeSuggestion
 * into the result that is stored. Only the fields of the intake schema pass either way, so a visibility or any other
 * field an answer carries is dropped.
 */
final class IntakeSuggestionMapper
{
    /**
     * @param array<mixed> $checkedData an answer AiAnswerCheck::checkData accepted, or a stored resultOf()
     *
     * @throws \UnexpectedValueException when a field is missing or of another type
     */
    public static function fromCheckedData(array $checkedData, string $model, string $promptVersion): IntakeSuggestion
    {
        $duplicateKeys = $checkedData['duplicates'] ?? null;
        if (!\is_array($duplicateKeys) || !array_is_list($duplicateKeys) || [] !== array_filter($duplicateKeys, static fn (mixed $key): bool => !\is_string($key))) {
            throw new \UnexpectedValueException('An intake suggestion lists its duplicate keys as strings.');
        }
        /** @var list<string> $duplicateKeys */
        $summary = $checkedData['summary'] ?? null;
        $type = $checkedData['type'] ?? null;
        $priority = $checkedData['priority'] ?? null;
        $isMissingDetails = $checkedData['missing'] ?? null;
        $isRisky = $checkedData['risky'] ?? null;
        if (!\is_string($summary) || !\is_string($type) || !\is_string($priority) || !\is_bool($isMissingDetails) || !\is_bool($isRisky)) {
            throw new \UnexpectedValueException('An intake suggestion has a summary, a type, a priority and two flags.');
        }

        return new IntakeSuggestion($summary, $type, $priority, $duplicateKeys, $isMissingDetails, $isRisky, $model, $promptVersion);
    }

    /**
     * The fields of the intake schema, as fromCheckedData() reads them back; no label.
     *
     * @return array{summary: string, type: string, priority: string, duplicates: list<string>, missing: bool, risky: bool}
     */
    public static function resultOf(IntakeSuggestion $suggestion): array
    {
        return [
            'summary' => $suggestion->summary,
            'type' => $suggestion->type,
            'priority' => $suggestion->priority,
            'duplicates' => $suggestion->duplicateKeys,
            'missing' => $suggestion->isMissingDetails,
            'risky' => $suggestion->isRisky,
        ];
    }
}
