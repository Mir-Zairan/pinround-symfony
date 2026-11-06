<?php

declare(strict_types=1);

namespace App\Tests\Unit\SharedKernel\Infrastructure\Messenger;

use App\SharedKernel\Application\MessageForAnotherTenantRefused;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Application\TenantSwitchRefused;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Infrastructure\Messenger\TenantStamp;
use App\SharedKernel\Infrastructure\Messenger\TenantStampMiddleware;
use App\Tests\Unit\SharedKernel\Application\RecordingTenantSwitchGuard;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Middleware\StackMiddleware;

final class TenantStampMiddlewareTest extends TestCase
{
    private const string ORG_A = '0197a3c0-0000-7000-8000-00000000000a';
    private const string ORG_B = '0197a3c0-0000-7000-8000-00000000000b';

    private RecordingTenantSwitchGuard $switchGuard;
    private TenantContext $tenantContext;
    private TenantStampMiddleware $middleware;

    private bool $isTransactionOpen = false;
    private ?\Throwable $rollBackFailure = null;
    private int $rollBackCount = 0;
    private int $connectionCloseCount = 0;
    private int $entityManagerClearCount = 0;

    private int $handlerCallCount = 0;
    private ?string $organizationSeenByHandler = null;

    protected function setUp(): void
    {
        $this->switchGuard = new RecordingTenantSwitchGuard();
        $this->tenantContext = new TenantContext($this->switchGuard);

        $connection = self::createStub(Connection::class);
        $connection->method('isTransactionActive')->willReturnCallback(fn (): bool => $this->isTransactionOpen);
        $connection->method('rollBack')->willReturnCallback(function (): void {
            ++$this->rollBackCount;
            if (null !== $this->rollBackFailure) {
                throw $this->rollBackFailure;
            }
            $this->isTransactionOpen = false;
        });
        $connection->method('close')->willReturnCallback(function (): void {
            ++$this->connectionCloseCount;
            $this->isTransactionOpen = false;
        });

        $entityManager = self::createStub(EntityManagerInterface::class);
        $entityManager->method('clear')->willReturnCallback(function (): void {
            ++$this->entityManagerClearCount;
        });

        $this->middleware = new TenantStampMiddleware(
            $this->tenantContext,
            $this->switchGuard,
            static fn (): Connection => $connection,
            static fn (): EntityManagerInterface => $entityManager,
        );
    }

    /**
     * @return iterable<string, array{?string}>
     */
    public static function currentOrganizations(): iterable
    {
        yield 'none set' => [null];
        yield 'one set' => [self::ORG_A];
    }

    #[Test]
    #[DataProvider('currentOrganizations')]
    public function aMessageWithoutAStampRunsUnderWhateverOrganizationIsSet(?string $currentOrganization): void
    {
        if (null !== $currentOrganization) {
            $this->tenantContext->set(new OrganizationId($currentOrganization));
        }
        $envelope = new Envelope(new \stdClass());

        $handledEnvelope = $this->middleware->handle($envelope, $this->stackWithHandler());

        self::assertSame($envelope, $handledEnvelope);
        self::assertSame(1, $this->handlerCallCount);
        self::assertSame($currentOrganization, $this->organizationSeenByHandler);
        self::assertSame($currentOrganization, $this->tenantContext->get()?->toString());
        self::assertSame(0, $this->switchGuard->switchCount);
        self::assertSame(0, $this->entityManagerClearCount);
    }

    #[Test]
    public function withNoOrganizationSetAStampedMessageRunsUnderItsOrganizationAndLeavesNoneSet(): void
    {
        $envelope = $this->envelopeFor(self::ORG_A);

        $handledEnvelope = $this->middleware->handle($envelope, $this->stackWithHandler());

        self::assertSame($envelope, $handledEnvelope);
        self::assertSame(self::ORG_A, $this->organizationSeenByHandler);
        self::assertNull($this->tenantContext->get());
        self::assertSame(1, $this->entityManagerClearCount);
        self::assertSame(0, $this->rollBackCount);
        // Once before setting the organization, once when TenantContext clears it.
        self::assertSame(2, $this->switchGuard->switchCount);
    }

    #[Test]
    public function anEntityManagerThatWasNeverBuiltIsNotBuiltToBeCleared(): void
    {
        $entityManagerStub = self::createStub(EntityManagerInterface::class);
        $unbuiltEntityManager = new \ReflectionClass($entityManagerStub::class)->newLazyProxy(
            static fn (): never => throw new \LogicException('The entity manager was built.'),
        );
        $middleware = new TenantStampMiddleware(
            $this->tenantContext,
            $this->switchGuard,
            static fn (): Connection => self::createStub(Connection::class),
            static fn (): EntityManagerInterface => $unbuiltEntityManager,
        );

        $middleware->handle($this->envelopeFor(self::ORG_A), $this->stackWithHandler());

        self::assertSame(self::ORG_A, $this->organizationSeenByHandler);
        self::assertNull($this->tenantContext->get());
    }

