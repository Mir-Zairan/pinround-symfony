<?php

declare(strict_types=1);

namespace App\Tests\Unit\Organization\UI;

use App\Identity\Api\LoginAssurance;
use App\Organization\Api\ActorContext;
use App\Organization\Api\AdminPermission;
use App\Organization\Api\OrganizationPermission;
use App\Organization\Api\Roles;
use App\Organization\UI\HideAdminAreaFromNonAdmins;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Unit\Organization\UI\Voter\FixedCurrentActor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\User\InMemoryUser;

final class HideAdminAreaFromNonAdminsTest extends TestCase
{
    private const string SARA = '0197a3c0-0000-7000-8000-0000000000a1';
    private const string RAJ = '0197a3c0-0000-7000-8000-0000000000a3';
    private const string OMAR = '0197a3c0-0000-7000-8000-0000000000a5';
    private const string NOBODY = '0197a3c0-0000-7000-8000-0000000000a4';
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-00000000000a';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-00000000000b';
    private const string SHOP = '0197a3c0-0000-7000-8000-0000000000c1';

    /**
     * @return iterable<string, array{string, OrganizationId}>
     */
    public static function peopleWhoAreNoAdminOfTheOrganization(): iterable
    {
        yield 'a Manager' => [self::RAJ, new OrganizationId(self::ACME_FOODS)];
        yield 'a team member without an org role' => [self::OMAR, new OrganizationId(self::ACME_FOODS)];
        yield 'an Admin at the admin area of another organization' => [self::SARA, new OrganizationId(self::BETA_BAKERY)];
    }

    #[Test]
    #[DataProvider('peopleWhoAreNoAdminOfTheOrganization')]
    public function aPersonWhoIsNoAdminOfTheOrganizationGetsNotFound(string $userId, OrganizationId $organizationId): void
    {
        $event = self::refusalOf([AdminPermission::AREA], $organizationId);

        self::listener($userId)($event);

        self::assertInstanceOf(NotFoundHttpException::class, $event->getThrowable());
        self::assertNull($event->getThrowable()->getPrevious(), 'the firewall would find the refusal in the chain and answer 403');
        self::assertNull($event->getResponse());
    }

    /**
     * @return iterable<string, array{?string, ExceptionEvent}>
     */
    public static function refusalsThatStayAsTheyAre(): iterable
    {
        yield 'an Admin of the organization' => [self::SARA, self::refusalOf([AdminPermission::AREA], new OrganizationId(self::ACME_FOODS))];
        yield 'a visitor who is not logged in' => [null, self::refusalOf([AdminPermission::AREA], new OrganizationId(self::ACME_FOODS))];
        yield 'a user without an actor' => [self::NOBODY, self::refusalOf([AdminPermission::AREA], new OrganizationId(self::ACME_FOODS))];
        yield 'a Manager refused another attribute' => [self::RAJ, self::refusalOf([OrganizationPermission::ADMIN], new OrganizationId(self::ACME_FOODS))];
        yield 'a Manager at an admin area without an organization' => [self::RAJ, self::refusalOf([AdminPermission::AREA], null)];
        yield 'a Manager at an admin area of a team' => [self::RAJ, self::refusalOf([AdminPermission::AREA], new TeamId(self::SHOP))];
        yield 'a Manager in a sub-request' => [self::RAJ, self::refusalOf([AdminPermission::AREA], new OrganizationId(self::ACME_FOODS), HttpKernelInterface::SUB_REQUEST)];
        yield 'a Manager at a page that failed otherwise' => [self::RAJ, new ExceptionEvent(self::kernel(), Request::create('/admin'), HttpKernelInterface::MAIN_REQUEST, new \RuntimeException('Something broke.'))];
    }

    #[Test]
    #[DataProvider('refusalsThatStayAsTheyAre')]
    public function everyOtherRefusalStaysAsItIs(?string $userId, ExceptionEvent $event): void
    {
        $before = $event->getThrowable();

        self::listener($userId)($event);

        self::assertSame($before, $event->getThrowable());
        self::assertNull($event->getResponse());
    }

    /**
     * AdminNeedsBrowserSessionVoter's 403 says why a token may not; Raj would get the 404 from a browser session.
     */
    #[Test]
    public function aRefusalOfAPersonalAccessTokenStaysAsItIs(): void
    {
        $event = self::refusalOf([AdminPermission::AREA], new OrganizationId(self::ACME_FOODS));
        $before = $event->getThrowable();

        self::listener(self::RAJ, isTokenAuthenticated: true)($event);

        self::assertSame($before, $event->getThrowable());
        self::assertNull($event->getResponse());
    }

    /**
     * @param list<string> $attributes
     */
    private static function refusalOf(array $attributes, mixed $subject, int $requestType = HttpKernelInterface::MAIN_REQUEST): ExceptionEvent
    {
        $refusal = new AccessDeniedException();
        $refusal->setAttributes($attributes);
        $refusal->setSubject($subject);

        return new ExceptionEvent(self::kernel(), Request::create('/admin'), $requestType, $refusal);
    }

    /**
     * Sara is an Admin, Raj a Manager and Omar a team member of Acme Foods, the organization of the request.
     */
    private static function listener(?string $userId, bool $isTokenAuthenticated = false): HideAdminAreaFromNonAdmins
    {
        $tokenStorage = new TokenStorage();
        $tokenStorage->setToken(null === $userId ? null : new UsernamePasswordToken(new InMemoryUser($userId, null), 'main'));
        $acmeFoods = new OrganizationId(self::ACME_FOODS);
        $currentActor = new FixedCurrentActor([
            self::SARA => new ActorContext(new UserId(self::SARA), $acmeFoods, new Roles(true, false, [], [], [])),
            self::RAJ => new ActorContext(new UserId(self::RAJ), $acmeFoods, new Roles(false, true, [], [], [])),
            self::OMAR => new ActorContext(new UserId(self::OMAR), $acmeFoods, new Roles(false, false, [], [new TeamId(self::SHOP)], [])),
        ]);

        return new HideAdminAreaFromNonAdmins($tokenStorage, $currentActor, self::loginAssurance($isTokenAuthenticated));
    }

    /**
     * Says every token is or is not a personal access token; the listener asks nothing else.
     */
    private static function loginAssurance(bool $isTokenAuthenticated): LoginAssurance
    {
        return new readonly class($isTokenAuthenticated) implements LoginAssurance {
            public function __construct(private bool $isTokenAuthenticated)
            {
            }

            public function isPasswordSession(mixed $token): bool
            {
                throw new \LogicException('Not used.');
            }

            public function isTwoStepLoginOn(mixed $token): bool
            {
                throw new \LogicException('Not used.');
            }

            public function hasConfirmedTwoStepLoginWithin(mixed $token, int $seconds): bool
            {
                throw new \LogicException('Not used.');
            }

            public function isTokenAuthenticated(mixed $token): bool
            {
                return $this->isTokenAuthenticated;
            }
        };
    }

    private static function kernel(): HttpKernelInterface
    {
        return new class implements HttpKernelInterface {
            public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
            {
                throw new \LogicException('Not used.');
            }
        };
    }
}
