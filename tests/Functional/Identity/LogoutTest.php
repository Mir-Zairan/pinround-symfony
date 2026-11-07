<?php

declare(strict_types=1);

namespace App\Tests\Functional\Identity;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Logout is the header's POST form with a CSRF token; nothing else ends the session.
 */
final class LogoutTest extends WebTestCase
{
    use Factories;
    use LogsInUsers;

    #[Test]
    public function theHeadersLogOutButtonEndsTheSession(): void
    {
        $client = $this->loggedInClient();
        $client->request('GET', '/');
        self::assertResponseStatusCodeSame(200);

        $client->submitForm('Log out');

        self::assertResponseRedirects('/login', 302);
        $client->request('GET', '/');
        self::assertResponseRedirects('/login', 302);
    }

    #[Test]
    public function aGetRequestDoesNotLogOut(): void
    {
        $client = $this->loggedInClient();

        $client->request('GET', '/logout');

        self::assertResponseStatusCodeSame(405);
        self::assertIsStillLoggedIn($client);
    }

    #[Test]
    public function aPostWithoutATokenDoesNotLogOut(): void
    {
        $client = $this->loggedInClient();

        $client->request('POST', '/logout');

        self::assertResponseStatusCodeSame(403);
        self::assertIsStillLoggedIn($client);
    }

    #[Test]
    public function aPostWithAWrongTokenDoesNotLogOut(): void
    {
        $client = $this->loggedInClient();

        $client->request('POST', '/logout', ['_csrf_token' => 'forged']);

        self::assertResponseStatusCodeSame(403);
        self::assertIsStillLoggedIn($client);
    }

    private function loggedInClient(): KernelBrowser
    {
        $client = self::createClient([], ['HTTPS' => 'on']);
        $this->logIn($client, $this->createUserWithPassword('Omar Haddad'));

        return $client;
    }

    private static function assertIsStillLoggedIn(KernelBrowser $client): void
    {
        $client->request('GET', '/');
        self::assertResponseStatusCodeSame(200);
    }
}
