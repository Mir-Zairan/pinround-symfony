<?php

declare(strict_types=1);

namespace App\SharedKernel\Infrastructure\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\When;

// Demo data must never be loaded into prod, so the command is not registered there.
#[AsCommand(name: 'app:demo:seed', description: 'Load demo data (nothing to load until organizations and users exist)')]
#[When('dev')]
#[When('test')]
final class DemoSeedCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('Nothing to seed yet: demo data arrives with organizations and users (P01).');

        return Command::SUCCESS;
    }
}
