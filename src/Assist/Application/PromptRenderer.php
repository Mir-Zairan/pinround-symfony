<?php

declare(strict_types=1);

namespace App\Assist\Application;

use App\Assist\Domain\AiFeature;

/**
 * Turns a feature's versioned prompt template and one ticket into the prompt sent to a model. Ticket data goes in only
 * inside delimiters marked as data, cleaned and cut to size (ADR-0020, FR-AI-18).
 */
interface PromptRenderer
{
    /**
     * @throws UnknownPromptTemplate when the feature has no template yet
     */
    public function currentVersion(AiFeature $feature): PromptVersion;

    /**
     * @param bool                  $isTitleOnly         the policy sends the title only, so the text is dropped
     * @param array<string, string> $duplicateCandidates titles by ticket key, at most 80, for the intake template
     *
     * @throws UnknownPromptTemplate     when the template is missing, or renders blank instructions, blank content or
     *                                   content without the ticket; nothing half-built is returned
     * @throws \InvalidArgumentException when there are more than 80 candidates or a candidate key is not like SHOP-12
     */
    public function build(PromptVersion $version, PromptTicket $ticket, bool $isTitleOnly, array $duplicateCandidates = []): AiPrompt;
}
