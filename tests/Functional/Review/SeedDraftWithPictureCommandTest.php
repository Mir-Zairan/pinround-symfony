<?php

declare(strict_types=1);

namespace App\Tests\Functional\Review;

use App\Media\Infrastructure\Demo\SeedReadyPicture;
use App\Review\Infrastructure\Console\SeedDraftWithPictureCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\Attribute\When;

final class SeedDraftWithPictureCommandTest extends KernelTestCase
{
    public function testItIsRegisteredInTestAndRefusesAnUnknownOrganizationBeforeWritingAnything(): void
    {
        $kernel = self::bootKernel();
        $tester = new CommandTester(new Application($kernel)->find('app:e2e:seed-draft-with-picture'));

        $exitCode = $tester->execute(['organization' => 'no-such-organization', 'ticket' => 'SHOP-1']);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('That organization does not exist.', $tester->getDisplay());
    }

    /**
     * @return iterable<string, array{class-string}>
     */
    public static function devOnlyClasses(): iterable
    {
        yield 'the command' => [SeedDraftWithPictureCommand::class];
        yield 'the ready picture seed' => [SeedReadyPicture::class];
    }

    /**
     * @param class-string $class
     */
    #[DataProvider('devOnlyClasses')]
    public function testItIsNotRegisteredInProd(string $class): void
    {
        $attributes = new \ReflectionClass($class)->getAttributes(When::class);

        $environments = array_map(static fn (\ReflectionAttribute $attribute): string => $attribute->newInstance()->env, $attributes);
        sort($environments);

        self::assertSame(['dev', 'test'], $environments);
    }
}
