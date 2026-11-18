<?php

declare(strict_types=1);

namespace App\Tests\Integration\Assist\Platform;

use App\Assist\Application\AiPrompt;
use App\Assist\Application\ProviderChoice;
use App\Assist\Domain\AiFeature;
use App\Assist\Infrastructure\Platform\FakeProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The AiProvider port as the test container gives it to the gateway: the fake, never a real provider.
 */
final class FakeProviderWiringTest extends KernelTestCase
{
    #[Test]
    public function theTestContainerGivesTheFakeProviderWhichAnswersWithTheFixedText(): void
    {
        $provider = self::getContainer()->get('test.assist_ai_provider');
        self::assertInstanceOf(FakeProvider::class, $provider);
        self::assertSame($provider, self::getContainer()->get(FakeProvider::class));

        $answer = $provider->complete(new AiPrompt(AiFeature::Summary, 'Summarize.', 'The checkout fails.'), ProviderChoice::fromSettings(null));

        self::assertSame('Fake summary: the checkout button does nothing on the last step.', $answer->text);
        self::assertSame('fake', $answer->provider);
        self::assertCount(1, $provider->calls());
    }

    #[Test]
    public function aKernelResetForgetsTheCalls(): void
    {
        $provider = self::getContainer()->get(FakeProvider::class);
        $provider->complete(new AiPrompt(AiFeature::Explain, 'Explain.', 'Why?'), ProviderChoice::fromSettings(null));

        self::getContainer()->get('services_resetter')->reset();

        self::assertSame([], $provider->calls());
    }
}
