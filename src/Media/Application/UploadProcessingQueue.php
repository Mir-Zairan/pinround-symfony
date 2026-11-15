<?php

declare(strict_types=1);

namespace App\Media\Application;

interface UploadProcessingQueue
{
    /**
     * Writes the message to the outbox in the caller's open transaction, so the processing worker gets it only if the
     * media object it names commits.
     *
     * @throws \LogicException when no transaction is open
     */
    public function queue(ProcessUpload $processUpload): void;
}
