<?php

declare(strict_types=1);

namespace App\Tests\Integration\Identity\Application;

use App\Identity\Api\OrganizationOAuthConnections;
use App\SharedKernel\Application\TenantContext;
use App\Tests\Integration\Identity\HandWiredOAuthConnections;
use App\Tests\Integration\Organization\TenantTransactions;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The page size rule of OrganizationOAuthConnections::livePage(), wired by hand (HandWiredOAuthConnections); the lists
 * themselves are DbalOAuthConnectionsTest's, and end() is EndOAuthConnectionTest's.
 */
final class OAuthConnectionsOfOrganizationTest extends KernelTestCase
{
    /** An organization with no rows. */
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000043a1';
    private const string NOW = '2027-03-15 10:00:00 UTC';

    protected function tearDown(): void
    {
        self::getContainer()->get(TenantContext::class)->reset();
        parent::tearDown();
    }

    #[Test]
    public function aPageHoldsOneToAHundredConnectionsAndAnyOtherSizeIsRefusedBeforeAnyQuery(): void
    {
        $refusals = [];
        foreach ([0, OrganizationOAuthConnections::MAX_PAGE_SIZE + 1] as $limit) {
            try {
                // No tenant: a query would refuse with TenantNotSet instead.
                $this->connections()->livePage(new \DateTimeImmutable(self::NOW), $limit);
                self::fail(\sprintf('A page of %d was given.', $limit));
            } catch (\InvalidArgumentException $refusal) {
                $refusals[$limit] = $refusal->getMessage();
            }
        }

        self::assertSame([0 => 'A page of connections holds 1 to 100.', 101 => 'A page of connections holds 1 to 100.'], $refusals);
        self::assertSame([], $this->transactions()->rolledBackAs(self::ACME_FOODS, fn (): array => $this->connections()->livePage(new \DateTimeImmutable(self::NOW), OrganizationOAuthConnections::MAX_PAGE_SIZE)));
    }

    private function connections(): OrganizationOAuthConnections
    {
        return new HandWiredOAuthConnections(self::getContainer())->organizationOAuthConnections();
    }

    private function transactions(): TenantTransactions
    {
        return new TenantTransactions(self::getContainer()->get('doctrine.orm.default_entity_manager'), self::getContainer()->get(TenantContext::class));
    }
}
