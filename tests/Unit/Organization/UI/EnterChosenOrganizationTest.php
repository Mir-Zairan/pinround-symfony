<?php

declare(strict_types=1);

namespace App\Tests\Unit\Organization\UI;

use App\Identity\Api\ApiTokenAccess;
use App\Identity\Api\CurrentUser;
use App\Identity\Domain\Credential;
use App\Identity\Domain\Email;
use App\Identity\Domain\PasswordHash;
use App\Identity\Domain\User;
use App\Identity\Infrastructure\Security\ApiTokenAuthentication;
use App\Identity\Infrastructure\Security\SecurityUser;
use App\Organization\Api\MembershipQueries;
use App\Organization\Api\Roles;
use App\Organization\UI\EnterChosenOrganization;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Application\TenantSwitchGuard;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Unit\Organization\UI\Voter\FixedLoginAssurance;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

final class EnterChosenOrganizationTest extends TestCase
{
    private const string LENA = '0197a3c0-0000-7000-8000-0000000000a1';
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-00000000000a';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-00000000000b';

    private TenantContext $tenantContext;

    protected function setUp(): void
    {
        $this->tenantContext = new TenantContext(new class implements TenantSwitchGuard {
            public function prepareSwitch(): void
            {
            }
        });
    }

    #[Test]
    public function aValidChoiceBecomesTheTenantAndTheCountIsKeptForTheMenu(): void
    {
        $event = $this->handle(self::LENA, [self::ACME_FOODS, self::BETA_BAKERY], chosen: self::BETA_BAKERY);

        self::assertSame(self::BETA_BAKERY, $this->tenantContext->get()?->toString());
        self::assertNull($event->getResponse());
        self::assertSame(2, $event->getRequest()->attributes->get(EnterChosenOrganization::ORGANIZATION_COUNT_ATTRIBUTE));
    }

    #[Test]
    public function theOnlyOrganizationIsChosenWhenTheSessionHasNoValidChoice(): void
    {
        $event = $this->handle(self::LENA, [self::ACME_FOODS], chosen: self::BETA_BAKERY);

        self::assertSame(self::ACME_FOODS, $this->tenantContext->get()?->toString());
        self::assertSame(self::ACME_FOODS, $event->getRequest()->getSession()->get(EnterChosenOrganization::SESSION_KEY));
        self::assertNull($event->getResponse());
    }

    #[Test]
    public function aStaleChoiceIsRemovedAndTheUserSentToThePicker(): void
    {
        $event = $this->handle(self::LENA, [self::ACME_FOODS, self::BETA_BAKERY], chosen: '0197a3c0-0000-7000-8000-00000000000c');

        self::assertNull($this->tenantContext->get());
        self::assertFalse($event->getRequest()->getSession()->has(EnterChosenOrganization::SESSION_KEY));
        $response = $event->getResponse();
        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/org/choose', $response->getTargetUrl());
    }

    #[Test]
    public function aUserInNoOrganizationIsSentToThePickerExceptOnARouteThatWorksWithoutOne(): void
    {
        self::assertInstanceOf(RedirectResponse::class, $this->handle(self::LENA, [], route: 'web_home')->getResponse());

        $event = $this->handle(self::LENA, [], route: 'identity_profile');

        self::assertNull($event->getResponse());
        self::assertNull($this->tenantContext->get());
        self::assertSame(0, $event->getRequest()->attributes->get(EnterChosenOrganization::ORGANIZATION_COUNT_ATTRIBUTE));
    }

    #[Test]
    public function aRequestWithoutALoggedInUserOrASubRequestIsLeftAlone(): void
    {
        foreach ([[null, HttpKernelInterface::MAIN_REQUEST], [self::LENA, HttpKernelInterface::SUB_REQUEST]] as [$userId, $requestType]) {
            $event = $this->handle($userId, [self::ACME_FOODS], chosen: self::ACME_FOODS, requestType: $requestType);

            self::assertNull($this->tenantContext->get());
            self::assertNull($event->getResponse());
            self::assertFalse($event->getRequest()->attributes->has(EnterChosenOrganization::ORGANIZATION_COUNT_ATTRIBUTE));
        }
    }

    /**
     * The firewall of /mcp and /api/v1 is stateless: the session a request may still carry neither chooses the
     * organization nor is written, and a request without one works too.
     */
    #[Test]
    public function aTokenRequestWorksInTheTokensOrganizationAndNeverInTheSessionsChoice(): void
    {
        $event = $this->handle(self::LENA, [self::ACME_FOODS, self::BETA_BAKERY], chosen: self::ACME_FOODS, tokenOrganizationId: self::BETA_BAKERY);

        self::assertSame(self::BETA_BAKERY, $this->tenantContext->get()?->toString());
        self::assertNull($event->getResponse());
        self::assertSame(self::ACME_FOODS, $event->getRequest()->getSession()->get(EnterChosenOrganization::SESSION_KEY));

        $event = $this->handle(self::LENA, [self::ACME_FOODS, self::BETA_BAKERY], tokenOrganizationId: self::BETA_BAKERY, hasSession: false);

        self::assertSame(self::BETA_BAKERY, $this->tenantContext->get()?->toString());
        self::assertNull($event->getResponse());
    }

