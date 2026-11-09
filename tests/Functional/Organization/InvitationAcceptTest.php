<?php

declare(strict_types=1);

namespace App\Tests\Functional\Organization;

use App\Identity\Api\InvitedPersonRegistration;
use App\Identity\Api\RegistrationRefusal;
use App\Identity\Api\RegistrationRefused;
use App\Identity\Application\RegisterInvitedPerson;
use App\Organization\UI\InvitationController;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Functional\Identity\LogsInUsers;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * The invite accept page through the real firewall, over HTTPS, with links the mailer built (SendsInvitationLinks).
 */
final class InvitationAcceptTest extends WebTestCase
{
    use Factories;
    use LogsInUsers;
    use SendsInvitationLinks;

    private const string NEW_PASSWORD = 'a long enough new passphrase';
    private const string INVITE_PAGE = 'https://localhost/invite';

    #[Test]
    public function aNewPersonChoosesANameAndPasswordJoinsAndLogsInWithThem(): void
    {
        $client = self::httpsClient();
        $organizationId = $this->createOrganization('Acme Foods');
        $email = self::newEmail('mia');
        $link = $this->invite($organizationId, $email);

        $this->openLink($client, $link);
        self::assertCount(1, $client->getCrawler()->filter('main input[name="password"][type="password"][minlength="12"]'));
        self::assertSame('At least 12 characters.', $client->getCrawler()->filter('main #invitation-password-hint')->text());
        $emailField = $client->getCrawler()->filter('main form input[type="email"][readonly][autocomplete="username"]:not([name])');
        self::assertSame($email, $emailField->attr('value'));
        self::assertCount(1, $client->getCrawler()->filterXPath('//main//form/label[input[@type="email"]]/following-sibling::label[1]/input[@name="password"]'), 'the email field comes right before the password');
        $client->submitForm('Join', ['name' => 'Mia Lund', 'password' => self::NEW_PASSWORD]);

        self::assertResponseRedirects('/login', 302);
        $client->followRedirect();
        self::assertResponseStatusCodeSame(200);
        self::assertSame(\sprintf(InvitationController::ACCOUNT_READY, 'Acme Foods'), $client->getCrawler()->filter('[role="status"]')->text());
        $userId = $this->userIdWithEmail($email);
        self::assertNotNull($userId, 'no account was created');
        self::assertSame(1, $this->membershipCount($organizationId, $userId));
        $client->request('GET', '/');
        self::assertResponseRedirects('/login', 302, 'not logged in automatically');

        $this->submitLogin($client, $email, self::NEW_PASSWORD);
        self::assertResponseRedirects('/', 302);
    }

    #[Test]
    public function aUsedLinkSaysItIsNoLongerValidOnTheSecondClick(): void
    {
        $client = self::httpsClient();
        $organizationId = $this->createOrganization('Acme Foods');
        $email = self::newEmail('omar');
        $link = $this->invite($organizationId, $email);
        $this->openLink($client, $link);
        $client->submitForm('Join', ['name' => 'Omar Haddad', 'password' => self::NEW_PASSWORD]);
        self::assertResponseRedirects('/login', 302);
        $userId = $this->userIdWithEmail($email);
        self::assertNotNull($userId);

        $this->openLink($client, $link);

        self::assertSame(InvitationController::NO_LONGER_VALID, $client->getCrawler()->filter('main [role="alert"]')->text());
        self::assertCount(0, $client->getCrawler()->filter('main form'));
        self::assertSame(1, $this->membershipCount($organizationId, $userId));
    }

    #[Test]
    public function anExistingUserLogsInFromTheInviteAndJoins(): void
    {
        $client = self::httpsClient();
        $user = $this->createUserWithPassword('Raj Patel');
        $organizationId = $this->createOrganization('Beta Bakery');
        $link = $this->invite($organizationId, $user->email()->toString());

        $this->openLink($client, $link);
        self::assertCount(0, $client->getCrawler()->filter('main input[name="password"]'));
        self::assertSame('/login', $client->getCrawler()->selectLink('Log in to join')->attr('href'));

        $this->submitLogin($client, $user->email()->toString(), self::PASSWORD);
        self::assertResponseRedirects(self::INVITE_PAGE, 302, 'the login brings the person back to the invite');
        $client->followRedirect();
        self::assertResponseStatusCodeSame(200);
        $client->submitForm('Join');

        self::assertResponseRedirects('/', 302);
        self::assertSame(1, $this->membershipCount($organizationId, $user->id()->toString()));
        $client->followRedirect();
        self::assertResponseStatusCodeSame(200);
        self::assertSame(\sprintf(InvitationController::JOINED, 'Beta Bakery'), $client->getCrawler()->filter('main [role="status"]')->text());
    }

