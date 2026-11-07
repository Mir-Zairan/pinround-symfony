<?php

declare(strict_types=1);

namespace App\SharedKernel\Infrastructure\Tracing;

use Symfony\Component\Messenger\Stamp\NonSendableStampInterface;

/**
 * Put on a received message by WorkerMessageSpanSubscriber: the worker, not the bus, ends and exports its process
 * span, so the worker's own log lines about the message still carry the trace and span id. Never sent to a transport.
 */
final readonly class SpanEndedByWorkerStamp implements NonSendableStampInterface
{
}
