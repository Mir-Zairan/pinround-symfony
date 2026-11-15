<?php

declare(strict_types=1);

namespace App\Media\Infrastructure\Messenger;

use App\Media\Application\Processing\CleanUpload;
use App\Media\Application\ProcessUpload;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Runs in worker-media once the relay has moved the message to async_media, inside the bus's transaction
 * (doctrine_transaction), which no other handler shares, under the media object's organization from the message's
 * TenantStamp.
 */
#[AsMessageHandler(bus: 'command.bus')]
final readonly class ProcessUploadHandler
{
    public function __construct(private CleanUpload $cleanUpload, private ClockInterface $clock)
    {
    }

    public function __invoke(ProcessUpload $request): void
    {
        ($this->cleanUpload)($request, $this->clock->now());
    }
}