    #[Test]
    public function aPersonWhoJoinedSinceTheInviteGoesInWithANotice(): void
    {
        $client = self::httpsClient();
        $user = $this->createUserWithPassword('Sara Holm');
        $organizationId = $this->createOrganization('Beta Bakery');
        $link = $this->invite($organizationId, $user->email()->toString());
        $this->logIn($client, $user);
        $this->openLink($client, $link);
        $this->addMembership($user, $organizationId);

        $client->submitForm('Join');

        self::assertResponseRedirects('/', 302);
        $client->followRedirect();
        self::assertResponseStatusCodeSame(200);
        self::assertSame(\sprintf(InvitationController::ALREADY_IN, 'Beta Bakery'), $client->getCrawler()->filter('main [role="status"]')->text());
        self::assertSame(1, $this->membershipCount($organizationId, $user->id()->toString()));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function homesOfRolesWithoutATeam(): iterable
    {
        yield 'manager' => ['{manager}', 'Teams at a glance'];
        yield 'admin' => ['{admin}', 'Your organization'];
    }

    #[Test]
    #[DataProvider('homesOfRolesWithoutATeam')]
    public function theNoticeShowsOnTheHomeOfTheJoinedRole(string $orgRoles, string $homeTitle): void
    {
        $client = self::httpsClient();
        $user = $this->createUserWithPassword('Raj Patel');
        $organizationId = $this->createOrganization('Beta Bakery');
        $link = $this->invite($organizationId, $user->email()->toString());
        $this->logIn($client, $user);
        $this->openLink($client, $link);
        $this->addMembership($user, $organizationId);
        $this->setOrgRoles($user, $organizationId, $orgRoles);

        $client->submitForm('Join');

        self::assertResponseRedirects('/', 302);
        $client->followRedirect();
        self::assertResponseStatusCodeSame(200);
        self::assertSame($homeTitle, $client->getCrawler()->filter('main h1')->text());
        self::assertSame(\sprintf(InvitationController::ALREADY_IN, 'Beta Bakery'), $client->getCrawler()->filter('main [role="status"]')->text());
    }

    #[Test]
    public function aPersonLoggedInWithAnotherEmailIsRefusedAndJoinsNothing(): void
    {
        $client = self::httpsClient();
        $other = $this->createUserWithPassword('Omar Haddad');
        $organizationId = $this->createOrganization('Beta Bakery');
        $link = $this->invite($organizationId, self::newEmail('mia'));
        $this->logIn($client, $other);

        $client->request('GET', (string) parse_url($link, \PHP_URL_PATH));
        self::assertResponseRedirects('/invite', 302);
        $client->followRedirect();

        self::assertResponseStatusCodeSame(403);
        self::assertSame(InvitationController::FOR_ANOTHER_EMAIL, $client->getCrawler()->filter('main [role="alert"]')->text());
        self::assertCount(1, $client->getCrawler()->filter('main form[action="/logout"]'));
        self::assertCount(0, $client->getCrawler()->filter('main form[action="/invite"]'));

        $client->request('POST', '/invite', ['_csrf_token' => 'any', 'name' => 'Omar Haddad', 'password' => self::NEW_PASSWORD]);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->membershipCount($organizationId, $other->id()->toString()));
        self::assertSame(0, $this->acceptedInvitationCount($organizationId));
    }

    #[Test]
    public function anExpiredLinkSaysItIsNoLongerValid(): void
    {
        $client = self::httpsClient();
        $organizationId = $this->createOrganization('Acme Foods');
        $link = $this->invite($organizationId, self::newEmail('mia'));
        $this->committedAs($organizationId, static fn (Connection $migrator): int|string => $migrator->executeStatement(
            "UPDATE org_invitation SET expires_at = now() - interval '1 second' WHERE organization_id = ?",
            [$organizationId->toString()],
        ));

        $this->openLink($client, $link);

        self::assertSame(InvitationController::NO_LONGER_VALID, $client->getCrawler()->filter('main [role="alert"]')->text());
        self::assertCount(0, $client->getCrawler()->filter('main form'));
    }

