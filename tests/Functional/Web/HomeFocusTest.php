<?php

declare(strict_types=1);

namespace App\Tests\Functional\Web;

use App\Identity\Domain\User;
use App\Organization\Api\ActorContext;
use App\Organization\Api\CurrentActor;
use App\Organization\Infrastructure\Security\RequestScopedCurrentActor;
use App\Organization\UI\Voter\OrganizationVoter;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\Tests\Functional\Identity\LogsInUsers;
use App\Web\UI\Controller\HomeFocusController;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Symfony\Contracts\Service\ResetInterface;
use Zenstruck\Foundry\Test\Factories;

/**
 * POST /home/focus (web_home_focus) through the real firewall, voters and session, over HTTPS. Home builds the chips
 * form with its CSRF token in the next step, so until then the tests take the token from the CSRF token manager on
 * the client's own session, as the form will.
 */
final class HomeFocusTest extends WebTestCase
{
    use Factories;
    use LogsInUsers {
        tearDown as private removeUsersAndOrganizations;
    }

    protected function tearDown(): void
    {
        foreach ($this->createdOrganizationIds as $organizationId) {
            $this->committedAs(new OrganizationId($organizationId), static fn (Connection $migrator): int|string => $migrator->executeStatement(
                'DELETE FROM web_home_focus WHERE organization_id = ?',
                [$organizationId],
            ));
        }
        $this->removeUsersAndOrganizations();
    }

    #[Test]
    public function theScriptsSaveIsAnsweredWithNoContentAndStoredForTheOrganization(): void
    {
        $client = self::httpsClient();
        $client->disableReboot();
        // Frozen at the real time with a fraction of a second: the session's own timestamps come from PHP's time(), and
        // the column keeps whole seconds.
        $clock = new MockClock(new \DateTimeImmutable('@'.time().'.750000'));
        self::getContainer()->set('clock', $clock);
        $user = $this->createUserWithPassword('Omar Haddad');
        $this->logIn($client, $user);

        $client->xmlHttpRequest('POST', '/home/focus', ['focus' => 'my_team', '_csrf_token' => $this->csrfToken($client)]);

        self::assertResponseStatusCodeSame(204);
        self::assertSame('', (string) $client->getResponse()->getContent());
        self::assertSame(['my_team', $clock->now()->format('Y-m-d H:i:s').'+00'], $this->storedRowOf($user, $this->onlyOrganization()));
    }

    #[Test]
    public function aPlainFormPostIsSentHomeWithSeeOther(): void
    {
        $client = self::httpsClient();
        $user = $this->createUserWithPassword('Mia Novak');
        $this->logIn($client, $user);

        $client->request('POST', '/home/focus', ['focus' => 'my_work', '_csrf_token' => $this->csrfToken($client)]);

        self::assertResponseRedirects('/', 303);
        self::assertSame('my_work', $this->storedFocusOf($user, $this->onlyOrganization()));
    }

    #[Test]
    public function aSecondChoiceReplacesTheFirst(): void
    {
        $client = self::httpsClient();
        $user = $this->createUserWithPassword('Mia Novak');
        $this->logIn($client, $user);

        $client->xmlHttpRequest('POST', '/home/focus', ['focus' => 'my_work', '_csrf_token' => $this->csrfToken($client)]);
        self::assertResponseStatusCodeSame(204);
        $client->xmlHttpRequest('POST', '/home/focus', ['focus' => 'all', '_csrf_token' => $this->csrfToken($client)]);

        self::assertResponseStatusCodeSame(204);
        self::assertSame('all', $this->storedFocusOf($user, $this->onlyOrganization()));
    }

    #[Test]
    public function theAdminChipIsSavedForAnAdminButRefusedToAnyoneElse(): void
    {
        $client = self::httpsClient();
        $member = $this->createUserWithPassword('Raj Patel');
        $organizationId = $this->onlyOrganization();
        $this->setOrgRoles($member, $organizationId, '{manager}');
        $this->logIn($client, $member);

        foreach (['admin', 'everything', ''] as $focus) {
            $client->xmlHttpRequest('POST', '/home/focus', ['focus' => $focus, '_csrf_token' => $this->csrfToken($client)]);
            self::assertResponseStatusCodeSame(422, $focus);
            self::assertSame(HomeFocusController::FOCUS_NOT_OFFERED, $client->getResponse()->getContent(), $focus);
        }
        self::assertNull($this->storedFocusOf($member, $organizationId));

        $this->setOrgRoles($member, $organizationId, '{admin}');
        $client->xmlHttpRequest('POST', '/home/focus', ['focus' => 'admin', '_csrf_token' => $this->csrfToken($client)]);

        self::assertResponseStatusCodeSame(204);
        self::assertSame('admin', $this->storedFocusOf($member, $organizationId));
    }

