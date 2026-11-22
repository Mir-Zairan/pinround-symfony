<?php

declare(strict_types=1);

namespace App\Review\Infrastructure\Console;

use App\Media\Api\ReadyPictureSeeds;
use App\Organization\Api\DemoOrganizations;
use App\Review\Api\ChecklistCommands;
use App\Review\Api\PlacedPin;
use App\Review\Api\RoundCommands;
use App\Review\Api\RoundRefused;
use App\Review\Api\ScreenshotWithPins;
use App\Review\Application\LockedRoundClosing;
use App\Review\Domain\Rounds;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Application\Transactions;
use App\SharedKernel\Domain\OrganizationId;
use App\SharedKernel\Domain\TicketId;
use App\SharedKernel\Domain\UserId;
use App\Ticketing\Api\TicketKeyQueries;
use App\Ticketing\Api\TicketNotFound;
use App\Ticketing\Api\TicketTurnQueries;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\Uid\Uuid;

/**
 * For browser tests of the review round page, which have no processing worker: gives a ticket with no rounds a round 1
 * and a review round 2. Round 1 is the assignee's draft, shared through the same RoundCommands the draft editor calls,
 * with two ready pictures, the first with PIN_NOTES. Round 2 is a review round of the assignee with one screen area per
 * AREA_NAMES, in order: each area's "before" is the round 1 picture at its position and its "after" a ready picture of
 * its own. No command makes a review round yet, so round 2 is written as rvw_round, rvw_screenshot and rvw_area rows.
 * For the full-screen review's layout checks, LONG_NOTE_OPTION gives the first pin a long note and PENDING_AFTER_OPTION
 * leaves one area's "after" picture pending. For the checklist card's checks, CHECKLIST_OPTION first gives the ticket the
 * reporter's Round 0 with one ready picture and REPORT_PIN_NOTES, and the checklist the app makes from it, which has no
 * number (ADR-0048, note of 2026-11-22), so the draft is round 1 and the review round 2 either way. CLOSED_OPTION then
 * closes the shared and review rounds, as a move on by the person whose turn it is would, so the assignee may share
 * another round (ADR-0048).
 */
// Never registered in prod, so no console call there can fake processed pictures or a round.
#[AsCommand(name: 'app:e2e:seed-review-round', description: 'Give a ticket with no rounds a round 1 with pins and a review round 2 with two screen areas (dev and test only)')]
#[When('dev')]
#[When('test')]
final class SeedReviewRoundCommand extends Command
{
    /** The screen areas of round 2, by position. */
    public const array AREA_NAMES = ['Cart summary', 'Checkout button'];

    /** The pins of round 1's first picture, by number: the earlier pins of round 2's first area. */
    public const array PIN_NOTES = ['The total shows the old price.', 'The badge covers the basket icon.'];

    /** The pins of Round 0's picture with CHECKLIST_OPTION, by number: the checklist's items. */
    public const array REPORT_PIN_NOTES = ['The coupon field takes no input.', 'The delivery date shows yesterday.'];

    public const string PENDING_AFTER_OPTION = 'pending-after';
    public const string LONG_NOTE_OPTION = 'long-note';
    public const string CHECKLIST_OPTION = 'checklist';
    public const string CLOSED_OPTION = 'closed';

    /** The first pin's note with LONG_NOTE_OPTION: long enough to wrap over many lines on a narrow screen. */
    public const string LONG_PIN_NOTE = 'The total shows the old price after a coupon is removed. The line under it still lists the discount, '
        .'so the sum at the bottom does not match the items above it. On a narrow screen the badge also covers the basket icon, '
        .'and the delivery fee moves below the button, where it is easy to miss before paying. Check the total, the discount line, '
        .'the badge and the delivery fee together, as one change touches all four.';

