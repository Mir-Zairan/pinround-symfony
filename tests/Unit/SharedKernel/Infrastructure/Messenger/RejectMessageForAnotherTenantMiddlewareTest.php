<?php

declare(strict_types=1);

namespace App\Tests\Unit\SharedKernel\Infrastructure\Messenger;

use App\SharedKernel\Application\MessageForAnotherTenantRefused;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Infrastructure\Messenger\RejectMessageForAnotherTenantMiddleware;
use App\SharedKernel\Infrastructure\Messenger\TenantStamp;
use App\Tests\Unit\SharedKernel\Application\RecordingTenantSwitchGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Middleware\StackMiddleware;

final class RejectMessageForAnotherTenantMiddlewareTest extends TestCase
{
    private const string ORG_A = '0197a3c0-0000-7000-8000-00000000000a';
    private const string ORG_B = '0197a3c0-0000-7000-8000-00000000000b';

    private TenantContext $tenantContext;
    private int $nextMiddlewareCallCount = 0;

    protected function setUp(): void
    {
        $this->tenantContext = new TenantContext(new RecordingTenantSwitchGuard());
    }

    /**
     * @return iterable<string, array{?string, ?string}>
     */
    public static function passingMessages(): iterable
    {
        yield 'no stamp, no organization set' => [null, null];
        yield 'no stamp, an organization set' => [null, self::ORG_A];
        yield 'a stamp, no organization set' => [self::ORG_A, null];
        yield 'a stamp for the organization set' => [self::ORG_A, self::ORG_A];
    }

    #[Test]
    #[DataProvider('passingMessages')]
    public function itPassesOnEveryMessageThatIsNotForAnotherOrganization(?string $stampedOrganization, ?string $currentOrganization): void
    {
        if (null !== $currentOrganization) {
            $this->tenantContext->set(new OrganizationId($currentOrganization));
        }
        $envelope = $this->envelopeFor($stampedOrganization);

        $handledEnvelope = new RejectMessageForAnotherTenantMiddleware($this->tenantContext)->handle($envelope, $this->stack());

        self::assertSame($envelope, $handledEnvelope);
        self::assertSame(1, $this->nextMiddlewareCallCount);
        self::assertSame($currentOrganization, $this->tenantContext->get()?->toString());
    }

    #[Test]
    public function itRefusesAMessageStampedForAnotherOrganizationBeforeTheNextMiddleware(): void
    {
        $this->tenantContext->set(new OrganizationId(self::ORG_A));

        try {
            new RejectMessageForAnotherTenantMiddleware($this->tenantContext)->handle($this->envelopeFor(self::ORG_B), $this->stack());
            self::fail('The message for another organization was not refused.');
        } catch (MessageForAnotherTenantRefused) {
        }

        self::assertSame(0, $this->nextMiddlewareCallCount);
        self::assertSame(self::ORG_A, $this->tenantContext->get()?->toString());
    }

    private function envelopeFor(?string $organizationId): Envelope
    {
        $stamps = null === $organizationId ? [] : [new TenantStamp(new OrganizationId($organizationId))];

        return new Envelope(new \stdClass(), $stamps);
    }

    private function stack(): StackInterface
    {
        $countCall = function (): void {
            ++$this->nextMiddlewareCallCount;
        };

        return new StackMiddleware(new readonly class($countCall) implements MiddlewareInterface {
            /**
             * @param \Closure(): void $countCall
             */
            public function __construct(private \Closure $countCall)
            {
            }

            public function handle(Envelope $envelope, StackInterface $stack): Envelope
            {
                ($this->countCall)();

                return $envelope;
            }
        });
    }
}
