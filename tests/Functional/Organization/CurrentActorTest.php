<?php

declare(strict_types=1);

namespace App\Tests\Functional\Organization;

use App\Tests\Functional\Identity\LogsInUsers;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * The actor a voter gets (CurrentActor), through the test-only /_test/actor in config/routes/test_fixtures.yaml. The
 * browser keeps one kernel, as a worker does (ADR-0008), so only the kernel's reset between requests can make the
 * second request read the roles again.
 */
final class CurrentActorTest extends WebTestCase
{
    use Factories;
    use LogsInUsers;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = self::createClient();
        $this->client->disableReboot();
    }

    #[Test]
    public function aRoleChangeBetweenTwoRequestsOnOneWorkerShowsInTheNextRequest(): void
    {
        $user = $this->createUserWithoutOrganization('Sara Novak');
        $acmeFoods = $this->createOrganization('Acme Foods');
        $this->addMembership($user, $acmeFoods);
        $this->setOrgRoles($user, $acmeFoods, '{admin}');
        $this->logIn($this->client, $user);

        $this->client->request('GET', '/_test/actor');
        self::assertResponseStatusCodeSame(200);
        self::assertSame(['actor' => [
            'userId' => $user->id()->toString(),
            'organizationId' => $acmeFoods->toString(),
            'isAdmin' => true,
            'isManager' => false,
            'isReusedWithinTheRequest' => true,
        ]], $this->responseJson());

        $this->setOrgRoles($user, $acmeFoods, '{manager}');
        $this->client->request('GET', '/_test/actor');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['actor' => [
            'userId' => $user->id()->toString(),
            'organizationId' => $acmeFoods->toString(),
            'isAdmin' => false,
            'isManager' => true,
            'isReusedWithinTheRequest' => true,
        ]], $this->responseJson());
    }

    #[Test]
    public function aRequestWithoutALoggedInUserHasNoActor(): void
    {
        $this->client->request('GET', '/_test/actor');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['actor' => null], $this->responseJson());
    }

    private function responseJson(): mixed
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
    }
}
