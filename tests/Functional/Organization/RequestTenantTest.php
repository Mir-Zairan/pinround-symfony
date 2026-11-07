<?php

declare(strict_types=1);

namespace App\Tests\Functional\Organization;

use App\Organization\UI\EnterChosenOrganization;
use App\SharedKernel\Infrastructure\Doctrine\TenantSetting;
use App\SharedKernel\Infrastructure\Http\RequestTransaction;
use App\Tests\Functional\Identity\LogsInUsers;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpKernel\KernelEvents;
use Zenstruck\Foundry\Test\Factories;

/**
 * The request transaction of a tenant page runs with the chosen organization as app.org_id, read inside the request
 * by a kernel.controller listener the test adds; disableReboot() keeps one kernel for many requests, as worker mode
 * does (ADR-0008).
 */
final class RequestTenantTest extends WebTestCase
{
    use Factories;
    use LogsInUsers;

    private KernelBrowser $client;

    /** @var list<string|null> app.org_id as each request's controller saw it, in request order; null when unset or empty */
    private array $tenantSettingsSeen = [];

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = self::createClient([], ['HTTPS' => 'on']);
        $this->client->disableReboot();
        $connection = self::getContainer()->get('doctrine.dbal.default_connection');
        self::getContainer()->get('event_dispatcher')->addListener(KernelEvents::CONTROLLER, function () use ($connection): void {
            $setting = $connection->fetchOne('SELECT current_setting(?, true)', [TenantSetting::NAME]);
            $this->tenantSettingsSeen[] = \is_string($setting) && '' !== $setting ? $setting : null;
        });
    }

    #[Test]
    public function theTenantIsSetAfterTheFirewallAndBeforeTheRequestTransactionBegins(): void
    {
        $dispatcher = self::getContainer()->get('event_dispatcher');
        $enterChosenOrganization = self::getContainer()->get(EnterChosenOrganization::class);
        $requestTransaction = self::getContainer()->get(RequestTransaction::class);

        $listenerPriority = $dispatcher->getListenerPriority(KernelEvents::REQUEST, [$enterChosenOrganization, '__invoke']);

        self::assertSame(7, $listenerPriority);
        self::assertGreaterThan($dispatcher->getListenerPriority(KernelEvents::REQUEST, [$requestTransaction, 'beginTransaction']), $listenerPriority);
    }

    #[Test]
    public function aTenantPageRunsItsTransactionWithTheMembersOrganizationAndTheNextRequestStartsWithout(): void
    {
        $lena = $this->createUserWithoutOrganization('Lena Berg');
        $acmeFoods = $this->createOrganization('Acme Foods');
        $this->addMembership($lena, $acmeFoods);
        $omar = $this->createUserWithoutOrganization('Omar Haddad');
        $betaBakery = $this->createOrganization('Beta Bakery');
        $this->addMembership($omar, $betaBakery);

        $this->logIn($this->client, $lena);
        $this->client->request('GET', '/');
        self::assertResponseStatusCodeSame(200);
        $this->logIn($this->client, $omar);
        $this->client->request('GET', '/');
        self::assertResponseStatusCodeSame(200);
        $this->client->getCookieJar()->clear();
        $this->client->request('GET', '/_test/worker-reset');
        self::assertResponseStatusCodeSame(200);

        self::assertSame([$acmeFoods->toString(), $betaBakery->toString(), null], $this->tenantSettingsSeen);
        self::assertSame(['organizationId' => null, 'isEntityManagerOpen' => true], json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR));
    }
}