    #[Test]
    public function aNewInviteOfTheSameEmailMakesTheOldLinkNoLongerValid(): void
    {
        $client = self::httpsClient();
        $organizationId = $this->createOrganization('Acme Foods');
        $email = self::newEmail('mia');
        $oldLink = $this->invite($organizationId, $email);
        $newLink = $this->invite($organizationId, $email);
        self::assertNotSame($oldLink, $newLink);

        $this->openLink($client, $oldLink);
        self::assertSame(InvitationController::NO_LONGER_VALID, $client->getCrawler()->filter('main [role="alert"]')->text());

        $this->openLink($client, $newLink);
        self::assertCount(1, $client->getCrawler()->filter('main input[name="password"]'));
    }

    #[Test]
    public function theLinkMovesItsTokenIntoTheSessionAndRedirectsToAnAddressWithoutIt(): void
    {
        $client = self::httpsClient();
        $organizationId = $this->createOrganization('Acme Foods');
        $link = $this->invite($organizationId, self::newEmail('mia'));
        $token = basename($link);

        $this->openLink($client, $link);

        self::assertSame(self::INVITE_PAGE, $client->getRequest()->getUri());
        self::assertStringNotContainsString($token, (string) $client->getResponse()->getContent());
    }

    #[Test]
    public function aJoinWithAWrongCsrfTokenCreatesNothing(): void
    {
        $client = self::httpsClient();
        $organizationId = $this->createOrganization('Acme Foods');
        $email = self::newEmail('mia');
        $link = $this->invite($organizationId, $email);
        $this->openLink($client, $link);

        $client->request('POST', '/invite', ['_csrf_token' => 'wrong', 'name' => 'Mia Lund', 'password' => self::NEW_PASSWORD]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(InvitationController::FORM_EXPIRED, $client->getCrawler()->filter('main [role="alert"]')->text());
        self::assertSame('Mia Lund', $client->getCrawler()->filter('main input[name="name"]')->attr('value'));
        self::assertNull($this->userIdWithEmail($email));
        self::assertSame(0, $this->acceptedInvitationCount($organizationId));
    }

    #[Test]
    public function aShortPasswordShowsTheRuleCreatesNothingAndLeavesTheLinkWorking(): void
    {
        $client = self::httpsClient();
        $organizationId = $this->createOrganization('Acme Foods');
        $email = self::newEmail('mia');
        $link = $this->invite($organizationId, $email);
        $this->openLink($client, $link);

        $client->submitForm('Join', ['name' => 'Mia Lund', 'password' => 'elevenchars']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(\sprintf(InvitationController::PASSWORD_TOO_SHORT, 12), $client->getCrawler()->filter('main [role="alert"]')->text());
        self::assertSame('Mia Lund', $client->getCrawler()->filter('main input[name="name"]')->attr('value'));
        self::assertNull($this->userIdWithEmail($email));
        self::assertSame(0, $this->acceptedInvitationCount($organizationId));

        $client->submitForm('Join', ['name' => 'Mia Lund', 'password' => self::NEW_PASSWORD]);
        self::assertResponseRedirects('/login', 302);
        self::assertNotNull($this->userIdWithEmail($email));
    }

    #[Test]
    public function anAccountMadeWithTheInvitedEmailMeanwhileIsAskedToLogInAndJoinsNothingYet(): void
    {
        $client = self::httpsClient();
        $organizationId = $this->createOrganization('Acme Foods');
        $email = self::newEmail('mia');
        $link = $this->invite($organizationId, $email);
        $this->openLink($client, $link);
        $user = $this->createUserWithoutOrganization('Mia Lund', self::PASSWORD, $email);

        $client->submitForm('Join', ['name' => 'Mia Lund', 'password' => self::NEW_PASSWORD]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(InvitationController::EMAIL_TAKEN, $client->getCrawler()->filter('main [role="alert"]')->text());
        self::assertSame('/login', $client->getCrawler()->selectLink('Log in to join')->attr('href'));
        self::assertCount(0, $client->getCrawler()->filter('main input[name="password"]'));
        self::assertSame(0, $this->membershipCount($organizationId, $user->id()->toString()));
        self::assertSame(0, $this->acceptedInvitationCount($organizationId));

        $this->submitLogin($client, $email, self::PASSWORD);
        self::assertResponseRedirects(self::INVITE_PAGE, 302, 'the login brings the person back to the invite');
        $client->followRedirect();
        self::assertResponseStatusCodeSame(200);
        self::assertCount(1, $client->getCrawler()->filter('main form[action="/invite"]'));
    }

    #[Test]
    public function anEmailTakenAtTheSameMomentAsTheJoinIsAskedToLogInAndJoinsNothing(): void
    {
        $client = self::httpsClient();
        $client->disableReboot();
        $organizationId = $this->createOrganization('Acme Foods');
        $email = self::newEmail('mia');
        $link = $this->invite($organizationId, $email);
        $refusingRegistration = new class implements InvitedPersonRegistration {
            public function minimumPasswordLength(): int
            {
                return 12;
            }

            public function maximumNameLength(): int
            {
                return 100;
            }

            public function register(string $email, string $name, #[\SensitiveParameter] string $password, \DateTimeImmutable $now): UserId
            {
                throw new RegistrationRefused([RegistrationRefusal::EmailTaken]);
            }
        };
        self::getContainer()->set(RegisterInvitedPerson::class, $refusingRegistration);
        $this->openLink($client, $link);

        $client->submitForm('Join', ['name' => 'Mia Lund', 'password' => self::NEW_PASSWORD]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(InvitationController::EMAIL_TAKEN, $client->getCrawler()->filter('main [role="alert"]')->text());
        self::assertSame('/login', $client->getCrawler()->selectLink('Log in to join')->attr('href'));
        self::assertNull($this->userIdWithEmail($email));
        self::assertSame(0, $this->acceptedInvitationCount($organizationId));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function refusedNames(): iterable
    {
        yield 'blank' => ['   ', InvitationController::NAME_BLANK];
        yield 'one character too long' => [str_repeat('m', 101), \sprintf(InvitationController::NAME_TOO_LONG, 100)];
    }

    #[Test]
    #[DataProvider('refusedNames')]
    public function aRefusedNameShowsItsRuleAndCreatesNothing(string $name, string $message): void
    {
        $client = self::httpsClient();
        $organizationId = $this->createOrganization('Acme Foods');
        $email = self::newEmail('mia');
        $this->openLink($client, $this->invite($organizationId, $email));

        $client->submitForm('Join', ['name' => $name, 'password' => self::NEW_PASSWORD]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame($message, $client->getCrawler()->filter('main [role="alert"]')->text());
        self::assertNull($this->userIdWithEmail($email));
        self::assertSame(0, $this->acceptedInvitationCount($organizationId));
    }

    #[Test]
    public function theInvitePageWithoutATokenInTheSessionSaysItIsNoLongerValid(): void
    {
        $client = self::httpsClient();

        $client->request('GET', '/invite');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(InvitationController::NO_LONGER_VALID, $client->getCrawler()->filter('main [role="alert"]')->text());
    }

    private function openLink(KernelBrowser $client, string $link): void
    {
        $client->request('GET', (string) parse_url($link, \PHP_URL_PATH));
        self::assertResponseRedirects('/invite', 302);
        $client->followRedirect();
        self::assertResponseStatusCodeSame(200);
    }

    private function submitLogin(KernelBrowser $client, string $email, string $password): void
    {
        $client->request('GET', '/login');
        self::assertResponseStatusCodeSame(200);
        $client->submitForm('Log in', ['email' => $email, 'password' => $password]);
    }

    /**
     * Also marks the account for deletion after the test.
     */
    private function userIdWithEmail(string $email): ?string
    {
        $userId = $this->migrator()->fetchOne('SELECT id FROM idn_user WHERE email = ?', [$email]);
        if (!\is_string($userId)) {
            return null;
        }
        $this->createdUserIds[] = $userId;

        return $userId;
    }

    private function membershipCount(OrganizationId $organizationId, string $userId): int
    {
        $count = 0;
        $this->committedAs($organizationId, static function (Connection $migrator) use ($organizationId, $userId, &$count): int {
            $value = $migrator->fetchOne('SELECT count(*) FROM org_membership WHERE organization_id = ? AND user_id = ?', [$organizationId->toString(), $userId]);
            $count = \is_int($value) ? $value : -1;

            return 0;
        });

        return $count;
    }

    private function acceptedInvitationCount(OrganizationId $organizationId): int
    {
        $count = 0;
        $this->committedAs($organizationId, static function (Connection $migrator) use ($organizationId, &$count): int {
            $value = $migrator->fetchOne('SELECT count(*) FROM org_invitation WHERE organization_id = ? AND accepted_at IS NOT NULL', [$organizationId->toString()]);
            $count = \is_int($value) ? $value : -1;

            return 0;
        });

        return $count;
    }

    private static function newEmail(string $name): string
    {
        return $name.'.'.bin2hex(random_bytes(4)).'@acme-foods.example';
    }

    private static function httpsClient(): KernelBrowser
    {
        return self::createClient([], ['HTTPS' => 'on', 'REMOTE_ADDR' => \sprintf('10.%d.%d.%d', random_int(0, 255), random_int(0, 255), random_int(1, 254))]);
    }
}
