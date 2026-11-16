<?php

declare(strict_types=1);

namespace App\Review\Infrastructure\Console;

use App\Media\Api\ReadyPictureSeeds;
use App\Organization\Api\DemoOrganizations;
use App\Review\Api\RoundCommands;
use App\Review\Api\RoundRefused;
use App\SharedKernel\Application\TenantContext;
use App\SharedKernel\Application\Transactions;
use App\Ticketing\Api\TicketKeyQueries;
use App\Ticketing\Api\TicketNotFound;
use App\Ticketing\Api\TicketTurnQueries;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\When;

/**
 * For browser tests, which have no processing worker: starts a draft round for the person the ticket is given to and
 * puts one ready picture in it, through the same RoundCommands the draft editor calls.
 */
// Never registered in prod, so no console call there can fake a processed picture.
#[AsCommand(name: 'app:e2e:seed-draft-with-picture', description: 'Start the assignee\'s draft round on a ticket with one ready picture (dev and test only)')]
#[When('dev')]
#[When('test')]
final class SeedDraftWithPictureCommand extends Command
{
    public function __construct(
        private readonly DemoOrganizations $demoOrganizations,
        private readonly TenantContext $tenantContext,
        private readonly Transactions $transactions,
        private readonly TicketKeyQueries $ticketKeyQueries,
        private readonly TicketTurnQueries $ticketTurnQueries,
        private readonly RoundCommands $roundCommands,
        private readonly ReadyPictureSeeds $readyPictureSeeds,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('organization', InputArgument::REQUIRED, 'The slug of the organization, for example acme-foods')
            ->addArgument('ticket', InputArgument::REQUIRED, 'The ticket key, for example SHOP-12; it must be given to someone');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $slug = $input->getArgument('organization');
        $key = $input->getArgument('ticket');
        \assert(\is_string($slug) && \is_string($key));

        $organizationId = $this->demoOrganizations->idBySlug($slug);
        if (null === $organizationId) {
            $output->writeln('<error>That organization does not exist.</error>');

            return Command::FAILURE;
        }

        // Looking the organization up loaded entities, which the tenant switch guard refuses to carry into another tenant.
        $this->entityManager->clear();
        $this->tenantContext->set($organizationId);
        try {
            $roundId = $this->transactions->run(function () use ($organizationId, $key): string {
                $ticketId = $this->ticketKeyQueries->idOf($key) ?? throw new TicketNotFound('That ticket does not exist.');
                $facts = $this->ticketTurnQueries->factsOf($ticketId) ?? throw new TicketNotFound('That ticket does not exist.');
                $owner = $facts->assigneeId ?? throw new RoundRefused('That ticket is not given to anyone, so there is no one to own the draft.');
                $now = $this->clock->now();
                $roundId = $this->roundCommands->startDraft($ticketId, $owner, $now);
                $this->roundCommands->addDraftScreenshot($roundId, $owner, $this->readyPictureSeeds->add($organizationId, $owner, $now), $now);

                return $roundId;
            });
        } catch (TicketNotFound|RoundRefused $refused) {
            $output->writeln(\sprintf('<error>%s</error>', $refused->getMessage()));

            return Command::FAILURE;
        } finally {
            // The rows this run loaded must not outlive its tenant.
            $this->entityManager->clear();
            $this->tenantContext->clear();
        }

        $output->writeln(\sprintf('Draft %s on %s has one ready picture.', $roundId, $key));

        return Command::SUCCESS;
    }
}
