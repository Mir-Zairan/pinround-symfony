<?php

declare(strict_types=1);

namespace App\Tests\Functional\Identity;

use App\Identity\Infrastructure\Mail\SymfonyPasswordResetMailer;
use App\Identity\UI\Controller\LoginController;
use App\Identity\UI\Controller\PasswordResetController;
use App\Tests\Factory\Identity\UserFactory;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Mime\Email;
use Zenstruck\Foundry\Test\Factories;

/**
 * "Forgot password" and the reset form through the real firewall, over HTTPS. The request counters live on the state
 * Valkey and outlast a test run, so every client asks from its own random IP.
 */
final class PasswordResetTest extends WebTestCase
{
    use Factories;
    use LogsInUsers;

    private const string NEW_PASSWORD = 'a much longer new passphrase';

    #[Test]
    public function aKnownEmailGetsOneQueuedEmailAndTheSamePageAsAnUnknownBlockedOrPasswordlessOne(): void
    {
        $client = self::httpsClient();
        $known = $this->createUserWithPassword('Lena Berg');
        $blocked = $this->createUserWithPassword('Mia Lund');
        $this->blockUser($blocked);
        $withoutPassword = UserFactory::createOne(['name' => 'Raj Patel']);
        $this->createdUserIds[] = $withoutPassword->id()->toString();

        $pages = [];
        foreach ([
            'known' => [$known->email()->toString(), 1],
            'unknown' => ['nobody.'.bin2hex(random_bytes(4)).'@acme-foods.example', 0],
            'blocked' => [$blocked->email()->toString(), 0],
            'without password' => [$withoutPassword->email()->toString(), 0],
            'not an email' => ['not an email', 0],
        ] as $case => [$email, $queuedEmails]) {
            $page = $this->askForLink($client, $email);
            self::assertResponseStatusCodeSame(200, $case);
            self::assertQueuedEmailCount($queuedEmails, message: $case);
            self::assertEmailCount(0, message: $case);
            $pages[$case] = $page->filter('main')->text();
        }

        self::assertSame(PasswordResetController::LINK_SENT, $this->lastCrawlerText($client, 'main [role="status"]'));
        self::assertCount(1, array_unique($pages));
        self::assertStringContainsString(PasswordResetController::LINK_SENT, $pages['known'] ?? '');
    }

    #[Test]
    public function theEmailHasATextAndAnHtmlPartWithTheLinkOnTheDefaultUriEvenForAnotherHost(): void
    {
        $client = self::httpsClient();
        $client->setServerParameter('HTTP_HOST', 'evil.example');
        $user = $this->createUserWithPassword('Omar Haddad');
        $this->askForLink($client, $user->email()->toString());
        self::assertResponseStatusCodeSame(200);
        $queued = self::getMailerMessage();
        self::assertInstanceOf(TemplatedEmail::class, $queued);
        $link = self::linkIn($queued);

        $sent = $this->sendQueuedEmailAsWorkerNotifyWould();

        self::assertMatchesRegularExpression('~^http://localhost/password/reset/[A-Za-z0-9_-]{43}$~', $link);
        self::assertSame([$user->email()->toString()], array_map(static fn ($address): string => $address->getAddress(), $sent->getTo()));
        self::assertSame('no-reply@pinround.localhost', $sent->getFrom()[0]->getAddress());
        self::assertSame(SymfonyPasswordResetMailer::SUBJECT, $sent->getSubject());
        self::assertStringContainsString($link, (string) $sent->getTextBody());
        self::assertStringContainsString('href="'.$link.'"', (string) $sent->getHtmlBody());
        self::assertStringContainsString('60 minutes', (string) $sent->getTextBody());
    }

