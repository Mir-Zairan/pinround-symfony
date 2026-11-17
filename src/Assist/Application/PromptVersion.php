<?php

declare(strict_types=1);

namespace App\Assist\Application;

use App\Assist\Domain\AiFeature;

/**
 * One version of one feature's prompt template, named like "summary.v1". The name is stored with every result and
 * picks the template file and its output schema.
 */
final readonly class PromptVersion
{
    private const string NAME_PATTERN = '/^(?<feature>[a-z]+)\.v(?<number>[1-9][0-9]{0,2})\z/';

    /**
     * @throws UnknownPromptTemplate when the number is below 1
     */
    public function __construct(
        public AiFeature $feature,
        public int $number,
    ) {
        if ($number < 1) {
            throw UnknownPromptTemplate::malformedName();
        }
    }

    /**
     * Reads a name a client sent, for example with a laptop result.
     *
     * @throws UnknownPromptTemplate when the name is not a known feature followed by ".v" and a number from 1 to 999
     */
    public static function fromName(string $name): self
    {
        if (1 !== preg_match(self::NAME_PATTERN, $name, $parts)) {
            throw UnknownPromptTemplate::malformedName();
        }

        $feature = AiFeature::tryFrom($parts['feature']) ?? throw UnknownPromptTemplate::malformedName();

        return new self($feature, (int) $parts['number']);
    }

    public function name(): string
    {
        return \sprintf('%s.v%d', $this->feature->value, $this->number);
    }
}