    public function __construct(
        private readonly DemoOrganizations $demoOrganizations,
        private readonly TenantContext $tenantContext,
        private readonly Transactions $transactions,
        private readonly TicketKeyQueries $ticketKeyQueries,
        private readonly TicketTurnQueries $ticketTurnQueries,
        private readonly RoundCommands $roundCommands,
        private readonly ChecklistCommands $checklistCommands,
        private readonly Rounds $rounds,
        private readonly LockedRoundClosing $lockedRoundClosing,
        private readonly ReadyPictureSeeds $readyPictureSeeds,
        private readonly Connection $connection,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('organization', InputArgument::REQUIRED, 'The slug of the organization, for example acme-foods')
            ->addArgument('ticket', InputArgument::REQUIRED, 'The ticket key, for example SHOP-12; it must be given to someone and have no rounds')
            ->addOption(self::PENDING_AFTER_OPTION, null, InputOption::VALUE_REQUIRED, 'The position of the screen area whose "after" picture stays pending (still processing), from 0')
            ->addOption(self::LONG_NOTE_OPTION, null, InputOption::VALUE_NONE, 'Give the first pin LONG_PIN_NOTE in place of its short note')
            ->addOption(self::CHECKLIST_OPTION, null, InputOption::VALUE_NONE, 'First give the ticket a Round 0 report with two pins and its checklist round, both items open')
            ->addOption(self::CLOSED_OPTION, null, InputOption::VALUE_NONE, 'Then close the shared round and the review round, so the assignee may share another');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $slug = $input->getArgument('organization');
        $key = $input->getArgument('ticket');
        \assert(\is_string($slug) && \is_string($key));
        $pendingAfter = $input->getOption(self::PENDING_AFTER_OPTION);
        $pendingAfterPosition = null;
        if (null !== $pendingAfter) {
            if (!\in_array($pendingAfter, array_map(strval(...), array_keys(self::AREA_NAMES)), true)) {
                $output->writeln(\sprintf('<error>There is no screen area at that position; give 0 to %d.</error>', \count(self::AREA_NAMES) - 1));

                return Command::FAILURE;
            }
            $pendingAfterPosition = (int) $pendingAfter;
        }
        $pinNotes = true === $input->getOption(self::LONG_NOTE_OPTION) ? [self::LONG_PIN_NOTE, ...\array_slice(self::PIN_NOTES, 1)] : self::PIN_NOTES;
        $withChecklist = true === $input->getOption(self::CHECKLIST_OPTION);
        $isClosed = true === $input->getOption(self::CLOSED_OPTION);

        $organizationId = $this->demoOrganizations->idBySlug($slug);
        if (null === $organizationId) {
            $output->writeln('<error>That organization does not exist.</error>');

            return Command::FAILURE;
        }

        // Looking the organization up loaded entities, which the tenant switch guard refuses to carry into another tenant.
        $this->entityManager->clear();
        $this->tenantContext->set($organizationId);
        try {
            $reviewNumber = $this->transactions->run(function () use ($organizationId, $key, $pinNotes, $pendingAfterPosition, $withChecklist, $isClosed): int {
                $ticketId = $this->ticketKeyQueries->idOf($key) ?? throw new TicketNotFound('That ticket does not exist.');
                $facts = $this->ticketTurnQueries->factsOf($ticketId) ?? throw new TicketNotFound('That ticket does not exist.');
                $owner = $facts->assigneeId ?? throw new RoundRefused('That ticket is not given to anyone, so there is no one to own the rounds.');
                if ($this->hasRounds($organizationId, $ticketId)) {
                    throw new RoundRefused('That ticket has rounds already; seed the review round on a new ticket.');
                }
                $now = $this->clock->now();
                if ($withChecklist) {
                    $this->addReportWithChecklist($organizationId, $ticketId, $facts->reporterId, $now);
                }
                [$earlierNumber, $earlierScreenshotIds] = $this->shareRoundWithPins($organizationId, $ticketId, $owner, $pinNotes, $now);
                $reviewNumber = $earlierNumber + 1;
                $this->addReviewRound($organizationId, $ticketId, $owner, $reviewNumber, $earlierScreenshotIds, $pendingAfterPosition, $now);
                if ($isClosed) {
                    foreach ($this->rounds->openSharedRoundsOf($ticketId) as $openRound) {
                        $this->lockedRoundClosing->closeLockedRound($openRound, $now);
                    }
                }

                return $reviewNumber;
            });
        } catch (TicketNotFound|RoundRefused $refused) {
            $output->writeln(\sprintf('<error>%s</error>', $refused->getMessage()));

            return Command::FAILURE;
        } finally {
            // The rows this run loaded must not outlive its tenant.
            $this->entityManager->clear();
            $this->tenantContext->clear();
        }

        $output->writeln(\sprintf('Round %d on %s has %d screen areas with ready pictures.', $reviewNumber, $key, \count(self::AREA_NAMES)));
        if (null !== $pendingAfterPosition) {
            $output->writeln(\sprintf('The "after" picture of %s is still processing.', self::AREA_NAMES[$pendingAfterPosition]));
        }
        if ($withChecklist) {
            $output->writeln(\sprintf('The checklist from the report on %s has %d open items.', $key, \count(self::REPORT_PIN_NOTES)));
        }
        if ($isClosed) {
            $output->writeln(\sprintf('Rounds %d and %d on %s are closed.', $reviewNumber - 1, $reviewNumber, $key));
        }

        return Command::SUCCESS;
    }

