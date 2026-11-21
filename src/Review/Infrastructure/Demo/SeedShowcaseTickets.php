<?php

declare(strict_types=1);

namespace App\Review\Infrastructure\Demo;

use App\Identity\Api\UserQueries;
use App\Media\Api\PictureFileSeeds;
use App\Organization\Api\DemoOrganizations;
use App\Review\Api\CheckItemView;
use App\Review\Api\ChecklistCommands;
use App\Review\Api\ChecklistQueries;
use App\Review\Api\PlacedPin;
use App\Review\Api\RoundCommands;
use App\Review\Api\ScreenshotWithPins;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Application\TenantSwitchRefused;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\SharedKernel\Infrastructure\Console\DemoSeedRefused;
use App\SharedKernel\Infrastructure\Console\DemoSeedStep;
use App\Ticketing\Api\DemoTicketTitles;
use App\Ticketing\Api\DraftChoice;
use App\Ticketing\Api\ProjectQueries;
use App\Ticketing\Api\TicketCommands;
use App\Ticketing\Api\TicketKeyQueries;
use App\Ticketing\Api\TicketQueries;
use App\Ticketing\Api\TicketVisibilities;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * Three SHOP tickets that show what pinround is for, one per planted bug of the bundled demo shop (demo-shop/BUGS.md;
 * bug 1 is left for the README's "Try the loop"). Each has Mia's report with a real screenshot and pins (Round 0),
 * the checklist round made from it, and Omar's fix shared as a round whose Befores are the report's pictures:
 *
 * - SEARCH_TITLE is done: Lena approved the code and Mia passed it, with every checklist item OK.
 * - TOTAL_TITLE waits in Testing for Mia: Lena approved the code; the checklist items are marked fixed.
 * - ORDERS_TITLE is back with Omar: Mia found a new order still missing and said "Needs changes" with her findings
 *   round, whose picture is "Not right yet".
 *
 * Every step goes through the commands a person's click runs (TicketCommands::move with the team's transition policy,
 * RoundCommands, ChecklistCommands), so the history, the turn, the pings and the round rules (ADR-0048) are the real
 * ones. The pictures are the PNGs in showcase/, processed at once (PictureFileSeeds). It runs after SeedDemoTickets,
 * so the 41 tickets keep their keys, and keeps a ticket whose title SHOP holds already, with all it has.
 */
