<?php

declare(strict_types=1);

namespace App\Assist\Api;

/**
 * What "Check with AI" suggests for a draft report, as the intake schema checked it, with the model and prompt
 * version that made it (ADR-0020: every AI output is labeled). It suggests only: the person decides what to take. It
 * has no visibility: the AI never sets one.
 */
final readonly class IntakeSuggestion
{
    public const string LABEL = 'AI suggestion';

    /**
     * @param list<string> $duplicateKeys keys of open tickets the draft may repeat, each one that was sent as a
     *                                    candidate
     */
    private function __construct(
        public string $summary,
        public string $type,
        public string $priority,
        public array $duplicateKeys,
        public bool $isMissingDetails,
        public bool $isRisky,
        public string $model,
        public string $promptVersion,
    ) {
    }

    /**
     * Takes the fields of the intake schema and drops anything else.
     *
     * @param array<mixed> $checkedData an answer AiAnswerCheck::checkData accepted, or a stored result()
     *
     * @throws \UnexpectedValueException when a field is missing or of another type
     */
    public static function fromCheckedData(array $checkedData, string $model, string $promptVersion): self
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

        return new self($summary, $type, $priority, $duplicateKeys, $isMissingDetails, $isRisky, $model, $promptVersion);
    }

    /**
     * The fields of the intake schema, as fromCheckedData() reads them back; no label.
     *
     * @return array{summary: string, type: string, priority: string, duplicates: list<string>, missing: bool, risky: bool}
     */
    public function result(): array
    {
        return [
            'summary' => $this->summary,
            'type' => $this->type,
            'priority' => $this->priority,
            'duplicates' => $this->duplicateKeys,
            'missing' => $this->isMissingDetails,
            'risky' => $this->isRisky,
        ];
    }
}
