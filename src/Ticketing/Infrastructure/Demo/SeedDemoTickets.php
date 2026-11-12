<?php

declare(strict_types=1);

namespace App\Ticketing\Infrastructure\Demo;

use App\Identity\Api\UserQueries;
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
 * No command moves a ticket yet (P03.T7), so a ticket is made through TicketCommands::create, which starts it in
 * Backlog or To do, and then put in its step with one UPDATE; its turn row is refreshed after that.
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
    private const string SHORT_ASK = 'Seeded for the demo.';
    private const array TITLES = [
        'Search box loses focus after typing',
        'Price rounds wrong in basket',
        'Add a gift note to the order',
        'Show delivery date on the product page',
        'Checkout button stays disabled after a coupon',
        'Let shoppers save a basket for later',
        'Product photos load slowly on mobile',
        'Out-of-stock items still show Add to basket',
        'Order email says the wrong shop name',
        'Filter by dietary label on the category page',
        'Quantity field accepts negative numbers',
        'Remember the chosen delivery slot',
        'Wishlist heart icon has no label for screen readers',
        'Receipt PDF cuts off long product names',
        'Add a reorder button to past orders',
        'Basket count is wrong after removing an item',
        'Sort products by price, low to high',
        'Postcode check rejects valid codes with a space',
        'Promo banner overlaps the menu on small screens',
        'Show unit price next to the pack price',
        'Login page keeps the old error after a retry',
        'Add organic and vegan badges to product cards',
        'Delivery fee is missing from the order summary',
        'Product page title is not unique',
        'Basket drawer does not close with the Escape key',
        'Add a store pickup option at checkout',
        'Search ignores accents in product names',
        'Tidy the footer links on the help pages',
        'Reviews show in the wrong order',
        'Free-shipping bar ignores discounts',
        'Allow guest checkout with an email only',
        'Stock level shows zero for pre-order items',
        'Date picker starts on the wrong weekday',
        'Newsletter sign-up fails on long addresses',
        'Add allergens list to the product page',
        'Currency symbol appears twice in the basket',
        'Back button leaves the checkout in a broken state',
        'Support chat button hides the Pay now button',
        'Show how many items are left when stock is low',
        'Refund note is missing from the order email',
        self::LONG_ASK_TITLE,
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

        return \sprintf('Tickets: created %d, kept %d.', $created, \count(self::TITLES) - $created);
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
        foreach (self::TITLES as $index => $title) {
            if (isset($existingTitles[$title])) {
                continue;
            }
            $coreCode = self::CORE_STEPS[$index % \count(self::CORE_STEPS)];
            $targetStep = $stepsByCoreCode[$coreCode] ?? throw new DemoSeedRefused(\sprintf('The Shop team has no %s step.', $coreCode));
            $assignee = 'backlog' === $coreCode ? null : (0 === $index % 2 ? $omar : $lena);
            $this->seedTicket($organizationId, $projectId, $lena, $title, self::LONG_ASK_TITLE === $title ? self::longAsk() : self::SHORT_ASK, $index, $assignee, $targetStep, $stepsByCoreCode['progress'] ?? throw new DemoSeedRefused('The Shop team has no progress step.'), $mia, $lena);
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
        $this->connection->executeStatement(
            'UPDATE tkt_ticket SET step_id = ?, previous_step_id = ?, visibility = ?, reviewer_id = ?, tester_id = ?, waiting_on_label = ?, closed_at = ?
             WHERE organization_id = ? AND id = ?',
            [
                $targetStep->id(),
                $isWaiting ? $stepBeforeWaiting->id() : null,
                3 === $index % 5 ? Visibility::Team->value : Visibility::All->value,
                'review' === $coreCode ? $reviewer->toString() : null,
                'test' === $coreCode ? $tester->toString() : null,
                $isWaiting ? ('input' === $coreCode ? 'Answer from the shopper' : 'Supplier delivery') : null,
                'done' === $coreCode ? $now->format(\DateTimeInterface::ATOM) : null,
                $organizationId->toString(),
                $ticketId,
            ],
        );
        $this->turnProjector->refresh(new TicketId($ticketId));
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
