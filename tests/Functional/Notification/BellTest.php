<?php

declare(strict_types=1);

namespace App\Tests\Functional\Notification;

use App\Identity\Domain\User;
use App\Notification\UI\Controller\BellController;
use App\Organization\UI\Voter\OrganizationVoter;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\Tests\Functional\Identity\LogsInUsers;
use App\Tests\Functional\Web\CreatesTeams;
use App\Tests\Functional\Web\IssuesCsrfTokens;
use App\Tests\Integration\Ticketing\TicketRows;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;

/**
 * The bell's popover, Done and Open (BellController) through the real firewall, voters, session and request
 * transaction, over HTTPS, for Omar in Acme Foods. His bell has three pings from Lena about SHOP-1 and a question from
 * Mia about SHOP-2; next to them sit rows it must leave out: a ping from Lena he already marked done, and a ping from
 * Lena to Mia about SHOP-2, which is Mia's group, not his.
 */
final class BellTest extends WebTestCase
{
    use CreatesTeams;
    use Factories;
    use IssuesCsrfTokens;
    use LogsInUsers {
        tearDown as private removeUsersAndOrganizations;
    }

    private const string STREAM_MEDIA_TYPE = 'text/vnd.turbo-stream.html';
    private const string NEWEST_PING_TEXT = 'Could you look at the checkout button?';

    private MockClock $clock;
    private User $omar;
    private User $lena;
    private User $mia;
    private OrganizationId $acmeFoods;
    private string $shop1;
    private string $shop2;
    private string $lenaToMia;

    protected function tearDown(): void
    {
        self::getContainer()->get(TenantContext::class)->reset();
        new TicketRows($this->migrator(), self::getContainer()->get(TenantContext::class))->removeAllOf(...$this->createdOrganizationIds);
        $this->removeUsersAndOrganizations();
    }

    #[Test]
    public function thePopoverShowsOmarsGroupsLargestFirstWithTheirCountAndAge(): void
    {
        $client = $this->omarsClient();

        $frame = $client->request('GET', '/bell');

        self::assertResponseStatusCodeSame(200);
        self::assertCount(1, $frame->filter('turbo-frame#bell-frame #bell-popover-body'));
        self::assertSame('Pings · 4 open', $frame->filter('#bell-popover-title')->text());
        self::assertSame(['Lena 3 pings · 5 min ago', 'Mia asked you · 3 h ago'], $frame->filter('.ping-card__line')->each(static fn (Crawler $line): string => $line->text()));
        self::assertSame(['“'.self::NEWEST_PING_TEXT.'”'], $frame->filter('.ping-card__text')->each(static fn (Crawler $text): string => $text->text()));
        self::assertSame(['SHOP-1', 'SHOP-2'], $frame->filter('.ping-card__key')->each(static fn (Crawler $key): string => $key->text()));
        self::assertSame(['Done', 'Open', 'Done', 'Open'], $frame->filter('.ping-card button')->each(static fn (Crawler $button): string => $button->text()));
        self::assertSame(['_top'], array_values(array_unique($frame->filter('form[action$="/open"]')->each(static fn (Crawler $form): string => (string) $form->attr('data-turbo-frame')))));
    }

    #[Test]
    public function aPersonWithNoOpenNotificationsIsToldSo(): void
    {
        $client = $this->omarsClient();
        $this->logIn($client, $this->lena);

        $frame = $client->request('GET', '/bell');

        self::assertResponseStatusCodeSame(200);
        self::assertSame('Pings · 0 open', $frame->filter('#bell-popover-title')->text());
        self::assertSame('No open pings.', $frame->filter('.bell-popover__empty')->text());
        self::assertCount(0, $frame->filter('.ping-card'));
    }

