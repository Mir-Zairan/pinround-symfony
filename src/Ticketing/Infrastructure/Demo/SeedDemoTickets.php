<?php

declare(strict_types=1);

namespace App\Ticketing\Infrastructure\Demo;

use App\Identity\Api\UserQueries;
use App\Insight\Api\DemoTurnWaits;
use App\Insight\Api\TurnProjector;
use App\Organization\Api\DemoOrganizations;
use App\Organization\Api\TeamQueries;
use App\Organization\Api\TeamSummary;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Application\TenantSwitchRefused;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TeamId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\SharedKernel\Infrastructure\Console\DemoSeedRefused;
use App\SharedKernel\Infrastructure\Console\DemoSeedStep;
use App\Ticketing\Api\TicketCommands;
use App\Ticketing\Domain\Priority;
use App\Ticketing\Domain\ProjectKey;
use App\Ticketing\Domain\Projects;
use App\Ticketing\Domain\TicketType;
use App\Ticketing\Domain\Visibility;
use App\Workflow\Api\StepQueries;
use App\Workflow\Api\StepView;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * 41 demo tickets in SHOP: five in each core step of the Shop team's workflow, and a sixth in Backlog whose ask is past
 * the ticket page's 700-character cut (the ticket page's e2e spec opens it by its title). It runs after SeedDemoProjects.
 * A ticket whose title SHOP already holds stays as it is.
 *
 * A ticket is made through TicketCommands::create, which starts it in Backlog or To do, and then put in its step with one
 * UPDATE rather than TicketCommands::move: the seed places it, skipping the transition policy and the moved history row on
 * purpose, and sets directly the visibility, the reviewer in Code review, the tester in Testing and the label On hold
 * waits on, since no move stores them for a row written without one. Its turn row is refreshed after that.
 *
 * The tickets get different ages, so waits, Whose turn's meters and "Longest waits first" mean something: ticket n last
 * moved WAIT_HOURS[n * 7 mod 16] hours before the clock's now (hours to five weeks, the same on every run) and was made
 * one to five days before that. The age is set on created_at, updated_at, closed_at and the turn's waiting_since; the
 * ticket's "made" history row keeps the seed's own time, as tkt_ticket_event has partitions only from the month the
 * migrations ran.
 */
