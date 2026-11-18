<?php

declare(strict_types=1);

namespace App\Assist\Api;

/**
 * The organization's AI policy as it applies to one person, for pages that show or hide AI help: whether the person
 * gets AI help at all and, when not, the code and the one line that says why. It never carries the provider key.
 */
final readonly class PolicyView
{
    public const string WHERE_CLOUD = 'cloud';
    public const string WHERE_LAPTOP = 'laptop';
    public const string WHERE_LAPTOP_FIRST = 'laptop_first';

    /**
     * @param string|null $refusalCode                 null when the person may use AI help
     * @param string|null $refusalSentence             null when the person may use AI help
     * @param string      $whereAiRuns                 WHERE_CLOUD, WHERE_LAPTOP or WHERE_LAPTOP_FIRST
     * @param bool        $isTitleOnly                 the ticket text is never sent, only the title
     * @param bool        $isSummaryOnSyncOn           AI help is on and a summary is written for each ticket when it syncs
     * @param string|null $laptopViewerRefusalSentence the line that keeps this person from laptop AI whatever the
     *                                                 laptop's state, as for an admin with no team or manager role;
     *                                                 null when none, and always null when AI help is off or runs in
     *                                                 the cloud only
     *
     * @throws \InvalidArgumentException when only one of the refusal code and sentence is given, or $whereAiRuns is
     *                                   not one of the WHERE_ values
     */
    public function __construct(
        public ?string $refusalCode,
        public ?string $refusalSentence,
        public string $whereAiRuns,
        public bool $isTitleOnly,
        public bool $isSummaryOnSyncOn,
        public bool $isRestrictedToCloudAllowed,
        public ?string $laptopViewerRefusalSentence,
    ) {
        if ((null === $refusalCode) !== (null === $refusalSentence)) {
            throw new \InvalidArgumentException('A refusal needs both its code and its sentence.');
        }
        if (!\in_array($whereAiRuns, [self::WHERE_CLOUD, self::WHERE_LAPTOP, self::WHERE_LAPTOP_FIRST], true)) {
            throw new \InvalidArgumentException('A policy view needs a known place where the AI runs.');
        }
    }

    public function isAllowed(): bool
    {
        return null === $this->refusalCode;
    }

    /**
     * Whether the browser may talk to Ollama on the laptop, for the page's connect-src.
     */
    public function isLaptopAllowed(): bool
    {
        return $this->isAllowed() && self::WHERE_CLOUD !== $this->whereAiRuns;
    }
}
