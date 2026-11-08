<?php

declare(strict_types=1);

namespace App\Tests\Functional\Organization;

use App\Identity\Domain\User;
use App\SharedKernel\Domain\OrganizationId;
use App\Tests\Functional\Identity\LogsInUsers;
use App\Tests\Integration\Voter\HackathonFlagEnvironment;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * ADMIN_AREA end to end, through the test-only admin page /_test/admin-area (config/routes/test_fixtures.yaml), the
 * real login form and the real firewall. The hackathon flag is read when the kernel boots, so each test sets it before
 * it creates the client and puts it back after. Each login comes from a random IP, so the login counters never refuse.
 */
final class AdminAreaTest extends WebTestCase
{
    use Factories;
    use LogsInUsers;

    private ?HackathonFlagEnvironment $flagBefore = null;

    #[After]
    public function restoreTheHackathonFlag(): void
    {
        $this->flagBefore?->restore();
        $this->flagBefore = null;
    }

    #[Test]
    public function anAdminLoggedInWithTheirPasswordOpensTheAdminAreaWhileTheHackathonFlagIsOn(): void
    {
        $client = $this->clientWithTheHackathonFlag('1');
        [$lena, $acmeFoods] = $this->memberWithOrgRoles('Lena Berg', '{admin}');

        $this->logInThroughTheForm($client, $lena);
        $client->request('GET', '/_test/admin-area');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['organizationId' => $acmeFoods->toString()], json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function theSameAdminIsRefusedWhileTheFlagIsOffAsNobodyHasATwoStepLoginYet(): void
    {
        $client = $this->clientWithTheHackathonFlag(null);
        [$lena] = $this->memberWithOrgRoles('Lena Berg', '{admin}');

        $this->logInThroughTheForm($client, $lena);
        $client->request('GET', '/_test/admin-area');

        self::assertResponseStatusCodeSame(403);
    }

    #[Test]
    public function aManagerIsRefusedWhileTheFlagIsOn(): void
    {
        $client = $this->clientWithTheHackathonFlag('1');
        [$raj] = $this->memberWithOrgRoles('Raj Patel', '{manager}');

        $this->logInThroughTheForm($client, $raj);
        $client->request('GET', '/_test/admin-area');

        self::assertResponseStatusCodeSame(403);
    }

    #[Test]
    public function aMemberWithoutAnOrgRoleIsRefusedWhileTheFlagIsOn(): void
    {
        $client = $this->clientWithTheHackathonFlag('1');
        [$omar] = $this->memberWithOrgRoles('Omar Haddad', '{}');

        $this->logInThroughTheForm($client, $omar);
        $client->request('GET', '/_test/admin-area');

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * The test browser's own login is a token kind that is no password session, as remember-me and the personal access
     * tokens of P09 are.
     */
    #[Test]
    public function anAdminWhoseLoginIsNotAPasswordSessionIsRefusedWhileTheFlagIsOn(): void
    {
        $client = $this->clientWithTheHackathonFlag('1');
        [$lena] = $this->memberWithOrgRoles('Lena Berg', '{admin}');

        $this->logIn($client, $lena);
        $client->request('GET', '/_test/admin-area');

        self::assertResponseStatusCodeSame(403);
    }

    #[Test]
    public function aVisitorWhoIsNotLoggedInIsSentToTheLoginPage(): void
    {
        $client = $this->clientWithTheHackathonFlag('1');

        $client->request('GET', '/_test/admin-area');

        self::assertResponseRedirects('/login', 302);
    }

    private function clientWithTheHackathonFlag(?string $flagValue): KernelBrowser
    {
        self::ensureKernelShutdown();
        $this->flagBefore = HackathonFlagEnvironment::set($flagValue);

        return self::createClient([], ['HTTPS' => 'on', 'REMOTE_ADDR' => \sprintf('10.%d.%d.%d', random_int(0, 255), random_int(0, 255), random_int(1, 254))]);
    }

    /**
     * @param string $orgRoles a PostgreSQL text[] literal, such as '{admin}'
     *
     * @return array{User, OrganizationId} a user who is a member of one new organization only, so it is chosen at once
     */
    private function memberWithOrgRoles(string $name, string $orgRoles): array
    {
        $user = $this->createUserWithoutOrganization($name);
        $acmeFoods = $this->createOrganization('Acme Foods');
        $this->addMembership($user, $acmeFoods);
        $this->setOrgRoles($user, $acmeFoods, $orgRoles);

        return [$user, $acmeFoods];
    }

    private function logInThroughTheForm(KernelBrowser $client, User $user): void
    {
        $client->request('GET', '/login');
        self::assertResponseStatusCodeSame(200);
        $client->submitForm('Log in', ['email' => $user->email()->toString(), 'password' => self::PASSWORD]);
        self::assertResponseRedirects('/', 302);
    }
}
