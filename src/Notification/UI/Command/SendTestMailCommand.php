<?php

declare(strict_types=1);

namespace App\Notification\UI\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

#[AsCommand(name: 'app:mail:send-test', description: 'Send a short test mail to check the mail setup')]
#[When('dev')]
#[When('test')]
final class SendTestMailCommand extends Command
{
    public const string RECIPIENT = 'omar@acme-foods.example';

    public function __construct(private readonly MailerInterface $mailer)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->mailer->send(
            (new Email())
                ->from('pinround@acme-foods.example')
                ->to(self::RECIPIENT)
                ->subject('Pinround test mail')
                ->text('If you can read this, mail from the app reaches your inbox.'),
        );

        $output->writeln('Test mail sent to '.self::RECIPIENT.'.');

        return Command::SUCCESS;
    }
}
