<?php

declare(strict_types=1);

namespace App\Tests\Functional\Organization;

use App\Identity\Domain\User;
use App\SharedKernel\Domain\OrganizationId;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Clock\MockClock;

/**
 * Logins through the real login form and code page, as the admin pages ask for them (ADMIN_AREA). Use it next to
 * LogsInUsers and TurnsOnTwoStepLogin, in a WebTestCase whose client keeps its kernel and reads $clock.
 */
trait LogsInAdmins
{
    /**
     * Turns two-step login on and logs in with a code from the app, at the clock's time after a 30-second step.
     *
     * @return string the seed
     */
    private function logInWithACode(KernelBrowser $client, MockClock $clock, User $user): string
    {
        [$seed] = $this->turnOnTwoStepLogin($client, $clock, $user);
        $clock->sleep(30);
        $this->openCodePage($client, $user);
        $client->submitForm('Log in', ['code' => self::codeOf($seed, $clock)]);
        self::assertResponseRedirects('/', 302);

        return $seed;
    }

    private function logInWithThePassword(KernelBrowser $client, User $user): void
    {
        $client->request('GET', '/login');
        self::assertResponseStatusCodeSame(200);
        $client->submitForm('Log in', ['email' => $user->email()->toString(), 'password' => self::PASSWORD]);
        self::assertResponseRedirects('/', 302);
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
}
