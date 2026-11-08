<?php

declare(strict_types=1);

namespace App\Tests\Unit\Organization\UI;

use App\Identity\Api\TwoStepProofPages;

/**
 * Answers /confirm and /setup and records each call.
 */
final class RecordingTwoStepProofPages implements TwoStepProofPages
{
    /** @var list<list<string>> */
    public array $calls = [];

    public function confirmItsYouPathFor(mixed $request): string
    {
        $this->calls[] = ['confirm'];

        return '/confirm';
    }

    public function twoStepSetupPathFor(mixed $request, string $notice): string
    {
        $this->calls[] = ['setup', $notice];

        return '/setup';
    }
}