    #[Test]
    public function aPostWithoutAValidCsrfTokenSavesNothing(): void
    {
        $client = self::httpsClient();
        $user = $this->createUserWithPassword('Sara Holm');
        $this->logIn($client, $user);

        foreach ([[], ['_csrf_token' => 'forged']] as $csrf) {
            $client->xmlHttpRequest('POST', '/home/focus', ['focus' => 'my_team', ...$csrf]);
            self::assertResponseStatusCodeSame(422);
            self::assertSame(HomeFocusController::FORM_EXPIRED, $client->getResponse()->getContent());
        }

        self::assertNull($this->storedFocusOf($user, $this->onlyOrganization()));
    }

    #[Test]
    public function anAnonymousVisitorIsSentToTheLoginPage(): void
    {
        $client = self::httpsClient();

        $client->request('POST', '/home/focus', ['focus' => 'my_team']);

        self::assertResponseRedirects('/login', 302);
    }

    #[Test]
    public function aUserWhoHasNotChosenAnOrganizationIsSentToThePickerAndSavesNothing(): void
    {
        $client = self::httpsClient();
        $user = $this->createUserWithoutOrganization('Lena Berg');
        $acmeFoods = $this->createOrganization('Acme Foods');
        $this->addMembership($user, $acmeFoods);
        $this->addMembership($user, $this->createOrganization('Beta Bakery'));
        $this->logIn($client, $user);

        $client->xmlHttpRequest('POST', '/home/focus', ['focus' => 'my_team', '_csrf_token' => $this->csrfToken($client)]);

        self::assertResponseRedirects('/org/choose', 302);
        self::assertNull($this->storedFocusOf($user, $acmeFoods));
    }

    #[Test]
    public function aUserInTwoOrganizationsKeepsADifferentFocusInEach(): void
    {
        $client = self::httpsClient();
        $user = $this->createUserWithoutOrganization('Lena Berg');
        $acmeFoods = $this->createOrganization('Acme Foods');
        $betaBakery = $this->createOrganization('Beta Bakery');
        $this->addMembership($user, $acmeFoods);
        $this->addMembership($user, $betaBakery);
        $this->setOrgRoles($user, $acmeFoods, '{admin}');
        $this->logIn($client, $user);

        $this->choose($client, $acmeFoods);
        $client->xmlHttpRequest('POST', '/home/focus', ['focus' => 'admin', '_csrf_token' => $this->csrfToken($client)]);
        self::assertResponseStatusCodeSame(204);
        $this->choose($client, $betaBakery);
        // In Beta Bakery she is no admin, so the Acme Foods choice cannot be repeated there.
        $client->xmlHttpRequest('POST', '/home/focus', ['focus' => 'admin', '_csrf_token' => $this->csrfToken($client)]);
        self::assertResponseStatusCodeSame(422);
        $client->xmlHttpRequest('POST', '/home/focus', ['focus' => 'my_work', '_csrf_token' => $this->csrfToken($client)]);
        self::assertResponseStatusCodeSame(204);

        self::assertSame('admin', $this->storedFocusOf($user, $acmeFoods));
        self::assertSame('my_work', $this->storedFocusOf($user, $betaBakery));
    }

    #[Test]
    public function theSaveIsRefusedWhenTheOrganizationVoterDenies(): void
    {
        $client = self::httpsClient();
        $client->disableReboot();
        $user = $this->createUserWithPassword('Omar Haddad');
        $this->logIn($client, $user);
        $csrfToken = $this->csrfToken($client);
        $this->replaceOrganizationVoter(grants: false);

        // 403, not a 204: the voter is asked before anything is saved.
        $client->xmlHttpRequest('POST', '/home/focus', ['focus' => 'my_team', '_csrf_token' => $csrfToken]);

        self::assertResponseStatusCodeSame(403);
        self::assertNull($this->storedFocusOf($user, $this->onlyOrganization()));
    }