    private function addReportWithChecklist(OrganizationId $organizationId, TicketId $ticketId, UserId $reporter, \DateTimeImmutable $now): void
    {
        $pins = array_map(
            static fn (int $index, string $note): PlacedPin => new PlacedPin(25.0 + 40.0 * $index, 30.0 + 20.0 * $index, $note),
            array_keys(self::REPORT_PIN_NOTES),
            self::REPORT_PIN_NOTES,
        );
        $this->roundCommands->createReport($ticketId, [new ScreenshotWithPins($this->readyPictureSeeds->add($organizationId, $reporter, $now), 'Checkout page', null, $pins)], $reporter, $now);
        // createReport makes the checklist itself for a ticket in To do; this makes it in any other step, as Start work does.
        $this->checklistCommands->createFromReport($ticketId, $now);
        $hasChecklist = $this->connection->fetchOne(
            "SELECT EXISTS (SELECT 1 FROM rvw_round WHERE organization_id = ? AND ticket_id = ? AND kind = 'checklist')",
            [$organizationId->toString(), $ticketId->toString()],
        );
        if (true !== $hasChecklist) {
            throw new \LogicException('A ticket with a new report and no other rounds gets a checklist.');
        }
    }

    private function hasRounds(OrganizationId $organizationId, TicketId $ticketId): bool
    {
        return true === $this->connection->fetchOne(
            'SELECT EXISTS (SELECT 1 FROM rvw_round WHERE organization_id = ? AND ticket_id = ?)',
            [$organizationId->toString(), $ticketId->toString()],
        );
    }

    /**
     * @param list<string> $pinNotes the pins of the first picture, by number
     *
     * @return array{int, list<string>} the shared round's number and its screenshot ids, one per area, by position
     */
    private function shareRoundWithPins(OrganizationId $organizationId, TicketId $ticketId, UserId $owner, array $pinNotes, \DateTimeImmutable $now): array
    {
        $roundId = $this->roundCommands->startDraft($ticketId, $owner, $now);
        $screenshotIds = [];
        foreach (self::AREA_NAMES as $areaName) {
            $screenshotId = $this->roundCommands->addDraftScreenshot($roundId, $owner, $this->readyPictureSeeds->add($organizationId, $owner, $now), $now);
            $this->roundCommands->editDraftScreenshot($roundId, $owner, $screenshotId, $areaName, null, $now);
            $screenshotIds[] = $screenshotId;
        }
        $pins = array_map(
            static fn (int $index, string $note): PlacedPin => new PlacedPin(30.0 + 35.0 * $index, 40.0 + 15.0 * $index, $note),
            array_keys($pinNotes),
            $pinNotes,
        );
        $this->roundCommands->setDraftPins($roundId, $owner, $screenshotIds[0], $pins, $now);

        return [$this->roundCommands->shareDraft($roundId, $owner, $now), $screenshotIds];
    }

    /**
     * @param list<string> $beforeScreenshotIds  one per area, by position
     * @param int|null     $pendingAfterPosition the area whose after picture stays pending, if any
     */
    private function addReviewRound(OrganizationId $organizationId, TicketId $ticketId, UserId $owner, int $number, array $beforeScreenshotIds, ?int $pendingAfterPosition, \DateTimeImmutable $now): void
    {
        $organization = $organizationId->toString();
        $at = $now->format(\DATE_ATOM);
        $roundId = Uuid::v7()->toRfc4122();
        $this->connection->executeStatement(
            "INSERT INTO rvw_round (organization_id, id, ticket_id, number, kind, owner_id, shared_at, state, version, created_at) VALUES (?, ?, ?, ?, 'review', ?, ?, 'open', 1, ?)",
            [$organization, $roundId, $ticketId->toString(), $number, $owner->toString(), $at, $at],
        );
        foreach (self::AREA_NAMES as $position => $areaName) {
            $afterScreenshotId = Uuid::v7()->toRfc4122();
            $afterMediaId = $position === $pendingAfterPosition
                ? $this->readyPictureSeeds->addPending($organizationId, $owner, $now)
                : $this->readyPictureSeeds->add($organizationId, $owner, $now);
            $this->connection->executeStatement(
                'INSERT INTO rvw_screenshot (organization_id, id, round_id, media_id, position, title, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$organization, $afterScreenshotId, $roundId, $afterMediaId, $position, $areaName, $at],
            );
            $this->connection->executeStatement(
                'INSERT INTO rvw_area (organization_id, id, round_id, name, position, before_screenshot_id, after_screenshot_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [$organization, Uuid::v7()->toRfc4122(), $roundId, $areaName, $position, $beforeScreenshotIds[$position], $afterScreenshotId, $at],
            );
        }
    }
}
