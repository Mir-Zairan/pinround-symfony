<?php

declare(strict_types=1);

namespace App\Tests\Integration\Notification;

use App\Notification\UI\Component\PingSheet;
use App\Organization\Api\ActorContext;
use App\Organization\Api\CurrentActor;
use App\Organization\Api\Roles;
use App\Organization\Domain\OrgSettings;
use App\Organization\Infrastructure\Doctrine\DoctrineOrgSettingsStore;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Integration\Organization\OrganizationRows;
use App\Tests\Integration\Organization\TenantTransactions;
use App\Tests\Integration\Web\RendersTwigComponents;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * The ping sheet rendered as a move's stream answer renders it: under the request's tenant, after the move's
 * transaction ended, with nothing having read the settings yet, on committed org_settings rows as pinround_app.
 */
final class PingSheetComponentTest extends KernelTestCase
{
    use RendersTwigComponents;

    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000c5301';
    private const string OMAR = '0197a3c0-0000-7000-8000-0000000c5311';
    private const string LENA = '0197a3c0-0000-7000-8000-0000000c5312';
    private const string SARA = '0197a3c0-0000-7000-8000-0000000c5313';
    private const string SHOP_1 = '0197a3c0-0000-7000-8000-0000000c5321';

    protected function setUp(): void
    {
        self::bootKernel();
        $this->rows()->removeAllOf(self::ACME_FOODS);
        $this->transactions()->committedAs(self::ACME_FOODS, fn () => new DoctrineOrgSettingsStore($this->entityManager())->add(
            new OrgSettings(new OrganizationId(self::ACME_FOODS), new UserId(self::SARA), new \DateTimeImmutable('2027-03-15 10:00:00 UTC')),
        ));
        $this->transactions()->committedAs(self::ACME_FOODS, fn () => $this->connection()->executeStatement(
            "UPDATE org_settings SET data = jsonb_set(data, '{ping_limit_hours}', to_jsonb(8))",
        ));
        $request = Request::create('https://localhost/t/SHOP-1');
        $request->setSession(new Session(new MockArraySessionStorage()));
        self::getContainer()->get('request_stack')->push($request);
        self::getContainer()->set(CurrentActor::class, new readonly class(new ActorContext(new UserId(self::OMAR), new OrganizationId(self::ACME_FOODS), Roles::none())) implements CurrentActor {
            public function __construct(private ActorContext $actor)
            {
            }

            public function actorFor(mixed $tokenUser): ActorContext
            {
                return $this->actor;
            }
        });
    }

    protected function tearDown(): void
    {
        self::getContainer()->get('request_stack')->pop();
        $this->tenantContext()->clear();
        self::getContainer()->get('doctrine')->resetManager();
        $this->rows()->removeAllOf(self::ACME_FOODS);
        parent::tearDown();
    }

    #[Test]
    public function withNoTransactionOpenTheNoteNamesTheOrganizationsOwnLimit(): void
    {
        $this->tenantContext()->set(new OrganizationId(self::ACME_FOODS));

        $note = new Crawler((string) $this->renderTwigComponent(PingSheet::class, $this->props()))->filter('.ping-sheet__note');

        self::assertCount(1, $note);
        self::assertSame('Lena gets one notification. You can ping again after 8 hours (set by your admin).', $note->text());
        self::assertFalse($this->connection()->isTransactionActive());
    }

    #[Test]
    public function withNoTenantAndNoTransactionTheSheetFailsRatherThanNoteTheDefault(): void
    {
        $this->assertFailsAtMount(PingSheet::class, $this->props(), 'A transaction of its own needs the request\'s tenant.', \LogicException::class);
    }

    /**
     * @return array<string, mixed>
     */
    private function props(): array
    {
        return [
            'ticketId' => self::SHOP_1,
            'ticketKey' => 'SHOP-1',
            'ticketTitle' => 'Checkout button does nothing',
            'toUserId' => self::LENA,
            'toFirstName' => 'Lena',
            'peopleUserIds' => [self::OMAR, self::LENA],
        ];
    }

    private function rows(): OrganizationRows
    {
        return new OrganizationRows(self::getContainer()->get('doctrine.dbal.migrator_connection'), $this->tenantContext());
    }

    private function transactions(): TenantTransactions
    {
        return new TenantTransactions($this->entityManager(), $this->tenantContext());
    }

    private function connection(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.default_connection');
    }

    private function entityManager(): EntityManagerInterface
    {
        return self::getContainer()->get('doctrine.orm.default_entity_manager');
    }

    private function tenantContext(): TenantContext
    {
        return self::getContainer()->get(TenantContext::class);
    }
}
