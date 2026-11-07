<?php

declare(strict_types=1);

namespace App\Tests\Functional\Identity;

use App\Identity\Infrastructure\Security\LoginKeyHasher;
use Doctrine\DBAL\ParameterType;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Every try on the login form leaves one idn_login_attempt row, read here through the migrator (the requests commit
 * their own). Each client uses its own random IP, whose hash finds and removes its rows.
 */
final class LoginAttemptRecordTest extends WebTestCase
{
    use Factories;
    use LogsInUsers {
        LogsInUsers::tearDown as private deleteCreatedUsers;
    }

    private string $ip = '';

    #[Test]
    public function aSuccessfulLoginWritesOneRowWithSuccessTrue(): void
    {
        $client = $this->httpsClient();
        $user = $this->createUserWithPassword('Omar Haddad');

        $this->submitLogin($client, $user->email()->toString(), self::PASSWORD);

        self::assertResponseRedirects('/', 302);
        $rows = $this->rowsOf();
        self::assertCount(1, $rows);
        self::assertTrue($rows[0]['success']);
    }

    #[Test]
    public function aWrongPasswordAnUnknownEmailABlockedUserAndAThrottledTryEachWriteARowWithSuccessFalse(): void
    {
        $client = $this->httpsClient();
        $user = $this->createUserWithPassword('Mia Lund');

        $this->submitLogin($client, $user->email()->toString(), 'not the password at all');
        self::assertResponseRedirects('/login', 302);
        self::assertCount(1, $this->rowsOf(), 'wrong password');

        $this->submitLogin($client, 'nobody.'.bin2hex(random_bytes(4)).'@acme-foods.example', self::PASSWORD);
        self::assertResponseRedirects('/login', 302);
        self::assertCount(2, $this->rowsOf(), 'unknown email');

        $blocked = $this->createUserWithPassword('Raj Patel');
        $this->blockUser($blocked);
        $this->submitLogin($client, $blocked->email()->toString(), self::PASSWORD);
        self::assertResponseRedirects('/login', 302);
        self::assertCount(3, $this->rowsOf(), 'blocked user');

        $throttledEmail = 'nobody.'.bin2hex(random_bytes(4)).'@acme-foods.example';
        for ($try = 1; $try <= 6; ++$try) {
            $this->submitLogin($client, $throttledEmail, self::PASSWORD);
            self::assertResponseRedirects('/login', 302);
        }
        $page = $client->followRedirect();
        self::assertStringContainsString('Too many tries', $page->filter('[role="alert"]')->text());

        $rows = $this->rowsOf();
        self::assertCount(9, $rows, 'five checked tries and the throttled sixth');
        self::assertSame([false], array_values(array_unique(array_column($rows, 'success'))));
    }

    #[Test]
    public function aWrongCsrfTokenWritesOneRowWithSuccessFalse(): void
    {
        $client = $this->httpsClient();
        $user = $this->createUserWithPassword('Lena Brandt');
        $client->request('GET', '/login');
        self::assertResponseStatusCodeSame(200);

        $client->submitForm('Log in', ['email' => $user->email()->toString(), 'password' => self::PASSWORD, '_csrf_token' => 'forged']);

        self::assertResponseRedirects('/login', 302);
        $rows = $this->rowsOf();
        self::assertCount(1, $rows);
        self::assertFalse($rows[0]['success']);
    }

    #[Test]
    public function theEmailHashIsTheSameInEveryLetterCaseAndTheRowHoldsNothingInPlain(): void
    {
        $client = $this->httpsClient();
        $password = 'a password nobody would guess';

        $this->submitLogin($client, 'Omar@Acme-Foods.Example', $password);
        $this->submitLogin($client, 'omar@acme-foods.example', $password);

        $rows = $this->rowsOf();
        self::assertCount(2, $rows);
        self::assertSame($rows[0]['email_hash'], $rows[1]['email_hash']);
        $hasher = self::getContainer()->get(LoginKeyHasher::class);
        self::assertSame($hasher->hexOf('omar@acme-foods.example'), $rows[0]['email_hash']);
        self::assertSame($hasher->hexOf($this->ip), $rows[0]['ip_hash']);
        foreach ($rows as $row) {
            $wholeRow = strtolower(implode('|', array_map(strval(...), $row)));
            self::assertStringNotContainsString('omar', $wholeRow);
            self::assertStringNotContainsString($this->ip, $wholeRow);
            self::assertStringNotContainsString('guess', $wholeRow);
        }
    }

    protected function tearDown(): void
    {
        if ('' !== $this->ip) {
            $this->migrator()->executeStatement(
                'DELETE FROM idn_login_attempt WHERE ip_hash = ?',
                [self::getContainer()->get(LoginKeyHasher::class)->bytesOf($this->ip)],
                [ParameterType::BINARY],
            );
            $this->ip = '';
        }

        $this->deleteCreatedUsers();
    }

    /**
     * @return list<array{id: string, email_hash: string, ip_hash: string, success: bool, at: string}> oldest first;
     *                                                                                                 the hashes as hex
     */
    private function rowsOf(): array
    {
        /** @var list<array{id: string, email_hash: string, ip_hash: string, success: bool, at: string}> $rows */
        $rows = $this->migrator()->fetchAllAssociative(
            "SELECT id::text AS id, encode(email_hash, 'hex') AS email_hash, encode(ip_hash, 'hex') AS ip_hash, success, at::text AS at"
            .' FROM idn_login_attempt WHERE ip_hash = ? ORDER BY id',
            [self::getContainer()->get(LoginKeyHasher::class)->bytesOf($this->ip)],
            [ParameterType::BINARY],
        );

        return $rows;
    }

    private function httpsClient(): KernelBrowser
    {
        $this->ip = \sprintf('10.%d.%d.%d', random_int(0, 255), random_int(0, 255), random_int(1, 254));

        return self::createClient([], ['HTTPS' => 'on', 'REMOTE_ADDR' => $this->ip]);
    }

    private function submitLogin(KernelBrowser $client, string $email, string $password): void
    {
        $client->request('GET', '/login');
        self::assertResponseStatusCodeSame(200);
        $client->submitForm('Log in', ['email' => $email, 'password' => $password]);
    }
}
