<?php

declare(strict_types=1);

namespace App\Tests\Functional\Notification;

use App\Notification\UI\Command\SendTestMailCommand;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\Attribute\When;

final class SendTestMailCommandTest extends KernelTestCase
{
    public function testItSendsOneMailToTheNeutralAddress(): void
    {
        self::bootKernel();
        $command = (new Application(self::$kernel))->find('app:mail:send-test');

        $tester = new CommandTester($command);
        $exitCode = $tester->execute([]);

        self::assertSame(0, $exitCode);
        self::assertEmailCount(1);
        $email = self::getMailerMessage();
        self::assertEmailAddressContains($email, 'to', SendTestMailCommand::RECIPIENT);
        self::assertEmailTextBodyContains($email, 'mail from the app');
    }

    public function testItIsNotRegisteredInProd(): void
    {
        $attributes = (new \ReflectionClass(SendTestMailCommand::class))->getAttributes(When::class);

        $environments = array_map(static fn (\ReflectionAttribute $attribute): string => $attribute->newInstance()->env, $attributes);
        sort($environments);

        self::assertSame(['dev', 'test'], $environments);
    }
}