    #[Test]
    public function withNoOrganizationSetAFailingStampedMessageAlsoLeavesNoneSet(): void
    {
        $handlerFailure = new \RuntimeException('The handler failed.');

        $thrown = $this->handleCatching($this->envelopeFor(self::ORG_A), static fn (): never => throw $handlerFailure);

        self::assertSame($handlerFailure, $thrown);
        self::assertSame(self::ORG_A, $this->organizationSeenByHandler);
        self::assertNull($this->tenantContext->get());
        self::assertSame(1, $this->entityManagerClearCount);
    }

    #[Test]
    public function withNoOrganizationSetAStampedMessageIsRefusedWhileTheGuardRefuses(): void
    {
        $this->switchGuard->isRefusing = true;

        $thrown = $this->handleCatching($this->envelopeFor(self::ORG_A));

        self::assertInstanceOf(TenantSwitchRefused::class, $thrown);
        self::assertSame(0, $this->handlerCallCount);
        self::assertNull($this->tenantContext->get());
        self::assertSame(0, $this->entityManagerClearCount);
    }

    #[Test]
    public function aTransactionTheMessageLeftOpenIsRolledBackAndTheSuccessBecomesAFailure(): void
    {
        $thrown = $this->handleCatching($this->envelopeFor(self::ORG_A), function (): void {
            $this->isTransactionOpen = true;
        });

        self::assertInstanceOf(\LogicException::class, $thrown);
        self::assertStringContainsString('left a database transaction open', $thrown->getMessage());
        self::assertSame(1, $this->rollBackCount);
        self::assertFalse($this->isTransactionOpen);
        self::assertNull($this->tenantContext->get());
    }

    #[Test]
    public function aFailingMessageThatLeftATransactionOpenKeepsItsOwnFailure(): void
    {
        $handlerFailure = new \RuntimeException('The handler failed.');

        $thrown = $this->handleCatching($this->envelopeFor(self::ORG_A), function () use ($handlerFailure): never {
            $this->isTransactionOpen = true;

            throw $handlerFailure;
        });

        self::assertSame($handlerFailure, $thrown);
        self::assertSame(1, $this->rollBackCount);
        self::assertNull($this->tenantContext->get());
    }

    #[Test]
    public function whenRollingBackTheLeftOpenTransactionFailsTheConnectionIsClosed(): void
    {
        $this->rollBackFailure = new \RuntimeException('The rollback failed.');

        $thrown = $this->handleCatching($this->envelopeFor(self::ORG_A), function (): void {
            $this->isTransactionOpen = true;
        });

        self::assertInstanceOf(\LogicException::class, $thrown);
        self::assertSame(1, $this->connectionCloseCount);
        self::assertFalse($this->isTransactionOpen);
        self::assertNull($this->tenantContext->get());
    }

    #[Test]
    public function withTheSameOrganizationSetAStampedMessageRunsAndTheOrganizationIsKept(): void
    {
        $this->tenantContext->set(new OrganizationId(self::ORG_A));

        $this->middleware->handle($this->envelopeFor(self::ORG_A), $this->stackWithHandler());

        self::assertSame(self::ORG_A, $this->organizationSeenByHandler);
        self::assertSame(self::ORG_A, $this->tenantContext->get()?->toString());
        self::assertSame(0, $this->switchGuard->switchCount);
        self::assertSame(0, $this->entityManagerClearCount);
    }

    #[Test]
    public function withAnotherOrganizationSetAStampedMessageIsRefusedBeforeItsHandlerRuns(): void
    {
        $this->tenantContext->set(new OrganizationId(self::ORG_A));

        $thrown = $this->handleCatching($this->envelopeFor(self::ORG_B));

        self::assertInstanceOf(MessageForAnotherTenantRefused::class, $thrown);
        self::assertSame(0, $this->handlerCallCount);
        self::assertSame(self::ORG_A, $this->tenantContext->get()?->toString());
        self::assertSame(0, $this->switchGuard->switchCount);
    }

    private function envelopeFor(string $organizationId): Envelope
    {
        return new Envelope(new \stdClass(), [new TenantStamp(new OrganizationId($organizationId))]);
    }

    /**
     * @param (\Closure(): void)|null $handlerBody
     */
    private function handleCatching(Envelope $envelope, ?\Closure $handlerBody = null): ?\Throwable
    {
        try {
            $this->middleware->handle($envelope, $this->stackWithHandler($handlerBody));
        } catch (\Throwable $thrown) {
            return $thrown;
        }

        return null;
    }

    /**
     * @param (\Closure(): void)|null $handlerBody
     */
    private function stackWithHandler(?\Closure $handlerBody = null): StackInterface
    {
        $recordHandling = function () use ($handlerBody): void {
            ++$this->handlerCallCount;
            $this->organizationSeenByHandler = $this->tenantContext->get()?->toString();
            if (null !== $handlerBody) {
                $handlerBody();
            }
        };

        return new StackMiddleware(new class($recordHandling) implements MiddlewareInterface {
            /**
             * @param \Closure(): void $recordHandling
             */
            public function __construct(private readonly \Closure $recordHandling)
            {
            }

            public function handle(Envelope $envelope, StackInterface $stack): Envelope
            {
                ($this->recordHandling)();

                return $envelope;
            }
        });
    }
}