    #[Test]
    public function doneFromThePopoverMarksTheGroupDoneAndAnswersWithTheBadgeThePopoverAndTheToast(): void
    {
        $client = $this->omarsClient();
        $rowsBefore = $this->rows();

        $client->request('POST', $this->groupUrl($this->lena, $this->shop1, 'done'), [BellController::CSRF_FIELD => $this->csrfToken($client)], server: ['HTTP_ACCEPT' => self::STREAM_MEDIA_TYPE.', text/html']);

        self::assertResponseStatusCodeSame(200);
        self::assertResponseHeaderSame('Content-Type', self::STREAM_MEDIA_TYPE.'; charset=UTF-8');
        $stream = new Crawler((string) $client->getResponse()->getContent());
        self::assertSame('1 open', $stream->filter('turbo-stream[target="bell-badge"] template')->text());
        $popover = new Crawler($stream->filter('turbo-stream[target="bell-popover-body"] template')->html());
        self::assertSame('Pings · 1 open', $popover->filter('#bell-popover-title')->text());
        self::assertSame(['Mia asked you · 3 h ago'], $popover->filter('.ping-card__line')->each(static fn (Crawler $line): string => $line->text()));
        self::assertStringContainsString('data-toast-message-value="Marked done."', $stream->filter('turbo-stream[target="toast-region"]')->html());
        self::assertSame($this->doneNow($rowsBefore, 'Lena to Omar on SHOP-1 at 50 min', 'Lena to Omar on SHOP-1 at 20 min', 'Lena to Omar on SHOP-1 at 5 min'), $this->rows());
    }

    #[Test]
    public function doneFromAPlainFormGoesHomeWithTheToastAndTheHeaderBadgeDrops(): void
    {
        $client = $this->omarsClient();
        $this->createTeam($this->acmeFoods, 'Shop', $this->omar);

        $home = $client->request('GET', '/');
        self::assertResponseStatusCodeSame(200);
        self::assertSame('4', $home->filter('header .bell__trigger .bell__badge')->text());
        self::assertSame('4 open', $home->filter('header #bell-badge')->text());

        $client->request('POST', $this->groupUrl($this->lena, $this->shop1, 'done'), [BellController::CSRF_FIELD => $this->csrfToken($client)]);

        self::assertResponseRedirects('/', 303);
        $home = $client->followRedirect();
        self::assertResponseStatusCodeSame(200);
        self::assertSame('Marked done.', $home->filter('#toast-region [data-toast-message-value]')->attr('data-toast-message-value'));
        self::assertSame('1', $home->filter('header .bell__trigger .bell__badge')->text());
    }

    #[Test]
    public function openMarksTheGroupReadAndGoesToTheTicket(): void
    {
        $client = $this->omarsClient();
        $rowsBefore = $this->rows();

        $client->request('POST', $this->groupUrl($this->lena, $this->shop1, 'open'), [BellController::CSRF_FIELD => $this->csrfToken($client)]);

        self::assertResponseRedirects('/t/SHOP-1', 303);
        $expected = $rowsBefore;
        foreach (['Lena to Omar on SHOP-1 at 50 min', 'Lena to Omar on SHOP-1 at 20 min', 'Lena to Omar on SHOP-1 at 5 min'] as $row) {
            $expected[$row]['read'] = true;
        }
        self::assertSame($expected, $this->rows());
    }

    #[Test]
    public function anotherPersonsGroupIsNotFoundAndStaysOpen(): void
    {
        $client = $this->omarsClient();
        $rowsBefore = $this->rows();
        self::assertSame(['read' => false, 'done' => false], $rowsBefore['Lena to Mia on SHOP-2']);

        // Lena's ping about SHOP-2 went to Mia; Omar has nothing from Lena about SHOP-2.
        foreach (['done', 'open'] as $action) {
            $client->request('POST', $this->groupUrl($this->lena, $this->shop2, $action), [BellController::CSRF_FIELD => $this->csrfToken($client)]);
            self::assertResponseStatusCodeSame(404, $action);
            self::assertStringContainsString(BellController::GROUP_NOT_FOUND, (string) $client->getResponse()->getContent(), $action);
        }

        self::assertSame($rowsBefore, $this->rows());
    }

    #[Test]
    public function aSecondDoneOfTheSameGroupIsNotFound(): void
    {
        $client = $this->omarsClient();
        $client->request('POST', $this->groupUrl($this->lena, $this->shop1, 'done'), [BellController::CSRF_FIELD => $this->csrfToken($client)]);
        self::assertResponseRedirects('/', 303);
        $rowsAfterFirstDone = $this->rows();

        $client->request('POST', $this->groupUrl($this->lena, $this->shop1, 'done'), [BellController::CSRF_FIELD => $this->csrfToken($client)]);

        self::assertResponseStatusCodeSame(404);
        self::assertSame($rowsAfterFirstDone, $this->rows());
    }

    #[Test]
    public function aMalformedIdIsNotFound(): void
    {
        $client = $this->omarsClient();
        $rowsBefore = $this->rows();

        foreach (['/bell/not-an-id/'.$this->shop1.'/done', '/bell/'.$this->lena->id()->toString().'/not-an-id/open'] as $url) {
            $client->request('POST', $url, [BellController::CSRF_FIELD => $this->csrfToken($client)]);
            self::assertResponseStatusCodeSame(404, $url);
        }

        self::assertSame($rowsBefore, $this->rows());
    }

