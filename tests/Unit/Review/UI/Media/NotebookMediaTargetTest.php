<?php

declare(strict_types=1);

namespace App\Tests\Unit\Review\UI\Media;

use App\Organization\Api\ActorContext;
use App\Organization\Api\Roles;
use App\Review\Api\AddedNotebookScreenshot;
use App\Review\Api\NotebookCursor;
use App\Review\Api\Notebooks;
use App\Review\Api\NotebooksPage;
use App\Review\Api\NotebookView;
use App\Review\Api\ScreenshotWithPins;
use App\Review\UI\Media\NotebookMediaTarget;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Unit\Organization\UI\Voter\FixedCurrentActor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;

/**
 * NotebookMediaTarget over a Notebooks that, like UseCaseNotebooks, finds a notebook only for its owner: Lena owns
 * LENAS_NOTEBOOK, Omar owns OMARS_NOTEBOOK; both of Acme Foods. Ben is an Admin of Beta Bakery.
 */
final class NotebookMediaTargetTest extends TestCase
{
    private const string LENA = '0197a3c0-0000-7000-8000-0000000018b1';
    private const string OMAR = '0197a3c0-0000-7000-8000-0000000018b2';
    private const string BEN = '0197a3c0-0000-7000-8000-0000000018b5';
    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-00000000181a';
    private const string BETA_BAKERY = '0197a3c0-0000-7000-8000-00000000181b';
    private const string LENAS_NOTEBOOK = '0197a3c0-0000-7000-8000-0000000018e1';
    private const string OMARS_NOTEBOOK = '0197a3c0-0000-7000-8000-0000000018e2';

    #[Test]
    public function theOwnerMayUploadForHerNotebook(): void
    {
        self::assertTrue(self::targetFor(self::tokenOf(self::LENA))->mayAttach(new OrganizationId(self::ACME_FOODS), new UserId(self::LENA), self::LENAS_NOTEBOOK));
        self::assertSame(Notebooks::UPLOAD_TARGET_KIND, self::targetFor(self::tokenOf(self::LENA))->targetKind());
    }

    /**
     * The token's user, who is asked about, the organization, the notebook id, and the person Notebooks acts for (the
     * token's user unless named). A row whose Notebooks would find the notebook is refused by NotebookMediaTarget's own
     * check alone.
     *
     * @return iterable<string, array{0: TokenInterface, 1: string, 2: string, 3: string, 4?: string}>
     */
    public static function refusedUploads(): iterable
    {
        yield 'another person\'s notebook' => [self::tokenOf(self::LENA), self::LENA, self::ACME_FOODS, self::OMARS_NOTEBOOK];
        yield 'an unknown id' => [self::tokenOf(self::LENA), self::LENA, self::ACME_FOODS, '0197a3c0-0000-7000-8000-0000000018e9'];
        yield 'a malformed id' => [self::tokenOf(self::LENA), self::LENA, self::ACME_FOODS, 'not-a-notebook'];
        yield 'asked about another person' => [self::tokenOf(self::OMAR), self::LENA, self::ACME_FOODS, self::LENAS_NOTEBOOK];
        yield 'the owner logged in, asked about another person' => [self::tokenOf(self::LENA), self::OMAR, self::ACME_FOODS, self::LENAS_NOTEBOOK];
        yield 'another organization than the actor\'s' => [self::tokenOf(self::LENA), self::LENA, self::BETA_BAKERY, self::LENAS_NOTEBOOK];
        yield 'an admin of another organization' => [self::tokenOf(self::BEN), self::BEN, self::BETA_BAKERY, self::LENAS_NOTEBOOK];
        yield 'nobody logged in, though Notebooks would find it' => [new NullToken(), self::LENA, self::ACME_FOODS, self::LENAS_NOTEBOOK, self::LENA];
    }

    #[Test]
    #[DataProvider('refusedUploads')]
    public function anyoneButTheOwnerAndAnyOtherTargetIsRefused(TokenInterface $token, string $askedAbout, string $organizationId, string $notebookId, ?string $notebooksActFor = null): void
    {
        self::assertFalse(self::targetFor($token, $notebooksActFor)->mayAttach(new OrganizationId($organizationId), new UserId($askedAbout), $notebookId));
    }

    /**
     * @param string|null $notebooksActFor whom the fake Notebooks finds notebooks for; null for the token's user
     */
    private static function targetFor(TokenInterface $token, ?string $notebooksActFor = null): NotebookMediaTarget
    {
        $tokenStorage = new TokenStorage();
        $tokenStorage->setToken($token);
        $acmeFoods = new OrganizationId(self::ACME_FOODS);
        $currentActor = new FixedCurrentActor([
            self::LENA => new ActorContext(new UserId(self::LENA), $acmeFoods, new Roles(false, false, [], [], [])),
            self::OMAR => new ActorContext(new UserId(self::OMAR), $acmeFoods, new Roles(false, false, [], [], [])),
            self::BEN => new ActorContext(new UserId(self::BEN), new OrganizationId(self::BETA_BAKERY), new Roles(true, false, [], [], [])),
        ]);
        $user = $token->getUser();
        $loggedIn = $user instanceof InMemoryUser ? $user->getUserIdentifier() : null;

        return new NotebookMediaTarget($tokenStorage, $currentActor, self::notebooksOf($notebooksActFor ?? $loggedIn));
    }

    private static function notebooksOf(?string $loggedIn): Notebooks
    {
        $owners = [self::LENAS_NOTEBOOK => self::LENA, self::OMARS_NOTEBOOK => self::OMAR];

        return new readonly class($owners, $loggedIn) implements Notebooks {
            /**
             * @param array<string, string> $owners
             */
            public function __construct(private array $owners, private ?string $loggedIn)
            {
            }

            public function get(string $notebookId): ?NotebookView
            {
                $owner = $this->owners[$notebookId] ?? null;
                if (null === $owner || $owner !== $this->loggedIn) {
                    return null;
                }
                $at = new \DateTimeImmutable('2027-03-15 10:00:00 UTC');

                return new NotebookView($notebookId, new OrganizationId('0197a3c0-0000-7000-8000-00000000181a'), new UserId($owner), 'Order page', [], [], 1, $at, $at, $at);
            }

            public function create(string $title, \DateTimeImmutable $at): string
            {
                throw new \LogicException('Not asked by NotebookMediaTarget.');
            }

            public function addScreenshot(string $notebookId, ScreenshotWithPins $screenshot, \DateTimeImmutable $at): AddedNotebookScreenshot
            {
                throw new \LogicException('Not asked by NotebookMediaTarget.');
            }

            public function rename(string $notebookId, string $title, \DateTimeImmutable $at): void
            {
                throw new \LogicException('Not asked by NotebookMediaTarget.');
            }

            public function delete(string $notebookId): void
            {
                throw new \LogicException('Not asked by NotebookMediaTarget.');
            }

            public function forOwner(?NotebookCursor $after = null): NotebooksPage
            {
                throw new \LogicException('Not asked by NotebookMediaTarget.');
            }

            public function replaceScenarios(string $notebookId, mixed $decodedScenarios, \DateTimeImmutable $at): void
            {
                throw new \LogicException('Not asked by NotebookMediaTarget.');
            }
        };
    }

    private static function tokenOf(string $userId): TokenInterface
    {
        return new UsernamePasswordToken(new InMemoryUser($userId, null), 'main');
    }
}