    #[Test]
    public function theSaveIsRefusedWithoutAnActorEvenWhenTheVoterGrants(): void
    {
        $client = self::httpsClient();
        $client->disableReboot();
        $user = $this->createUserWithPassword('Omar Haddad');
        $this->logIn($client, $user);
        $csrfToken = $this->csrfToken($client);
        $this->replaceOrganizationVoter(grants: true);
        // The kernel resets the real one between requests, so the stand-in must be resettable too.
        self::getContainer()->set(RequestScopedCurrentActor::class, new class implements CurrentActor, ResetInterface {
            public function actorFor(mixed $tokenUser): ?ActorContext
            {
                return null;
            }

            public function reset(): void
            {
            }
        });

        $client->xmlHttpRequest('POST', '/home/focus', ['focus' => 'my_team', '_csrf_token' => $csrfToken]);

        self::assertResponseStatusCodeSame(403);
        self::assertNull($this->storedFocusOf($user, $this->onlyOrganization()));
    }

    /**
     * Call it before the first request, while the voter is not built yet, on a client that keeps its kernel.
     */
    private function replaceOrganizationVoter(bool $grants): void
    {
        self::getContainer()->set(OrganizationVoter::class, new class($grants) extends Voter {
            public function __construct(private readonly bool $grants)
            {
            }

            protected function supports(string $attribute, mixed $subject): bool
            {
                return $subject instanceof OrganizationId;
            }

            protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
            {
                return $this->grants;
            }
        });
    }

    /**
     * A token for the chips form, stored in the client's session as rendering the form would store it.
     */
    private function csrfToken(KernelBrowser $client): string
    {
        $session = $client->getSession();
        self::assertNotNull($session);
        $request = Request::create('https://localhost/');
        $request->setSession($session);
        $requestStack = self::getContainer()->get('request_stack');
        $requestStack->push($request);
        try {
            $token = self::getContainer()->get('security.csrf.token_manager')->getToken('web_home_focus')->getValue();
        } finally {
            $requestStack->pop();
        }
        $session->save();

        return $token;
    }

    private function choose(KernelBrowser $client, OrganizationId $organizationId): void
    {
        $picker = $client->request('GET', '/org/choose');
        self::assertResponseStatusCodeSame(200);
        $form = $picker->filter(\sprintf('input[name="organization_id"][value="%s"]', $organizationId->toString()))->closest('form')?->form();
        self::assertNotNull($form);
        $client->submit($form);
        self::assertResponseRedirects('/', 302);
    }

    private function onlyOrganization(): OrganizationId
    {
        self::assertCount(1, $this->createdOrganizationIds);

        return new OrganizationId($this->createdOrganizationIds[0]);
    }

    private function storedFocusOf(User $user, OrganizationId $organizationId): ?string
    {
        return $this->storedRowOf($user, $organizationId)[0] ?? null;
    }

    /**
     * @return array{0?: string, 1?: string} the focus and its time in UTC, or nothing when no row is stored
     */
    private function storedRowOf(User $user, OrganizationId $organizationId): array
    {
        $tenantContext = self::getContainer()->get(TenantContext::class);
        $tenantContext->reset();
        $tenantContext->set($organizationId);
        try {
            $row = $this->migrator()->transactional(static fn (Connection $migrator): array|false => $migrator->fetchNumeric(
                "SELECT focus, to_char(updated_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS') || '+00' FROM web_home_focus WHERE organization_id = ? AND user_id = ?",
                [$organizationId->toString(), $user->id()->toString()],
            ));
        } finally {
            $tenantContext->reset();
        }
        if (false === $row) {
            return [];
        }
        [$focus, $updatedAt] = $row;
        self::assertIsString($focus);
        self::assertIsString($updatedAt);

        return [$focus, $updatedAt];
    }

    private static function httpsClient(): KernelBrowser
    {
        return self::createClient([], ['HTTPS' => 'on', 'REMOTE_ADDR' => self::randomIp()]);
    }

    private static function randomIp(): string
    {
        return \sprintf('10.%d.%d.%d', random_int(0, 255), random_int(0, 255), random_int(1, 254));
    }
}