#[AsTaggedItem(priority: 30)]
final readonly class SeedDemoTickets implements DemoSeedStep
{
    private const string ORGANIZATION = 'acme-foods';
    private const string TEAM = 'Shop';
    private const string KEY = 'SHOP';
    private const string OMAR_EMAIL = 'omar@acme-foods.example';
    private const string MIA_EMAIL = 'mia@acme-foods.example';
    /** The core steps in workflow order; ticket n goes to step n modulo their count. */
    private const array CORE_STEPS = ['backlog', 'todo', 'progress', 'review', 'test', 'input', 'hold', 'done'];
    public const string LONG_ASK_TITLE = 'Pay button does nothing on a long basket';

    /**
     * @return list<string> every sample ticket's title, the long ask's included
     */
    public static function titles(): array
    {
        return [...array_column(self::TICKETS, 0), self::LONG_ASK_TITLE];
    }
    /** How long ago a ticket last moved, in hours: from two hours to about five weeks. */
    private const array WAIT_HOURS = [2, 7, 19, 30, 50, 74, 100, 140, 190, 260, 340, 430, 530, 640, 760, 860];
    /** Each sample ticket's title and one-line ask, in the shop's words; LONG_ASK_TITLE comes last. */
    private const array TICKETS = [
        ['Search box loses focus after typing', 'Type two letters in Search on Products: the box loses focus, so the third letter goes nowhere.'],
        ['Price rounds wrong in the cart', 'Three Coffee beans at €8.50 show €25.49 in the cart instead of €25.50.'],
        ['Add a gift note to the order', 'Let shoppers add a short gift note at Checkout and print it on the packing slip.'],
        ['Show delivery date on the product page', 'Show "Arrives Thursday" under the price, from the next free delivery slot.'],
        ['Checkout button stays disabled after a coupon', 'Apply WELCOME10, then remove it: Go to checkout stays grey until the page is reloaded.'],
        ['Let shoppers save a cart for later', 'A "Save for later" link on each cart line moves it to a list under the cart.'],
        ['Product photos load slowly on mobile', 'On a phone the product photos take about 4 seconds on 4G; serve smaller pictures.'],
        ['Out-of-stock items still show Add to cart', 'Tomatoes 1 kg is out of stock but still has an Add to cart button that does nothing.'],
        ['Order email says the wrong shop name', 'The order email is signed "Test shop" instead of Acme Foods.'],
        ['Filter by dietary label on the category page', 'Add Vegan, Gluten-free and Organic filters above the product list.'],
        ['Quantity field accepts negative numbers', 'Typing -2 in a cart quantity takes €3.70 off the total.'],
        ['Remember the chosen delivery slot', 'Going back from Pay to Checkout clears the delivery slot the shopper picked.'],
        ['Wishlist heart icon has no label for screen readers', 'A screen reader says only "button" on the heart icon; it should say "Add to wishlist".'],
        ['Receipt PDF cuts off long product names', '"Apples, pack of 6, organic" ends at "Apples, pack o" on the receipt PDF.'],
        ['Add a reorder button to past orders', 'A Reorder button on each past order puts the same items back in the cart.'],
        ['Cart count is wrong after removing an item', 'Remove one Oat milk from a cart of two: the header still says Cart (2).'],
        ['Sort products by price, low to high', 'Add "Price, low to high" to the sort menu on Products.'],
        ['Postcode check rejects valid codes with a space', '"1012 AB" is refused at Checkout; "1012AB" is taken.'],
        ['Promo banner overlaps the menu on small screens', 'Under 400 px wide the promo banner covers the Cart and Orders links.'],
        ['Show unit price next to the pack price', 'Show €0.49 per item under "Apples, pack of 6 · €2.95".'],
        ['Login page keeps the old error after a retry', 'After a wrong password, a right one still shows "Wrong password" for a moment.'],
        ['Add organic and vegan badges to product cards', 'Small badges on the product cards, from the labels the products already have.'],
        ['Delivery fee is missing from the order summary', 'The order summary on Pay shows the subtotal and total but not the €3.95 delivery fee.'],
        ['Product page title is not unique', 'Every product page has the browser title "Acme Foods shop"; add the product name.'],
        ['Cart drawer does not close with the Escape key', 'The cart drawer closes only with its X button; Escape should close it too.'],
        ['Add a store pickup option at checkout', 'Let shoppers pick "Collect in store" instead of delivery, with no delivery fee.'],
        ['Search ignores accents in product names', 'Searching "creme" does not find "Crème fraîche".'],
        ['Tidy the footer links on the help pages', 'The help pages repeat Contact twice in the footer and miss Returns.'],
        ['Reviews show in the wrong order', 'Product reviews list the oldest first; show the newest first.'],
        ['Free-shipping bar ignores discounts', 'The bar says "€5 to free shipping" from the total before the discount code.'],
        ['Allow guest checkout with an email only', 'Let shoppers pay without an account, with only their email for the receipt.'],
        ['Stock level shows zero for pre-order items', 'Pre-order products show "0 in stock" instead of "Pre-order: ships in May".'],
        ['Date picker starts on the wrong weekday', 'The delivery date picker starts weeks on Sunday; the shop uses Monday.'],
        ['Newsletter sign-up fails on long addresses', 'An email address over 40 characters gets "Something went wrong".'],
        ['Add allergens list to the product page', 'List the allergens under the ingredients, in bold, as the label shows them.'],
        ['Currency symbol appears twice in the cart', 'The cart total reads "€€12.40".'],
        ['Back button leaves the checkout in a broken state', 'Browser Back from Pay shows an empty Checkout page with no cart lines.'],
        ['Support chat button hides the Pay now button', 'On a phone the round chat button sits on top of Pay now.'],
        ['Show how many items are left when stock is low', 'Show "Only 3 left" on products with fewer than 5 in stock.'],
        ['Refund note is missing from the order email', 'The email for a refunded order does not say the amount or when it arrives.'],
    ];

    /** Whole words, so the page's cut at 700 characters ends at a word and the rest stays behind "Show the full ask". */
    private static function longAsk(): string
    {
        return str_repeat('Pay now does nothing once the basket holds many items. ', 14).'The checkout total is wrong.';
    }

    public function __construct(
        private DemoOrganizations $demoOrganizations,
        private TeamQueries $teamQueries,
        private UserQueries $userQueries,
        private StepQueries $stepQueries,
        private Projects $projects,
        private TicketCommands $ticketCommands,
        private TurnProjector $turnProjector,
        private DemoTurnWaits $demoTurnWaits,
        private TenantContext $tenantContext,
        private EntityManagerInterface $entityManager,
        private Connection $connection,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws TenantSwitchRefused when a transaction is open, from DemoOrganizations, before anything is read here
     */
    public function seed(): string
    {
        $organizationId = $this->demoOrganizations->idBySlug(self::ORGANIZATION)
            ?? throw new DemoSeedRefused(\sprintf('The demo organization %s does not exist yet, so the tickets cannot be made. Seed the organizations first.', self::ORGANIZATION));

        $created = $this->inTransactionOf($organizationId, fn (): int => $this->seedTickets($organizationId));

        return \sprintf('Tickets: created %d, kept %d.', $created, \count(self::TICKETS) + 1 - $created);
    }

    private function seedTickets(OrganizationId $organizationId): int
    {
        $team = $this->findTeamByName($organizationId, self::TEAM)
            ?? throw new DemoSeedRefused(\sprintf('The demo organization %s has no team %s yet, so the tickets cannot be made. Seed the organizations first.', self::ORGANIZATION, self::TEAM));
        $project = $this->projects->byKey(new ProjectKey(self::KEY))
            ?? throw new DemoSeedRefused(\sprintf('The demo project %s does not exist yet, so the tickets cannot be made. Seed the projects first.', self::KEY));
        $projectId = $project->id()->toString();
        $lena = $this->teamQueries->leadOf($team) ?? throw new DemoSeedRefused('The demo team Shop has no lead.');
        $omar = $this->userQueries->idByEmail(self::OMAR_EMAIL) ?? throw new DemoSeedRefused('The demo person Omar does not exist yet.');
        $mia = $this->userQueries->idByEmail(self::MIA_EMAIL) ?? throw new DemoSeedRefused('The demo person Mia does not exist yet.');
        $stepsByCoreCode = [];
        foreach ($this->stepQueries->stepsFor($team) as $step) {
            if (null !== $step->coreCode()) {
                $stepsByCoreCode[$step->coreCode()] = $step;
            }
        }
        $existingTitles = [];
        foreach ($this->connection->fetchFirstColumn('SELECT title FROM tkt_ticket WHERE project_id = ?', [$projectId]) as $existingTitle) {
            \assert(\is_string($existingTitle));
            $existingTitles[$existingTitle] = true;
        }

        $created = 0;
        foreach ([...self::TICKETS, [self::LONG_ASK_TITLE, self::longAsk()]] as $index => [$title, $ask]) {
            if (isset($existingTitles[$title])) {
                continue;
            }
            $coreCode = self::CORE_STEPS[$index % \count(self::CORE_STEPS)];
            $targetStep = $stepsByCoreCode[$coreCode] ?? throw new DemoSeedRefused(\sprintf('The Shop team has no %s step.', $coreCode));
            $assignee = 'backlog' === $coreCode ? null : (0 === $index % 2 ? $omar : $lena);
            $this->seedTicket($organizationId, $projectId, $lena, $title, $ask, $index, $assignee, $targetStep, $stepsByCoreCode['progress'] ?? throw new DemoSeedRefused('The Shop team has no progress step.'), $mia, $lena);
            ++$created;
        }

        return $created;
    }

    private function seedTicket(
        OrganizationId $organizationId,
        string $projectId,
        UserId $reporter,
        string $title,
        string $description,
        int $index,
        ?UserId $assignee,
        StepView $targetStep,
        StepView $stepBeforeWaiting,
        UserId $tester,
        UserId $reviewer,
    ): void {
        $now = $this->clock->now();
        $types = TicketType::cases();
        $priorities = Priority::cases();
        $key = $this->ticketCommands->create(
            $organizationId,
            $reporter,
            $projectId,
            $title,
            $description,
            $types[$index % \count($types)]->value,
            $priorities[($index + intdiv($index, \count(self::CORE_STEPS))) % \count($priorities)]->value,
            $assignee,
            $now,
        );
        // The flush writes the row so the raw UPDATE below can find it; clear() drops the now-stale Ticket so later reads load it fresh.
        $this->entityManager->flush();
        $this->entityManager->clear();

        $coreCode = $targetStep->coreCode();
        $ticketId = $this->connection->fetchOne('SELECT id FROM tkt_ticket WHERE organization_id = ? AND key = ?', [$organizationId->toString(), $key]);
        if (!\is_string($ticketId)) {
            throw new DemoSeedRefused(\sprintf('The demo ticket %s was not found after it was made.', $key));
        }
        $isWaiting = \in_array($coreCode, ['input', 'hold'], true);
        $movedAt = self::movedAtOf($index, $now);
        $this->connection->executeStatement(
            'UPDATE tkt_ticket SET step_id = ?, previous_step_id = ?, visibility = ?, reviewer_id = ?, tester_id = ?, waiting_on_label = ?, closed_at = ?,
             created_at = ?, updated_at = ?
             WHERE organization_id = ? AND id = ?',
            [
                $targetStep->id(),
                $isWaiting ? $stepBeforeWaiting->id() : null,
                3 === $index % 5 ? Visibility::Team->value : Visibility::All->value,
                'review' === $coreCode ? $reviewer->toString() : null,
                'test' === $coreCode ? $tester->toString() : null,
                $isWaiting ? ('input' === $coreCode ? 'Customer' : 'Supplier delivery') : null,
                'done' === $coreCode ? $movedAt->format(\DateTimeInterface::ATOM) : null,
                self::createdAtOf($index, $movedAt)->format(\DateTimeInterface::ATOM),
                $movedAt->format(\DateTimeInterface::ATOM),
                $organizationId->toString(),
                $ticketId,
            ],
        );
        $this->turnProjector->refresh(new TicketId($ticketId));
        $this->demoTurnWaits->backdate(new TicketId($ticketId), $movedAt);
    }

    private static function movedAtOf(int $index, \DateTimeImmutable $now): \DateTimeImmutable
    {
        return $now->modify(\sprintf('-%d hours', self::WAIT_HOURS[($index * 7) % \count(self::WAIT_HOURS)]));
    }

    private static function createdAtOf(int $index, \DateTimeImmutable $movedAt): \DateTimeImmutable
    {
        return $movedAt->modify(\sprintf('-%d days', 1 + $index % 5));
    }

    /**
     * Team names are unique without regard to letter case.
     */
    private function findTeamByName(OrganizationId $organizationId, string $name): ?TeamId
    {
        $team = array_find(
            $this->teamQueries->allSummariesOf($organizationId),
            static fn (TeamSummary $summary): bool => 0 === strcasecmp($summary->name(), $name),
        );

        return $team?->id();
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
            null === $tenantBefore ? $this->tenantContext->clear() : $this->tenantContext->set($tenantBefore);
        }
    }
}
