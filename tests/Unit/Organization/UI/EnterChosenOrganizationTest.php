<?php

declare(strict_types=1);

namespace App\Tests\Unit\Organization\UI;

use App\Identity\Api\CurrentUser;
use App\Organization\Api\MembershipQueries;
use App\Organization\Api\Roles;
use App\Organization\UI\EnterChosenOrganization;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Application\TenantSwitchGuard;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RequestContext;

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
     * @param list<string> $organizationIds
     */
    private function handle(?string $userId, array $organizationIds, ?string $chosen = null, string $route = 'web_home', int $requestType = HttpKernelInterface::MAIN_REQUEST): RequestEvent
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
        $session = new Session(new MockArraySessionStorage());
        if (null !== $chosen) {
            $session->set(EnterChosenOrganization::SESSION_KEY, $chosen);
        }
        $request->setSession($session);
        $event = new RequestEvent(self::createStub(HttpKernelInterface::class), $request, $requestType);

        $this->tenantContext->reset();
        new EnterChosenOrganization($currentUser, $membershipQueries, $this->tenantContext, $urlGenerator, ['organization_choose', 'identity_profile'])($event);

        return $event;
    }
}
