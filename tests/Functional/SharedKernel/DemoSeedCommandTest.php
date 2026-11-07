<?php

declare(strict_types=1);

namespace App\Tests\Functional\SharedKernel;

use App\SharedKernel\Infrastructure\Console\DemoSeedCommand;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Exception\CommandNotFoundException;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\Attribute\When;

final class DemoSeedCommandTest extends KernelTestCase
{
    public function testItSaysThereIsNothingToSeedYet(): void
    {
        $kernel = self::bootKernel();
        $tester = new CommandTester(new Application($kernel)->find('app:demo:seed'));

        $exitCode = $tester->execute([]);

        self::assertSame(0, $exitCode);
        self::assertSame(
            "Nothing to seed yet: demo data arrives with organizations and users (P01).\n",
            $tester->getDisplay(),
        );
    }

    public function testItIsNotRegisteredInProd(): void
    {
        $attributes = new \ReflectionClass(DemoSeedCommand::class)->getAttributes(When::class);

        $environments = array_map(static fn (\ReflectionAttribute $attribute): string => $attribute->newInstance()->env, $attributes);
        sort($environments);

        self::assertSame(['dev', 'test'], $environments);
    }

    public function testAProdKernelHasNoSeedCommand(): void
    {
        $kernel = self::bootKernel(['environment' => 'prod', 'debug' => false]);
        $application = new Application($kernel);

        self::assertSame('prod', $kernel->getEnvironment());
        $this->expectException(CommandNotFoundException::class);
        $application->find('app:demo:seed');
    }
}