#[AsTaggedItem(priority: 25)]
final readonly class SeedShowcaseTickets implements DemoSeedStep
{
    public const string SEARCH_TITLE = 'Search finds nothing for Bread with a capital B';
    public const string TOTAL_TITLE = 'Cart total disappears after a discount code';
    public const string ORDERS_TITLE = 'Orders filter leaves out the last order';
    public const string ORDERS_FINDING = 'A new order still goes missing under Placed.';

    private const string ORGANIZATION = 'acme-foods';
    private const string PROJECT = 'SHOP';
    private const string LENA_EMAIL = 'lena@acme-foods.example';
    private const string OMAR_EMAIL = 'omar@acme-foods.example';
    private const string MIA_EMAIL = 'mia@acme-foods.example';

    public function __construct(
        private DemoOrganizations $demoOrganizations,
        private UserQueries $userQueries,
        private ProjectQueries $projectQueries,
        private DemoTicketTitles $demoTicketTitles,
        private TicketCommands $ticketCommands,
        private TicketKeyQueries $ticketKeyQueries,
        private TicketQueries $ticketQueries,
        private TicketVisibilities $ticketVisibilities,
        private RoundCommands $roundCommands,
        private ChecklistCommands $checklistCommands,
        private ChecklistQueries $checklistQueries,
        private PictureFileSeeds $pictureFileSeeds,
        private TenantContext $tenantContext,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws TenantSwitchRefused when a transaction is open, from DemoOrganizations, before anything is read here
     */
    public function seed(): string
    {
        $organizationId = $this->demoOrganizations->idBySlug(self::ORGANIZATION)
            ?? throw new DemoSeedRefused(\sprintf('The demo organization %s does not exist yet, so the showcase tickets cannot be made. Seed the organizations first.', self::ORGANIZATION));

        $created = $this->inTransactionOf($organizationId, fn (): int => $this->seedShowcase($organizationId));

        return \sprintf('Showcase tickets: created %d, kept %d.', $created, 3 - $created);
    }

    private function seedShowcase(OrganizationId $organizationId): int
    {
        $project = $this->projectQueries->projectByKey(self::PROJECT)
            ?? throw new DemoSeedRefused(\sprintf('The demo project %s does not exist yet, so the showcase tickets cannot be made. Seed the projects first.', self::PROJECT));
        $cast = new ShowcaseCast(
            $organizationId,
            $this->personByEmail(self::LENA_EMAIL, 'Lena'),
            $this->personByEmail(self::OMAR_EMAIL, 'Omar'),
            $this->personByEmail(self::MIA_EMAIL, 'Mia'),
            $project->id(),
        );
        $existingTitles = $this->demoTicketTitles->keysByTitleIn(self::PROJECT);
        $now = $this->clock->now();

        $created = 0;
        if (!isset($existingTitles[self::SEARCH_TITLE])) {
            $this->seedSearchTicket($cast, $now->modify('-6 days'));
            ++$created;
        }
        if (!isset($existingTitles[self::TOTAL_TITLE])) {
            $this->seedTotalTicket($cast, $now->modify('-3 days'));
            ++$created;
        }
        if (!isset($existingTitles[self::ORDERS_TITLE])) {
            $this->seedOrdersTicket($cast, $now->modify('-2 days'));
            ++$created;
        }

        return $created;
    }

    private function seedSearchTicket(ShowcaseCast $cast, \DateTimeImmutable $reportedAt): void
    {
        $key = $this->reportTicket(
            $cast,
            self::SEARCH_TITLE,
            "Where: Products page, the search box.\n\nSteps:\n1. Open Products.\n2. Search for Bread, with a capital B.\n\n"
            ."Expected: Sourdough bread is listed.\n\nActual: \"No products match “Bread”.\" Searching for bread in lowercase finds it.",
            'major',
            new ShowcasePicture('search-bread-finds-nothing.png', 'Products search', [new PlacedPin(30.0, 39.0, 'No results for Bread, but Sourdough bread is in the shop.')]),
            $reportedAt,
        );
        $this->fixAndSendToReview(
            $cast,
            $key,
            new ShowcasePicture('search-bread-finds-bread.png', 'Products search', [new PlacedPin(22.0, 47.0, 'Bread now finds Sourdough bread.')], 'Search now ignores letter case.'),
            $reportedAt,
        );
        $this->move($cast, $cast->lena, $key, 'approve_code', $reportedAt->modify('+1 day 2 hours'));
        $this->confirmItems($cast, $key, $cast->mia, $reportedAt->modify('+1 day 5 hours'));
        $this->move($cast, $cast->mia, $key, 'pass_it', $reportedAt->modify('+1 day 5 hours'));
    }

    private function seedTotalTicket(ShowcaseCast $cast, \DateTimeImmutable $reportedAt): void
    {
        $key = $this->reportTicket(
            $cast,
            self::TOTAL_TITLE,
            "Where: Cart.\n\nSteps:\n1. Add only Oat milk to the cart.\n2. Enter WELCOME10 and press Apply.\n\n"
            ."Expected: a Total line under the discount, €1.66.\n\nActual: Subtotal and Discount show, but the Total line is gone. "
            .'With more than €10 in the cart the Total is there.',
            'critical',
            new ShowcasePicture('cart-total-missing-with-code.png', 'Cart with WELCOME10', [new PlacedPin(16.0, 66.0, 'The Total line should be here, under the discount.')]),
            $reportedAt,
        );
        $this->fixAndSendToReview(
            $cast,
            $key,
            new ShowcasePicture('cart-total-shown-with-code.png', 'Cart with WELCOME10', [new PlacedPin(20.0, 67.0, 'Total shows €1.66 with the code.')], 'The total now shows with any code.'),
            $reportedAt,
        );
        $this->move($cast, $cast->lena, $key, 'approve_code', $reportedAt->modify('+1 day 2 hours'));
    }

    private function seedOrdersTicket(ShowcaseCast $cast, \DateTimeImmutable $reportedAt): void
    {
        $key = $this->reportTicket(
            $cast,
            self::ORDERS_TITLE,
            "Where: Orders page, the Status filter.\n\nSteps:\n1. Press Reset demo data.\n2. Open Orders, choose Shipped and press Filter.\n\n"
            ."Expected: 3 orders: 1002, 1004 and 1006.\n\nActual: 2 orders. 1006 is missing, and it is always the last order in the list.",
            'major',
            new ShowcasePicture('orders-shipped-two-of-three.png', 'Orders, Shipped', [new PlacedPin(14.0, 39.0, 'Says 2 orders; 1006 is shipped too.')]),
            $reportedAt,
        );
        $this->fixAndSendToReview(
            $cast,
            $key,
            new ShowcasePicture('orders-shipped-three-of-three.png', 'Orders, Shipped', [new PlacedPin(14.0, 39.0, 'Shipped now shows all 3.')], 'The filter now checks every order.'),
            $reportedAt,
        );
        $this->move($cast, $cast->lena, $key, 'approve_code', $reportedAt->modify('+1 day 2 hours'));
        $foundAt = $reportedAt->modify('+1 day 4 hours');
        $this->confirmItems($cast, $key, $cast->mia, $foundAt);
        $this->draftPicture(
            $cast,
            $key,
            $cast->mia,
            new ShowcasePicture('orders-placed-new-order-missing.png', 'Orders, Placed', [new PlacedPin(14.0, 39.0, self::ORDERS_FINDING)], 'I placed order 1007 and chose Placed: it is not listed.'),
            RoundCommands::VERDICT_NOT_RIGHT_YET,
            $foundAt,
        );
        $this->move($cast, $cast->mia, $key, 'needs_changes', $foundAt, DraftChoice::Share);
    }

    /**
     * Mia reports the ticket for Omar, with Round 0 and the checklist round made from its pins.
     *
     * @return string the ticket's key
     */
    private function reportTicket(ShowcaseCast $cast, string $title, string $description, string $priority, ShowcasePicture $picture, \DateTimeImmutable $at): string
    {
        $key = $this->ticketCommands->create($cast->organizationId, $cast->mia, $cast->projectId, $title, $description, 'bug', $priority, $cast->omar, $at);
        $this->entityManager->flush();
        $this->roundCommands->createReport(
            $this->ticketIdOf($key),
            [new ScreenshotWithPins($this->pictureFileSeeds->addFromPng($cast->organizationId, $cast->mia, self::picturePath($picture->file), $at), $picture->title, $picture->summary, $picture->pins)],
            $cast->mia,
            $at,
        );
        $this->entityManager->flush();

        return $key;
    }

    /**
     * Omar starts work, marks the report's checklist items fixed and shares his draft with the fix as he sends the
     * ticket to code review, Lena's turn.
     */
    private function fixAndSendToReview(ShowcaseCast $cast, string $key, ShowcasePicture $fixed, \DateTimeImmutable $reportedAt): void
    {
        $this->move($cast, $cast->omar, $key, 'start_work', $reportedAt->modify('+1 hour'));
        $fixedAt = $reportedAt->modify('+5 hours');
        $this->draftPicture($cast, $key, $cast->omar, $fixed, RoundCommands::VERDICT_LOOKS_RIGHT, $fixedAt);
        foreach ($this->checklistItemIds($key) as $itemId) {
            $this->checklistCommands->markFixed($itemId, $cast->omar, $fixedAt);
        }
        $this->entityManager->flush();
        $this->move($cast, $cast->omar, $key, 'send_to_code_review', $fixedAt, DraftChoice::Share);
    }

    /**
     * A draft of $owner with one picture, its pins and the owner's verdict on it, left for a move to share.
     */
    private function draftPicture(ShowcaseCast $cast, string $key, UserId $owner, ShowcasePicture $picture, string $verdict, \DateTimeImmutable $at): void
    {
        $roundId = $this->roundCommands->startDraft($this->ticketIdOf($key), $owner, $at);
        $screenshotId = $this->roundCommands->addDraftScreenshot($roundId, $owner, $this->pictureFileSeeds->addFromPng($cast->organizationId, $owner, self::picturePath($picture->file), $at), $at);
        $this->roundCommands->editDraftScreenshot($roundId, $owner, $screenshotId, $picture->title, $picture->summary, $at);
        $this->roundCommands->setDraftPins($roundId, $owner, $screenshotId, $picture->pins, $at);
        $this->roundCommands->setDraftVerdict($roundId, $owner, $screenshotId, $verdict, $at);
        $this->entityManager->flush();
    }

    private function confirmItems(ShowcaseCast $cast, string $key, UserId $tester, \DateTimeImmutable $at): void
    {
        foreach ($this->checklistItemIds($key) as $itemId) {
            $this->checklistCommands->markOk($itemId, $tester, $at);
        }
        $this->entityManager->flush();
    }

    private function move(ShowcaseCast $cast, UserId $actor, string $key, string $verb, \DateTimeImmutable $at, DraftChoice $draftChoice = DraftChoice::Keep): void
    {
        $page = $this->ticketQueries->pageOf($key, $this->ticketVisibilities->of($cast->organizationId, $actor))
            ?? throw new DemoSeedRefused(\sprintf('The showcase ticket %s was not found after it was made.', $key));
        $this->ticketCommands->move($cast->organizationId, $actor, $key, $verb, $page->version, $at, $draftChoice);
        $this->entityManager->flush();
        // The next move reads the ticket's version afresh; a managed Ticket would keep the one it was loaded with.
        $this->entityManager->clear();
    }

    /**
     * @return list<string> the ids of the ticket's checklist items, in the checklist's order
     */
    private function checklistItemIds(string $key): array
    {
        $checklist = $this->checklistQueries->forTicket($this->ticketIdOf($key))
            ?? throw new DemoSeedRefused(\sprintf('The showcase ticket %s has no checklist round.', $key));

        return array_map(static fn (CheckItemView $item): string => $item->itemId, $checklist->items);
    }

    private function ticketIdOf(string $key): TicketId
    {
        return $this->ticketKeyQueries->idOf($key) ?? throw new DemoSeedRefused(\sprintf('The showcase ticket %s was not found after it was made.', $key));
    }

    private function personByEmail(string $email, string $name): UserId
    {
        return $this->userQueries->idByEmail($email) ?? throw new DemoSeedRefused(\sprintf('The demo person %s does not exist yet.', $name));
    }

    private static function picturePath(string $file): string
    {
        return __DIR__.'/showcase/'.$file;
    }

    /**
     * @template T
     *
     * @param \Closure(): T $work
     *
     * @return T
     */
    private function inTransactionOf(OrganizationId $organizationId, \Closure $work): mixed
    {
        $tenantBefore = $this->tenantContext->get();
        // The switch guard refuses while the entity manager holds an entity such as the logged-in user.
        $this->entityManager->clear();
        try {
            $this->tenantContext->set($organizationId);

            return $this->entityManager->wrapInTransaction(static fn (): mixed => $work());
        } finally {
            // Entities loaded under $organizationId must not outlive its tenant.
            $this->entityManager->clear();
            if (null === $tenantBefore) {
                $this->tenantContext->clear();
            } else {
                $this->tenantContext->set($tenantBefore);
            }
        }
    }
}
