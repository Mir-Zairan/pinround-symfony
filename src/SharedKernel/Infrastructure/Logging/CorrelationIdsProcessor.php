<?php

declare(strict_types=1);

namespace App\SharedKernel\Infrastructure\Logging;

use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Infrastructure\Http\RequestId;
use Monolog\Attribute\AsMonologProcessor;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Adds request_id and organization_id to the extra of every record, each only when it is known. user_id comes with
 * the users of P01, trace_id and span_id with OpenTelemetry; RedactionProcessor already allows them.
 */
#[AsMonologProcessor]
final readonly class CorrelationIdsProcessor implements ProcessorInterface
{
    public function __construct(private RequestId $requestId, private TenantContext $tenantContext)
    {
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

        return $record->with(extra: $extra);
    }
}
