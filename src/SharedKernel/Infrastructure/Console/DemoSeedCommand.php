<?php

declare(strict_types=1);

namespace App\SharedKernel\Infrastructure\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\DependencyInjection\Attribute\When;

// Demo data must never be loaded into prod, so the command is not registered there.
#[AsCommand(name: 'app:demo:seed', description: 'Load demo data (needs DEMO_PASSWORD, at least 12 characters)')]
#[When('dev')]
#[When('test')]
final class DemoSeedCommand extends Command
{
    /**
     * @param iterable<DemoSeedStep> $steps in priority order
     */
    public function __construct(#[AutowireIterator('app.demo_seed_step')] private readonly iterable $steps)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            foreach ($this->steps as $step) {
                $output->writeln($step->seed());
            }
        } catch (DemoSeedRefused $refused) {
            $output->writeln(\sprintf('<error>%s</error>', $refused->getMessage()));

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
