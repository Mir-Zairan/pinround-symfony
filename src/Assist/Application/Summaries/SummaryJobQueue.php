<?php

declare(strict_types=1);

namespace App\Assist\Application\Summaries;

interface SummaryJobQueue
{
    /**
     * Sends the job to worker-ai at once, not with the open transaction: the request that queues it writes no row.
     *
     * @throws \RuntimeException when the queue cannot be reached; the job is not sent then
     */
    public function queue(GenerateSummary $generateSummary): void;
}
