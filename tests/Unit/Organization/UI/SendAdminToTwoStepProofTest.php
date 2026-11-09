<?php

declare(strict_types=1);

namespace App\Tests\Unit\Organization\UI;

use App\Organization\Api\ActorContext;
use App\Organization\Api\AdminPermission;
use App\Organization\Api\OrganizationPermission;
use App\Organization\Api\Roles;
use App\Organization\UI\SendAdminToTwoStepProof;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Unit\Organization\UI\Voter\FixedCurrentActor;
use App\Tests\Unit\Organization\UI\Voter\FixedLoginAssurance;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Authentication\Token\RememberMeToken;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\User\InMemoryUser;

final class SendAdminToTwoStepProofTest extends TestCase
{
    private const string SARA = '0197a3c0-0000-7000-8000-0000000000a1';
    private const string RAJ = '0197a3c0-0000-7000-8000-0000000000a3';
    private const string NOBODY = '0197a3c0-0000-7000-8000-0000000000a4';
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-00000000000a';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-00000000000b';

    #[Test]
    public function anAdminWhoseTwoStepLoginIsOffGoesToTheSetupPageWithTheMessage(): void
    {
        $pages = self::pages();
        $event = self::refusalOfTheAdminArea();

        self::listener(self::passwordSessionOf(self::SARA), isTwoStepLoginOn: false, codeProofAgeSeconds: null, pages: $pages)($event);

        self::assertRedirectsTo('/setup', $event);
        self::assertSame([['setup', SendAdminToTwoStepProof::ADMIN_PAGES_NEED_TWO_STEP_LOGIN]], $pages->calls);
    }

    /**
     * @return iterable<string, array{?int}>
     */
    public static function missingOrOldCodeProofs(): iterable
    {
        yield 'no code in this session' => [null];
        yield 'a code 1 hour and 1 second old' => [3601];
    }

    #[Test]
    #[DataProvider('missingOrOldCodeProofs')]
    public function anAdminWithTwoStepLoginOnButNoCodeOfTheLastHourGoesToConfirmItsYou(?int $codeProofAgeSeconds): void
    {
        $pages = self::pages();
        $event = self::refusalOfTheAdminArea();

        self::listener(self::passwordSessionOf(self::SARA), isTwoStepLoginOn: true, codeProofAgeSeconds: $codeProofAgeSeconds, pages: $pages)($event);

        self::assertRedirectsTo('/confirm', $event);
        self::assertSame([['confirm']], $pages->calls);
    }

    #[Test]
    public function anAdminWithAFreshCodeRefusedForAnotherReasonKeepsThe403(): void
    {
        $event = self::refusalOfTheAdminArea();

        self::listener(self::passwordSessionOf(self::SARA), isTwoStepLoginOn: true, codeProofAgeSeconds: 3600)($event);

        self::assertNull($event->getResponse());
    }

    /**
     * @return iterable<string, array{TokenInterface|null, ExceptionEvent}>
     */
    public static function refusalsThatStay403(): iterable
    {
        yield 'a Manager' => [self::passwordSessionOf(self::RAJ), self::refusalOfTheAdminArea()];
        yield 'a user without an actor' => [self::passwordSessionOf(self::NOBODY), self::refusalOfTheAdminArea()];
        yield 'no token' => [null, self::refusalOfTheAdminArea()];
        yield 'an Admin by remember-me' => [new RememberMeToken(new InMemoryUser(self::SARA, null), 'main'), self::refusalOfTheAdminArea()];
        yield 'an Admin at the admin area of another organization' => [self::passwordSessionOf(self::SARA), self::refusalOfTheAdminArea(new OrganizationId(self::BETA_BAKERY))];
        yield 'an Admin at an admin area without an organization' => [self::passwordSessionOf(self::SARA), self::refusalOfTheAdminArea(null)];
        yield 'an Admin at an admin area of a team' => [self::passwordSessionOf(self::SARA), self::refusalOfTheAdminArea(new TeamId(self::ACME_FOODS))];
        yield 'an Admin refused another attribute' => [self::passwordSessionOf(self::SARA), self::refusalOf([OrganizationPermission::ADMIN], new OrganizationId(self::ACME_FOODS))];
        yield 'an Admin refused with no attribute named' => [self::passwordSessionOf(self::SARA), self::refusalOf([], new OrganizationId(self::ACME_FOODS))];
        yield 'an Admin in a sub-request' => [self::passwordSessionOf(self::SARA), self::refusalOfTheAdminArea(requestType: HttpKernelInterface::SUB_REQUEST)];
        yield 'an Admin at a page that failed otherwise' => [self::passwordSessionOf(self::SARA), new ExceptionEvent(self::kernel(), Request::create('/_test/admin-area'), HttpKernelInterface::MAIN_REQUEST, new \RuntimeException('Something broke.'))];
    }

    /**
     * Two-step login is off for everyone here, so a listener that let any of them through would redirect to setup.
     */
    #[Test]
    #[DataProvider('refusalsThatStay403')]
    public function everyoneElseKeepsThe403(?TokenInterface $token, ExceptionEvent $event): void
    {
        $pages = self::pages();

        self::listener($token, isTwoStepLoginOn: false, codeProofAgeSeconds: null, pages: $pages)($event);

        self::assertNull($event->getResponse());
        self::assertSame([], $pages->calls);
    }

    private static function assertRedirectsTo(string $path, ExceptionEvent $event): void
    {
        $response = $event->getResponse();
        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame($path, $response->getTargetUrl());
    }

    private static function passwordSessionOf(string $userId): TokenInterface
    {
        return new UsernamePasswordToken(new InMemoryUser($userId, null), 'main');
    }

    private static function refusalOfTheAdminArea(mixed $subject = new OrganizationId(self::ACME_FOODS), int $requestType = HttpKernelInterface::MAIN_REQUEST): ExceptionEvent
    {
        return self::refusalOf([AdminPermission::AREA], $subject, $requestType);
    }

    /**
     * @param list<string> $attributes
     */
    private static function refusalOf(array $attributes, mixed $subject, int $requestType = HttpKernelInterface::MAIN_REQUEST): ExceptionEvent
    {
        $refusal = new AccessDeniedException();
        $refusal->setAttributes($attributes);
        $refusal->setSubject($subject);

        return new ExceptionEvent(self::kernel(), Request::create('/_test/admin-area'), $requestType, $refusal);
    }

    /**
     * Sara is an Admin and Raj a Manager of Acme Foods, the organization of the request.
     */
    private static function listener(?TokenInterface $token, bool $isTwoStepLoginOn, ?int $codeProofAgeSeconds, ?RecordingTwoStepProofPages $pages = null): SendAdminToTwoStepProof
    {
        $tokenStorage = new TokenStorage();
        $tokenStorage->setToken($token);
        $acmeFoods = new OrganizationId(self::ACME_FOODS);
        $currentActor = new FixedCurrentActor([
            self::SARA => new ActorContext(new UserId(self::SARA), $acmeFoods, new Roles(true, false, [], [], [])),
            self::RAJ => new ActorContext(new UserId(self::RAJ), $acmeFoods, new Roles(false, true, [], [], [])),
        ]);

        return new SendAdminToTwoStepProof($tokenStorage, $currentActor, new FixedLoginAssurance($isTwoStepLoginOn, $codeProofAgeSeconds), $pages ?? self::pages());
    }

    private static function pages(): RecordingTwoStepProofPages
    {
        return new RecordingTwoStepProofPages();
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
