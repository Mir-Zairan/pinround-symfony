<?php

declare(strict_types=1);

namespace App\Tests\Integration\Notification;

use App\Notification\UI\Component\LiveUpdates;
use App\Organization\Api\ActorContext;
use App\Organization\Api\CurrentActor;
use App\Organization\Api\Roles;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\UserId;
use App\Tests\Integration\Web\RendersTwigComponents;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;

/**
 * The live updates hook of a page: what a live-updates controller needs to subscribe and renew, and the paused banner.
 */
final class LiveUpdatesComponentTest extends KernelTestCase
{
    use RendersTwigComponents;

    private const string ACME_FOODS = '0197a3c0-0000-7000-8000-0000000000c1';
    private const string OMAR = '0197a3c0-0000-7000-8000-0000000000a2';
    private const string MIA = '0197a3c0-0000-7000-8000-0000000000a3';
    private const string TICKET = '0197a3c0-0000-7000-8000-0000000000d1';
    private const string OTHER_TICKET = '0197a3c0-0000-7000-8000-0000000000d2';
    private const string HUB = 'https://localhost/.well-known/mercure';
    private const string PAGE = '/t/SHOP-7?list=mine';

    private ?ActorContext $actor = null;

    private bool $isActorDoubleSet = false;

    protected function setUp(): void
    {
        self::bootKernel();
        self::getContainer()->get('request_stack')->push(Request::create('https://localhost'.self::PAGE));
    }

    protected function tearDown(): void
    {
        self::getContainer()->get('request_stack')->pop();
        parent::tearDown();
    }

    #[Test]
    public function aTicketPageSubscribesToTheViewersTopicAndTheTicketsTopic(): void
    {
        $this->actAs(new ActorContext(new UserId(self::OMAR), new OrganizationId(self::ACME_FOODS), Roles::none()));

        $liveUpdates = new Crawler((string) $this->renderTwigComponent(LiveUpdates::class, ['ticketId' => strtoupper(self::TICKET)]))->filter('.live-updates');

        self::assertCount(1, $liveUpdates);
        self::assertSame('live-updates', $liveUpdates->attr('data-controller'));
        self::assertSame(
            self::HUB.'?match='.rawurlencode('https://pinround.app/o/'.self::ACME_FOODS.'/u/'.self::OMAR).'&match='.rawurlencode('https://pinround.app/o/'.self::ACME_FOODS.'/t/'.self::TICKET),
            $liveUpdates->attr('data-live-updates-subscribe-url-value'),
        );
        self::assertSame('/realtime/token', $liveUpdates->attr('data-live-updates-token-url-value'));
        self::assertSame('["'.self::TICKET.'"]', $liveUpdates->attr('data-live-updates-ticket-ids-value'));
        self::assertSame(['status', 'polite', null], [$liveUpdates->attr('role'), $liveUpdates->attr('aria-live'), $liveUpdates->attr('hidden')]);
        $paused = $liveUpdates->filter('.live-updates__paused');
        self::assertSame(['Updates paused. Reload to see the latest.', null, ''], [$paused->text(), $paused->attr('role'), $paused->attr('hidden')]);
        self::assertCount(1, $liveUpdates->filter('[role="status"]'), 'one live region, which a hidden line inside does not repeat');
        self::assertSame(self::PAGE, $paused->filter('a.live-updates__reload')->attr('href'));
        self::assertSame('reload', $paused->filter('a.live-updates__reload')->attr('data-live-updates-target'));
    }

    /**
     * Turbo keeps a data-turbo-permanent element across a visit when the next page has one with the same id, and so
     * keeps its stream; a page that subscribes to other topics must get an element of its own.
     */
    #[Test]
    public function theElementSurvivesTurboVisitsOnlyToPagesWithTheSameSubscription(): void
    {
        $this->actAs(new ActorContext(new UserId(self::OMAR), new OrganizationId(self::ACME_FOODS), Roles::none()));
        $ticketPage = $this->renderedLiveUpdates(['ticketId' => self::TICKET]);
        $sameTicketPage = $this->renderedLiveUpdates(['ticketId' => strtoupper(self::TICKET)]);
        $otherTicketPage = $this->renderedLiveUpdates(['ticketId' => self::OTHER_TICKET]);
        $pageWithNoTicket = $this->renderedLiveUpdates([]);
        $this->actAs(new ActorContext(new UserId(self::MIA), new OrganizationId(self::ACME_FOODS), Roles::none()));
        $miasTicketPage = $this->renderedLiveUpdates(['ticketId' => self::TICKET]);

        self::assertSame('', $ticketPage->attr('data-turbo-permanent'));
        self::assertMatchesRegularExpression('/\Alive-updates-[0-9a-f]{32}\z/', (string) $ticketPage->attr('id'));
        self::assertSame($ticketPage->attr('id'), $sameTicketPage->attr('id'));
        $ids = array_map(static fn (Crawler $liveUpdates): ?string => $liveUpdates->attr('id'), [$ticketPage, $otherTicketPage, $pageWithNoTicket, $miasTicketPage]);
        self::assertSame($ids, array_values(array_unique($ids)), 'another ticket or another person keeps no stream');
    }

    /**
     * @param array<string, string> $props
     */
    private function renderedLiveUpdates(array $props): Crawler
    {
        $liveUpdates = new Crawler((string) $this->renderTwigComponent(LiveUpdates::class, $props))->filter('.live-updates');
        self::assertCount(1, $liveUpdates);

        return $liveUpdates;
    }

    #[Test]
    public function aPageWithNoTicketSubscribesToTheViewersTopicOnly(): void
    {
        $this->actAs(new ActorContext(new UserId(self::OMAR), new OrganizationId(self::ACME_FOODS), Roles::none()));

        $liveUpdates = new Crawler((string) $this->renderTwigComponent(LiveUpdates::class))->filter('.live-updates');

        self::assertSame(self::HUB.'?match='.rawurlencode('https://pinround.app/o/'.self::ACME_FOODS.'/u/'.self::OMAR), $liveUpdates->attr('data-live-updates-subscribe-url-value'));
        self::assertSame('[]', $liveUpdates->attr('data-live-updates-ticket-ids-value'));
    }

    #[Test]
    public function aVisitorWithNoActorGetsNothing(): void
    {
        $this->actAs(null);

        self::assertSame('', trim((string) $this->renderTwigComponent(LiveUpdates::class, ['ticketId' => self::TICKET])));
    }

    #[Test]
    public function aTicketIdThatIsNoTicketIdFailsAtMount(): void
    {
        $this->actAs(new ActorContext(new UserId(self::OMAR), new OrganizationId(self::ACME_FOODS), Roles::none()));

        $this->assertFailsAtMount(LiveUpdates::class, ['ticketId' => 'SHOP-7'], 'canonical UUIDv7');
    }

    /** Can be called again in one test: the container keeps the first double, which then reads the new actor. */
    private function actAs(?ActorContext $actor): void
    {
        $this->actor = $actor;
        if ($this->isActorDoubleSet) {
            return;
        }
        $this->isActorDoubleSet = true;
        self::getContainer()->set(CurrentActor::class, new readonly class(fn (): ?ActorContext => $this->actor) implements CurrentActor {
            /** @param \Closure(): ?ActorContext $currentActor */
            public function __construct(private \Closure $currentActor)
            {
            }

            public function actorFor(mixed $tokenUser): ?ActorContext
            {
                return ($this->currentActor)();
            }
        });
    }
}