    #[Test]
    public function aTokenWhoseUserLeftItsOrganizationIsRefusedWithoutATenantEvenWhenTheUserHasAnotherOne(): void
    {
        $event = $this->handle(self::LENA, [self::ACME_FOODS], tokenOrganizationId: self::BETA_BAKERY);

        $response = $event->getResponse();
        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(401, $response->getStatusCode());
        self::assertSame('Bearer', $response->headers->get('WWW-Authenticate'));
        self::assertSame(['error' => EnterChosenOrganization::NOT_A_MEMBER_OF_THE_TOKENS_ORGANIZATION], json_decode((string) $response->getContent(), true));
        self::assertNull($this->tenantContext->get());
        self::assertFalse($event->getRequest()->getSession()->has(EnterChosenOrganization::SESSION_KEY));
    }

    /**
     * @param list<string> $organizationIds
     * @param string|null  $tokenOrganizationId the organization of the personal access token the request authenticated
     *                                          with; null for a browser session
     */
    private function handle(?string $userId, array $organizationIds, ?string $chosen = null, string $route = 'web_home', int $requestType = HttpKernelInterface::MAIN_REQUEST, ?string $tokenOrganizationId = null, bool $hasSession = true): RequestEvent
    {
        $currentUser = new readonly class($userId) implements CurrentUser {
            public function __construct(private ?string $userId)
            {
            }

            public function id(): ?UserId
            {
                return null === $this->userId ? null : new UserId($this->userId);
            }

            public function idOf(mixed $tokenUser): ?UserId
            {
                throw new \LogicException('The listener works for the request\'s user.');
            }
        };
        $membershipQueries = new readonly class($organizationIds) implements MembershipQueries {
            /**
             * @param list<string> $organizationIds
             */
            public function __construct(private array $organizationIds)
            {
            }

            public function organizationsOf(UserId $userId): array
            {
                return array_map(static fn (string $organizationId): OrganizationId => new OrganizationId($organizationId), $this->organizationIds);
            }

            public function rolesOf(UserId $userId, OrganizationId $organizationId): Roles
            {
                throw new \LogicException('The listener checks memberships only.');
            }

            public function membersAmong(OrganizationId $organizationId, array $userIds): array
            {
                throw new \LogicException('The listener asks for one user\'s organizations.');
            }
        };
        $urlGenerator = new class implements UrlGeneratorInterface {
            /**
             * @param array<mixed> $parameters
             */
            public function generate(string $name, array $parameters = [], int $referenceType = self::ABSOLUTE_PATH): string
            {
                return 'organization_choose' === $name ? '/org/choose' : throw new \LogicException('Unexpected route '.$name);
            }

            public function setContext(RequestContext $context): void
            {
            }

            public function getContext(): RequestContext
            {
                return new RequestContext();
            }
        };

        $request = Request::create('/');
        $request->attributes->set('_route', $route);
        if ($hasSession) {
            $session = new Session(new MockArraySessionStorage());
            if (null !== $chosen) {
                $session->set(EnterChosenOrganization::SESSION_KEY, $chosen);
            }
            $request->setSession($session);
        }
        $event = new RequestEvent(self::createStub(HttpKernelInterface::class), $request, $requestType);
        $tokenStorage = new TokenStorage();
        if (null !== $userId) {
            $tokenStorage->setToken(null === $tokenOrganizationId
                ? new UsernamePasswordToken(self::securityUser($userId), 'main', ['ROLE_USER'])
                : new ApiTokenAuthentication(self::securityUser($userId), new ApiTokenAccess('0197a3c0-0000-7000-8000-0000000000f1', new OrganizationId($tokenOrganizationId), ['read'])));
        }

        $this->tenantContext->reset();
        new EnterChosenOrganization(
            $currentUser,
            $membershipQueries,
            $this->tenantContext,
            $urlGenerator,
            $tokenStorage,
            new FixedLoginAssurance(isTwoStepLoginOn: true, codeProofAgeSeconds: 60),
            ['organization_choose', 'identity_profile'],
        )($event);

        return $event;
    }

    private static function securityUser(string $userId): SecurityUser
    {
        $user = new User(new UserId($userId), new Email('lena@acme-foods.example'), 'Lena Berg', 'en', 'Europe/Berlin', new \DateTimeImmutable('2026-11-02 09:30:00 UTC'));

        return SecurityUser::of($user, new Credential($user, new PasswordHash('$argon2id$v=19$m=10,t=3,p=1$stored'), new \DateTimeImmutable('2026-11-02 09:30:00 UTC')));
    }
}
