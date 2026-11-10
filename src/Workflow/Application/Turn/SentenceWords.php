<?php

declare(strict_types=1);

namespace App\Workflow\Application\Turn;

use App\SharedKernel\Domain\UserId;
use App\Workflow\Api\NowNextContext;

/**
 * Names and verb forms for the Now, Next and verb sentences, so "you" is decided in one place.
 */
final readonly class SentenceWords
{
    public function __construct(private NowNextContext $context)
    {
    }

    public function who(?UserId $userId, ?string $label = null, string $fallback = 'someone'): string
    {
        if (null === $userId) {
            return $label ?? $fallback;
        }

        if ($this->isViewer($userId)) {
            return 'you';
        }

        return $this->context->firstNameOf($userId) ?? $label ?? $fallback;
    }

    /**
     * "your" for the viewer, "Mia's" for a named person, else $fallback (for example "the").
     */
    public function possessive(?UserId $userId, string $fallback): string
    {
        if (null === $userId) {
            return $fallback;
        }

        if ($this->isViewer($userId)) {
            return 'your';
        }

        $name = $this->context->firstNameOf($userId);

        return null === $name ? $fallback : $name."'s";
    }

    /**
     * A clause such as "Omar builds it" or "you build it"; every verb used here takes a plain "s" in the third person.
     * It starts with a lowercase "you" or "a …"; the caller capitalizes the first clause of a sentence.
     */
    public function action(?UserId $userId, string $fallback, string $verbStem, string $rest, ?string $label = null): string
    {
        $subject = $this->who($userId, $label, $fallback);

        return $subject.' '.('you' === $subject ? $verbStem : $verbStem.'s').$rest;
    }

    private function isViewer(UserId $userId): bool
    {
        return null !== $this->context->viewerId && $this->context->viewerId->equals($userId);
    }
}
