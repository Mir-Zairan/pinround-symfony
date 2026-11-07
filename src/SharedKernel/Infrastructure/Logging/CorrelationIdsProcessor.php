<?php

declare(strict_types=1);

namespace App\SharedKernel\Infrastructure\Logging;

use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Infrastructure\Http\RequestId;
use App\SharedKernel\Infrastructure\Tracing\CurrentSpan;
use Monolog\Attribute\AsMonologProcessor;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Adds request_id, organization_id, and trace_id and span_id (lowercase hex, only while a sampled request span is
 * active) to the extra of every record, each only when it is known. user_id comes with the users of P01;
 * RedactionProcessor already allows them all.
 */
#[AsMonologProcessor]
final readonly class CorrelationIdsProcessor implements ProcessorInterface
{
    public function __construct(
        private RequestId $requestId,
        private TenantContext $tenantContext,
        private CurrentSpan $currentSpan,
    ) {
    }

    public function __invoke(LogRecord $record): LogRecord
    {
        $extra = $record->extra;

        $requestId = $this->requestId->get();
        if (null !== $requestId) {
            $extra['request_id'] = $requestId;
        }

        $organizationId = $this->tenantContext->get();
        if (null !== $organizationId) {
            $extra['organization_id'] = $organizationId->toString();
        }

        $spanContext = $this->currentSpan->sampledSpanContext();
        if (null !== $spanContext) {
            $extra['trace_id'] = $spanContext->getTraceId();
            $extra['span_id'] = $spanContext->getSpanId();
        }

        return $record->with(extra: $extra);
    }
}