    #[Test]
    public function aPostWithoutAValidCsrfTokenChangesNothing(): void
    {
        $client = $this->omarsClient();
        $rowsBefore = $this->rows();

        foreach (['done', 'open'] as $action) {
            foreach ([[], [BellController::CSRF_FIELD => 'forged']] as $csrf) {
                $client->request('POST', $this->groupUrl($this->lena, $this->shop1, $action), $csrf);
                self::assertResponseStatusCodeSame(422, $action);
                self::assertSame(BellController::FORM_EXPIRED, $client->getResponse()->getContent(), $action);
            }
        }

        self::assertSame($rowsBefore, $this->rows());
    }

    #[Test]
    public function everyBellRequestIsRefusedWhenTheOrganizationVoterDenies(): void
    {
        $client = $this->omarsClient();
        $csrfToken = $this->csrfToken($client);
        $rowsBefore = $this->rows();
        $this->replaceOrganizationVoter();

        $client->request('GET', '/bell');
        self::assertResponseStatusCodeSame(403);
        foreach (['done', 'open'] as $action) {
            $client->request('POST', $this->groupUrl($this->lena, $this->shop1, $action), [BellController::CSRF_FIELD => $csrfToken]);
            self::assertResponseStatusCodeSame(403, $action);
        }

        self::assertSame($rowsBefore, $this->rows());
    }

    #[Test]
    public function anAnonymousVisitorIsSentToTheLoginPage(): void
    {
        $client = self::createClient([], ['HTTPS' => 'on', 'REMOTE_ADDR' => self::randomIp()]);

        $client->request('GET', '/bell');

        self::assertResponseRedirects('/login', 302);
    }

    /**
     * Omar logged in on a client that keeps its kernel, with the clock two hours behind the real time, so an age
     * counted from the wall clock differs, yet within the session limits, whose own timestamps come from PHP's time().
     */
    private function omarsClient(): KernelBrowser
    {
        $client = self::createClient([], ['HTTPS' => 'on', 'REMOTE_ADDR' => self::randomIp()]);
        $client->disableReboot();
        $this->clock = new MockClock(new \DateTimeImmutable('@'.(time() - 7200)));
        self::getContainer()->set('clock', $this->clock);
        $this->omar = $this->createUserWithPassword('Omar Haddad');
        $this->acmeFoods = new OrganizationId($this->createdOrganizationIds[0]);
        $this->lena = $this->memberOfAcmeFoods('Lena Berg');
        $this->mia = $this->memberOfAcmeFoods('Mia Novak');
        $this->seedBells();
        $this->logIn($client, $this->omar);

        return $client;
    }

    private function memberOfAcmeFoods(string $name): User
    {
        $user = $this->createUserWithoutOrganization($name);
        $this->addMembership($user, $this->acmeFoods);

        return $user;
    }

    private function seedBells(): void
    {
        $this->shop1 = Uuid::v7()->toRfc4122();
        $this->shop2 = Uuid::v7()->toRfc4122();
        $projectId = Uuid::v7()->toRfc4122();
        $ticketRows = new TicketRows($this->migrator(), self::getContainer()->get(TenantContext::class));
        $ticketRows->addOf($this->acmeFoods->toString(), $this->shop1, $projectId, $this->lena->id()->toString(), 1);
        $ticketRows->addOf($this->acmeFoods->toString(), $this->shop2, $projectId, $this->lena->id()->toString(), 2);

        $newestPingId = Uuid::v7()->toRfc4122();
        $this->lenaToMia = Uuid::v7()->toRfc4122();
        $rows = [
            'Lena to Omar on SHOP-1 at 50 min' => $this->notification($this->lena, $this->omar, $this->shop1, 'ping', 50 * 60),
            'Lena to Omar on SHOP-1 at 20 min' => $this->notification($this->lena, $this->omar, $this->shop1, 'ping', 20 * 60),
            'Lena to Omar on SHOP-1 at 5 min' => $this->notification($this->lena, $this->omar, $this->shop1, 'ping', 5 * 60, ['pingId' => $newestPingId]),
            'Lena to Omar on SHOP-1, done' => [...$this->notification($this->lena, $this->omar, $this->shop1, 'ping', 60), 'done_at' => $this->secondsAgo(30)],
            'Mia to Omar on SHOP-2' => $this->notification($this->mia, $this->omar, $this->shop2, 'question', 3 * 3600 + 120),
            'Lena to Mia on SHOP-2' => [...$this->notification($this->lena, $this->mia, $this->shop2, 'ping', 2 * 60), 'id' => $this->lenaToMia],
        ];
        $this->rowIds = array_map(static fn (array $row): string => $row['id'], $rows);
        $ping = [
            'organization_id' => $this->acmeFoods->toString(),
            'id' => $newestPingId,
            'ticket_id' => $this->shop1,
            'from_id' => $this->lena->id()->toString(),
            'to_id' => $this->omar->id()->toString(),
            'body' => self::NEWEST_PING_TEXT,
            'created_at' => $this->secondsAgo(5 * 60),
        ];
        $this->committedAs($this->acmeFoods, static function (Connection $migrator) use ($rows, $ping): int {
            $migrator->insert('ntf_ping', $ping);
            foreach ($rows as $row) {
                $migrator->insert('ntf_notification', $row);
            }

            return \count($rows);
        });
    }

