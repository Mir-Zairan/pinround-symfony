<?php

declare(strict_types=1);

namespace App\Organization\Api;

/**
 * An organization's AI settings as the Admin › AI tab sets them (ai-architecture.md, "Org AI policy"), read-only and
 * without the provider key, which only AiSettingsQueries::providerKeyOf reads. The provider and model are kept as
 * stored; Assist checks them when it picks the provider.
 */
final readonly class AiSettings
{
    public const string WHERE_CLOUD = 'cloud';
    public const string WHERE_LAPTOP = 'laptop';
    public const string WHERE_LAPTOP_FIRST = 'laptop_first';

    public const array WHERE_VALUES = [self::WHERE_CLOUD, self::WHERE_LAPTOP, self::WHERE_LAPTOP_FIRST];

    /**
     * @param bool        $isForLeadsOnly             "Who may use it": leads only, or everyone
     * @param bool        $isTitleOnly                "What goes to the AI": the title only, or the title and ticket text
     * @param string      $whereAiRuns                one of WHERE_VALUES
     * @param bool        $isRestrictedToCloudAllowed whether team-only tickets may go to cloud AI
     * @param string|null $provider                   null means Hugging Face
     * @param string|null $providerModel              the cloud model; null means the provider's default
     *
     * @throws \InvalidArgumentException when $whereAiRuns is not one of WHERE_VALUES
     */
    public function __construct(
        public bool $isOn,
        public bool $isForLeadsOnly,
        public bool $isTitleOnly,
        public bool $isSummaryOnSyncOn,
        public string $whereAiRuns,
        public bool $isRestrictedToCloudAllowed,
        public ?string $provider,
        public ?string $providerModel,
    ) {
        if (!\in_array($whereAiRuns, self::WHERE_VALUES, true)) {
            throw new \InvalidArgumentException('AI settings need a known place where the AI runs.');
        }
    }

    /**
     * AI help off, with every other setting at its default: what an organization without AI settings, or with one it
     * cannot read, has.
     */
    public static function off(): self
    {
        return new self(false, false, false, false, self::WHERE_CLOUD, false, null, null);
    }
}