    #[Test]
    public function aLinkWorksOnceAndTheNewPasswordReplacesTheOldOne(): void
    {
        $client = self::httpsClient();
        $user = $this->createUserWithPassword('Sara Holm');
        $link = $this->linkFor($client, $user->email()->toString());

        $this->openLink($client, $link);
        $client->submitForm('Save password', ['password' => self::NEW_PASSWORD, 'password_repeat' => self::NEW_PASSWORD]);

        self::assertResponseRedirects('/login', 302);
        $loginPage = $client->followRedirect();
        self::assertResponseStatusCodeSame(200);
        self::assertSame(PasswordResetController::PASSWORD_CHANGED, $loginPage->filter('[role="status"]')->text());
        self::assertSame(302, $this->statusAfterGettingHome($client), 'not logged in automatically');

        $this->openLink($client, $link);
        self::assertSame(PasswordResetController::LINK_NO_LONGER_WORKS, $this->lastCrawlerText($client, '[role="alert"]'));
        self::assertSame('/password/forgot', $client->getCrawler()->filter('main a[href="/password/forgot"]')->attr('href'));

        $this->submitLogin($client, $user->email()->toString(), self::PASSWORD);
        self::assertResponseRedirects('/login', 302);
        $client->followRedirect();
        self::assertSame(LoginController::WRONG_CREDENTIALS, $this->lastCrawlerText($client, '[role="alert"]'));
        $this->submitLogin($client, $user->email()->toString(), self::NEW_PASSWORD);
        self::assertResponseRedirects('/', 302);
    }

    #[Test]
    public function aLinkStillWorksAfterFiftyNineMinutes(): void
    {
        [$client, $clock] = self::httpsClientWithMockClock();
        $user = $this->createUserWithPassword('Lena Berg');
        $link = $this->linkFor($client, $user->email()->toString());

        $clock->modify('+59 minutes');
        $this->openLink($client, $link);
        $client->submitForm('Save password', ['password' => self::NEW_PASSWORD, 'password_repeat' => self::NEW_PASSWORD]);

        self::assertResponseRedirects('/login', 302);
    }

    #[Test]
    public function aLinkIsRefusedOneHourAndOneSecondAfterItWasSent(): void
    {
        [$client, $clock] = self::httpsClientWithMockClock();
        $user = $this->createUserWithPassword('Omar Haddad');
        $link = $this->linkFor($client, $user->email()->toString());

        $clock->modify('+1 hour +1 second');
        $this->openLink($client, $link);

        self::assertSame(PasswordResetController::LINK_NO_LONGER_WORKS, $this->lastCrawlerText($client, '[role="alert"]'));
        self::assertCount(0, $client->getCrawler()->filter('input[name="password"]'));
    }

