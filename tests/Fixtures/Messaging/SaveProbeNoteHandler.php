<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Messaging;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class SaveProbeNoteHandler
{
    private int $attemptCount = 0;

    /**
     * @throws \RuntimeException always; like domain code (ADR-0027), its message names no content
     */
    public function __invoke(SaveProbeNote $message): void
    {
        ++$this->attemptCount;

        throw new \RuntimeException('Saving the probe note failed.');
    }

    public function attemptCount(): int
    {
        return $this->attemptCount;
    }
}