    /** @var array<string, string> the seeded notifications' ids by what they are */
    private array $rowIds = [];

    /**
     * @param array<string, string> $ids what it is about, as pingId
     *
     * @return array<string, mixed>
     */
    private function notification(User $sender, User $recipient, string $ticketId, string $kind, int $secondsAgo, array $ids = []): array
    {
        return [
            'organization_id' => $this->acmeFoods->toString(),
            'id' => Uuid::v7()->toRfc4122(),
            'user_id' => $recipient->id()->toString(),
            'kind' => $kind,
            'actor_id' => $sender->id()->toString(),
            'ticket_id' => $ticketId,
            'data' => json_encode(['actorName' => $sender->name(), 'ticketKey' => $ticketId === $this->shop1 ? 'SHOP-1' : 'SHOP-2', ...$ids], \JSON_THROW_ON_ERROR),
            'created_at' => $this->secondsAgo($secondsAgo),
        ];
    }

    private function secondsAgo(int $seconds): string
    {
        return $this->clock->now()->modify(\sprintf('-%d seconds', $seconds))->format('Y-m-d H:i:sP');
    }

    /**
     * @return array<string, array{read: bool, done: bool}> whether each seeded notification is read and done, by what it is
     */
    private function rows(): array
    {
        $tenantContext = self::getContainer()->get(TenantContext::class);
        $tenantContext->reset();
        $tenantContext->set($this->acmeFoods);
        try {
            $stored = $this->migrator()->transactional(fn (Connection $migrator): array => $migrator->fetchAllAssociativeIndexed(
                'SELECT id, read_at IS NOT NULL AS read, done_at IS NOT NULL AS done FROM ntf_notification WHERE organization_id = ?',
                [$this->acmeFoods->toString()],
            ));
        } finally {
            $tenantContext->reset();
        }
        self::assertCount(\count($this->rowIds), $stored);
        $states = [];
        foreach ($this->rowIds as $name => $id) {
            self::assertArrayHasKey($id, $stored);
            $states[$name] = ['read' => true === $stored[$id]['read'], 'done' => true === $stored[$id]['done']];
        }

        return $states;
    }

    /**
     * @param array<string, array{read: bool, done: bool}> $rows
     *
     * @return array<string, array{read: bool, done: bool}>
     */
    private function doneNow(array $rows, string ...$names): array
    {
        foreach ($names as $name) {
            $rows[$name]['done'] = true;
        }

        return $rows;
    }

    private function groupUrl(User $sender, string $ticketId, string $action): string
    {
        return \sprintf('/bell/%s/%s/%s', $sender->id()->toString(), $ticketId, $action);
    }

    private function csrfToken(KernelBrowser $client): string
    {
        return $this->csrfTokenFor($client, BellController::CSRF_ID);
    }

    /**
     * Call it before the voter is built, on a client that keeps its kernel.
     */
    private function replaceOrganizationVoter(): void
    {
        self::getContainer()->set(OrganizationVoter::class, new class extends Voter {
            protected function supports(string $attribute, mixed $subject): bool
            {
                return $subject instanceof OrganizationId;
            }

            protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
            {
                return false;
            }
        });
    }

    private static function randomIp(): string
    {
        return \sprintf('10.%d.%d.%d', random_int(0, 255), random_int(0, 255), random_int(1, 254));
    }
}
