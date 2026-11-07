<?php

declare(strict_types=1);

namespace App\Tests\Unit\SharedKernel\Infrastructure\Http;

use App\SharedKernel\Infrastructure\Http\RequestId;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Uid\Uuid;

final class RequestIdTest extends TestCase
{
    private RequestId $requestId;

    protected function setUp(): void
    {
        $this->requestId = new RequestId();
    }

    #[Test]
    public function itHasNoIdOutsideARequest(): void
    {
        self::assertNull($this->requestId->get());
    }

    #[Test]
    public function aMainRequestGetsAUuid(): void
    {
        $this->requestId->start($this->requestEvent(HttpKernelInterface::MAIN_REQUEST));

        self::assertTrue(Uuid::isValid((string) $this->requestId->get()));
    }

    #[Test]
    public function itGivesANewIdAfterReset(): void
    {
        $this->requestId->start($this->requestEvent(HttpKernelInterface::MAIN_REQUEST));
        $firstId = $this->requestId->get();

        $this->requestId->reset();
        self::assertNull($this->requestId->get());

        $this->requestId->start($this->requestEvent(HttpKernelInterface::MAIN_REQUEST));
        self::assertNotNull($this->requestId->get());
        self::assertNotSame($firstId, $this->requestId->get());
    }

    #[Test]
    public function eachMainRequestGetsANewIdEvenWithoutAReset(): void
    {
        $this->requestId->start($this->requestEvent(HttpKernelInterface::MAIN_REQUEST));
        $firstId = $this->requestId->get();

        $this->requestId->start($this->requestEvent(HttpKernelInterface::MAIN_REQUEST));

        self::assertNotSame($firstId, $this->requestId->get());
    }

    #[Test]
    public function aSubRequestKeepsTheIdOfItsMainRequest(): void
    {
        $this->requestId->start($this->requestEvent(HttpKernelInterface::MAIN_REQUEST));
        $mainId = $this->requestId->get();

        $this->requestId->start($this->requestEvent(HttpKernelInterface::SUB_REQUEST));

        self::assertSame($mainId, $this->requestId->get());
    }

    private function requestEvent(int $requestType): RequestEvent
    {
        return new RequestEvent(self::createStub(HttpKernelInterface::class), new Request(), $requestType);
    }
}
