<?php

declare(strict_types=1);

namespace App\Tests\Unit\SharedKernel\Infrastructure\Logging;

use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Infrastructure\Http\RequestId;
use App\SharedKernel\Infrastructure\Logging\CorrelationIdsProcessor;
use App\Tests\Unit\SharedKernel\Application\RecordingTenantSwitchGuard;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class CorrelationIdsProcessorTest extends TestCase
{
    private const string ORG_A = '0197a3c0-0000-7000-8000-00000000000a';

    private RequestId $requestId;
    private TenantContext $tenantContext;
    private CorrelationIdsProcessor $processor;

    protected function setUp(): void
    {
        $this->requestId = new RequestId();
        $this->tenantContext = new TenantContext(new RecordingTenantSwitchGuard());
        $this->processor = new CorrelationIdsProcessor($this->requestId, $this->tenantContext);
    }

    #[Test]
    public function itAddsTheRequestIdAndTheOrganizationIdAndKeepsTheOtherExtra(): void
    {
        $this->requestId->start(new RequestEvent(self::createStub(HttpKernelInterface::class), new Request(), HttpKernelInterface::MAIN_REQUEST));
        $this->tenantContext->set(new OrganizationId(self::ORG_A));

        $record = ($this->processor)(self::record(['memory' => 1]));

        self::assertSame(['memory' => 1, 'request_id' => $this->requestId->get(), 'organization_id' => self::ORG_A], $record->extra);
    }

    #[Test]
    public function itAddsNothingOutsideARequestAndWithoutATenant(): void
    {
        $record = ($this->processor)(self::record(['memory' => 1]));

        self::assertSame(['memory' => 1], $record->extra);
    }

    /**
     * @param array<string, mixed> $extra
     */
    private static function record(array $extra): LogRecord
    {
        return new LogRecord(new \DateTimeImmutable(), 'app', Level::Info, 'Probe.', [], $extra);
    }
}