    #[Test]
    public function aFormOpenedBeforeTheHourEndedIsRefusedWhenSentAfterIt(): void
    {
        [$client, $clock] = self::httpsClientWithMockClock();
        $user = $this->createUserWithPassword('Mia Lund');
        $link = $this->linkFor($client, $user->email()->toString());
        $this->openLink($client, $link);
        self::assertCount(1, $client->getCrawler()->filter('input[name="password"]'));

        $clock->modify('+1 hour +1 second');
        $client->submitForm('Save password', ['password' => self::NEW_PASSWORD, 'password_repeat' => self::NEW_PASSWORD]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(PasswordResetController::LINK_NO_LONGER_WORKS, $this->lastCrawlerText($client, '[role="alert"]'));
    }

    #[Test]
    public function aWrongTokenGetsTheSameAnswerAsAUsedOrExpiredOne(): void
    {
        $client = self::httpsClient();

        $client->request('GET', '/password/reset/'.rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='));
        self::assertResponseRedirects('/password/reset', 302);
        $client->followRedirect();

        self::assertResponseStatusCodeSame(200);
        self::assertSame(PasswordResetController::LINK_NO_LONGER_WORKS, $this->lastCrawlerText($client, '[role="alert"]'));
    }

    #[Test]
    public function theResetPageWithoutATokenInTheSessionSaysTheLinkNoLongerWorks(): void
    {
        $client = self::httpsClient();

        $client->request('GET', '/password/reset');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(PasswordResetController::LINK_NO_LONGER_WORKS, $this->lastCrawlerText($client, '[role="alert"]'));
    }

    #[Test]
    public function theLinkMovesItsTokenIntoTheSessionAndRedirectsToAnAddressWithoutIt(): void
    {
        $client = self::httpsClient();
        $user = $this->createUserWithPassword('Raj Patel');
        $link = $this->linkFor($client, $user->email()->toString());
        $token = basename($link);

        $client->request('GET', (string) parse_url($link, \PHP_URL_PATH));

        self::assertResponseRedirects('/password/reset', 302);
        $client->followRedirect();
        self::assertResponseStatusCodeSame(200);
        self::assertSame('https://localhost/password/reset', $client->getRequest()->getUri());
        self::assertStringNotContainsString($token, (string) $client->getResponse()->getContent());
        self::assertCount(1, $client->getCrawler()->filter('input[name="password"][type="password"][minlength="12"]'));
    }

    #[Test]
    public function aResetEndsTheSessionOfTheUserInAnotherBrowser(): void
    {
        $client = self::httpsClient();
        $user = $this->createUserWithPassword('Lena Berg');
        $otherBrowser = self::getContainer()->get('test.client');
        self::assertInstanceOf(KernelBrowser::class, $otherBrowser);
        $otherBrowser->setServerParameters(['HTTPS' => 'on']);
        $this->logIn($otherBrowser, $user);
        $otherBrowser->request('GET', '/');
        self::assertSame(200, $otherBrowser->getResponse()->getStatusCode());
        $link = $this->linkFor($client, $user->email()->toString());

        $this->openLink($client, $link);
        $client->submitForm('Save password', ['password' => self::NEW_PASSWORD, 'password_repeat' => self::NEW_PASSWORD]);
        self::assertResponseRedirects('/login', 302);

        $otherBrowser->request('GET', '/');
        self::assertSame(302, $otherBrowser->getResponse()->getStatusCode());
        self::assertSame('/login', parse_url((string) $otherBrowser->getResponse()->headers->get('Location'), \PHP_URL_PATH));
    }

    #[Test]
    public function aUserBlockedAfterTheLinkWasSentCannotUseIt(): void
    {
        $client = self::httpsClient();
        $user = $this->createUserWithPassword('Mia Lund');
        $link = $this->linkFor($client, $user->email()->toString());
        $this->openLink($client, $link);
        $this->blockUser($user);
        $hashQuery = 'SELECT password_hash FROM idn_credential WHERE user_id = ?';
        $hashBefore = $this->migrator()->fetchOne($hashQuery, [$user->id()->toString()]);

        $client->submitForm('Save password', ['password' => self::NEW_PASSWORD, 'password_repeat' => self::NEW_PASSWORD]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(PasswordResetController::LINK_NO_LONGER_WORKS, $this->lastCrawlerText($client, '[role="alert"]'));
        self::assertSame($hashBefore, $this->migrator()->fetchOne($hashQuery, [$user->id()->toString()]));
    }

    #[Test]
    public function aShortOrMismatchedPasswordIsRefusedAndLeavesTheLinkWorking(): void
    {
        $client = self::httpsClient();
        $user = $this->createUserWithPassword('Omar Haddad');
        $link = $this->linkFor($client, $user->email()->toString());
        $this->openLink($client, $link);

        $client->submitForm('Save password', ['password' => 'eleven char', 'password_repeat' => 'eleven char']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('A password needs at least 12 characters.', $this->lastCrawlerText($client, '[role="alert"]'));

        $client->submitForm('Save password', ['password' => self::NEW_PASSWORD, 'password_repeat' => self::NEW_PASSWORD.'!']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(PasswordResetController::PASSWORDS_DIFFER, $this->lastCrawlerText($client, '[role="alert"]'));

        $client->submitForm('Save password', ['password' => self::NEW_PASSWORD, 'password_repeat' => self::NEW_PASSWORD]);
        self::assertResponseRedirects('/login', 302);
    }

    #[Test]
    public function aResetFormWithAWrongCsrfTokenChangesNothing(): void
    {
        $client = self::httpsClient();
        $user = $this->createUserWithPassword('Sara Holm');
        $link = $this->linkFor($client, $user->email()->toString());
        $this->openLink($client, $link);

        $client->submitForm('Save password', ['password' => self::NEW_PASSWORD, 'password_repeat' => self::NEW_PASSWORD, '_csrf_token' => 'forged']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(LoginController::FORM_EXPIRED, $this->lastCrawlerText($client, '[role="alert"]'));
        $this->submitLogin($client, $user->email()->toString(), self::PASSWORD);
        self::assertResponseRedirects('/', 302);
    }

    #[Test]
    public function aForgotFormWithAWrongCsrfTokenSendsNothing(): void
    {
        $client = self::httpsClient();
        $user = $this->createUserWithPassword('Mia Lund');
        $client->request('GET', '/password/forgot');
        self::assertResponseStatusCodeSame(200);

        $client->submitForm('Send link', ['email' => $user->email()->toString(), '_csrf_token' => 'forged']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(LoginController::FORM_EXPIRED, $this->lastCrawlerText($client, '[role="alert"]'));
        self::assertQueuedEmailCount(0);
    }

    #[Test]
    public function theSixthRequestWithinFifteenMinutesFromOneEmailAndIpIsThrottledAndSendsNothing(): void
    {
        $client = self::httpsClient();
        $user = $this->createUserWithPassword('Raj Patel');
        $email = $user->email()->toString();
        foreach ([$email, strtoupper($email), $email, ucfirst($email), $email] as $typedEmail) {
            $this->askForLink($client, $typedEmail);
            self::assertResponseStatusCodeSame(200);
            self::assertQueuedEmailCount(1);
        }

        $this->askForLink($client, $email);

        self::assertResponseStatusCodeSame(429);
        self::assertSame('Too many tries. Wait 15 minutes.', $this->lastCrawlerText($client, '[role="alert"]'));
        self::assertQueuedEmailCount(0);
        self::assertCount(0, $client->getCrawler()->filter('main [role="status"]'));
    }

    #[Test]
    public function anUnknownEmailIsThrottledWithTheSameAnswer(): void
    {
        $client = self::httpsClient();
        $email = 'nobody.'.bin2hex(random_bytes(4)).'@acme-foods.example';
        for ($request = 1; $request <= 5; ++$request) {
            $this->askForLink($client, $email);
            self::assertResponseStatusCodeSame(200);
        }

        $this->askForLink($client, $email);

        self::assertResponseStatusCodeSame(429);
        self::assertSame('Too many tries. Wait 15 minutes.', $this->lastCrawlerText($client, '[role="alert"]'));
    }

    #[Test]
    public function fiftyRequestsFromOneIpRefuseAnyEmailForTheRestOfTheHour(): void
    {
        $client = self::httpsClient();
        for ($request = 1; $request <= 50; ++$request) {
            $this->askForLink($client, 'nobody'.$request.'.'.bin2hex(random_bytes(4)).'@acme-foods.example');
            self::assertResponseStatusCodeSame(200);
        }

        $this->askForLink($client, 'nobody51.'.bin2hex(random_bytes(4)).'@acme-foods.example');

        self::assertResponseStatusCodeSame(429);
        self::assertSame('Too many tries. Wait 60 minutes.', $this->lastCrawlerText($client, '[role="alert"]'));
    }

    #[Test]
    public function aLoggedInUserOpeningForgotPasswordGoesHome(): void
    {
        $client = self::httpsClient();
        $this->logIn($client, $this->createUserWithPassword('Lena Berg'));

        $client->request('GET', '/password/forgot');
        self::assertResponseRedirects('/', 302);
        $client->request('POST', '/password/forgot', ['email' => 'lena@acme-foods.example']);
        self::assertResponseRedirects('/', 302);
        self::assertQueuedEmailCount(0);
    }

    #[Test]
    public function theLoginPageLinksToForgotPassword(): void
    {
        $client = self::httpsClient();

        $page = $client->request('GET', '/login');

        self::assertResponseStatusCodeSame(200);
        self::assertSame('Forgot password?', $page->filter('main a[href="/password/forgot"]')->text());
    }

    private function askForLink(KernelBrowser $client, string $email): Crawler
    {
        $client->request('GET', '/password/forgot');
        self::assertResponseStatusCodeSame(200);

        return $client->submitForm('Send link', ['email' => $email]);
    }

    private function linkFor(KernelBrowser $client, string $email): string
    {
        $this->askForLink($client, $email);
        self::assertResponseStatusCodeSame(200);
        self::assertQueuedEmailCount(1);
        $email = self::getMailerMessage();
        self::assertInstanceOf(TemplatedEmail::class, $email);

        return self::linkIn($email);
    }

    /**
     * The mailer logs a rendered copy of a queued email (rendering empties the context), so the link is read from its
     * text part.
     */
    private static function linkIn(TemplatedEmail $email): string
    {
        self::assertSame(1, preg_match('~https?://\S+/password/reset/[A-Za-z0-9_-]+~', (string) $email->getTextBody(), $match));

        return $match[0];
    }

    private function openLink(KernelBrowser $client, string $link): void
    {
        $client->request('GET', (string) parse_url($link, \PHP_URL_PATH));
        self::assertResponseRedirects('/password/reset', 302);
        $client->followRedirect();
        self::assertResponseStatusCodeSame(200);
    }

    /**
     * Hands the queued message to the bus as a worker receiving it from async_notify would; the test env's null mailer
     * transport then renders and "sends" it.
     */
    private function sendQueuedEmailAsWorkerNotifyWould(): Email
    {
        $transport = self::getContainer()->get('messenger.transport.async_notify');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $envelopes = $transport->getSent();
        self::assertCount(1, $envelopes);
        self::getContainer()->get('command.bus')->dispatch($envelopes[0]->with(new ReceivedStamp('async_notify')));

        $sent = array_values(array_filter(self::getMailerEvents(), static fn (MessageEvent $event): bool => !$event->isQueued()));
        self::assertCount(1, $sent);
        $email = $sent[0]->getMessage();
        self::assertInstanceOf(Email::class, $email);

        return $email;
    }

    private function submitLogin(KernelBrowser $client, string $email, string $password): void
    {
        $client->request('GET', '/login');
        self::assertResponseStatusCodeSame(200);
        $client->submitForm('Log in', ['email' => $email, 'password' => $password]);
    }

    private function statusAfterGettingHome(KernelBrowser $client): int
    {
        $client->request('GET', '/');

        return $client->getResponse()->getStatusCode();
    }

    private function lastCrawlerText(KernelBrowser $client, string $selector): string
    {
        return $client->getCrawler()->filter($selector)->text();
    }

    private static function httpsClient(): KernelBrowser
    {
        return self::createClient([], ['HTTPS' => 'on', 'REMOTE_ADDR' => \sprintf('10.%d.%d.%d', random_int(0, 255), random_int(0, 255), random_int(1, 254))]);
    }

    /**
     * A client that keeps its kernel, so the clock it is given stays in every service across requests. The clock
     * starts at the real time: the session's own timestamps come from PHP's time().
     *
     * @return array{KernelBrowser, MockClock}
     */
    private static function httpsClientWithMockClock(): array
    {
        $client = self::httpsClient();
        $client->disableReboot();
        $clock = new MockClock();
        self::getContainer()->set('clock', $clock);

        return [$client, $clock];
    }
}
