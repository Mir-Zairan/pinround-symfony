<?php

declare(strict_types=1);

namespace App\Tests\Functional\Identity;

use App\Identity\Domain\User;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * A new session stamp on a user ends every open session of that user on its next request, and no one else's.
 */
final class SessionStampTest extends WebTestCase
{
    use Factories;
    use LogsInUsers;

    #[Test]
    public function rotatingAUsersStampSendsEachOfTheirSessionsToTheLoginPage(): void
    {
        $firstBrowser = self::createClient([], ['HTTPS' => 'on']);
        $omar = $this->createUserWithPassword('Omar Haddad');
        $mia = $this->createUserWithPassword('Mia Lund');
        $secondBrowser = self::anotherClient();
        $miasBrowser = self::anotherClient();
        $this->logIn($firstBrowser, $omar);
        $this->logIn($secondBrowser, $omar);
        $this->logIn($miasBrowser, $mia);
        foreach ([$firstBrowser, $secondBrowser, $miasBrowser] as $browser) {
            $browser->request('GET', '/');
            self::assertSame(200, $browser->getResponse()->getStatusCode());
        }

        $this->rotateSessionStampOf($omar);

        foreach ([$firstBrowser, $secondBrowser] as $browser) {
            $browser->request('GET', '/');
            self::assertSame(302, $browser->getResponse()->getStatusCode());
            self::assertSame('/login', parse_url((string) $browser->getResponse()->headers->get('Location'), \PHP_URL_PATH));
        }
        $miasBrowser->request('GET', '/');
        self::assertSame(200, $miasBrowser->getResponse()->getStatusCode());
    }

    #[Test]
    public function aNewLoginAfterTheRotationStaysIn(): void
    {
        $browser = self::createClient([], ['HTTPS' => 'on']);
        $lena = $this->createUserWithPassword('Lena Berg');
        $this->rotateSessionStampOf($lena);

        $this->logIn($browser, $lena);
        $browser->request('GET', '/');

        self::assertResponseStatusCodeSame(200);
    }

    /**
     * A second browser on the same kernel, with its own cookies and so its own session.
     */
    private static function anotherClient(): KernelBrowser
    {
        $client = self::getContainer()->get('test.client');
        self::assertInstanceOf(KernelBrowser::class, $client);
        $client->setServerParameters(['HTTPS' => 'on']);

        return $client;
    }

    private function rotateSessionStampOf(User $user): void
    {
        $this->migrator()->executeStatement(
            "UPDATE idn_user SET session_stamp = replace(gen_random_uuid()::text, '-', '') WHERE id = ?",
            [$user->id()->toString()],
        );
        // The test's entity manager still holds the user with the old stamp; a later logIn() must load the new one.
        self::getContainer()->get('doctrine.orm.default_entity_manager')->clear();
    }
}
