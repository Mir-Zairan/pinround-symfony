<?php

declare(strict_types=1);

namespace App\Tests\Unit\Assist\Infrastructure\Platform;

use App\Assist\Application\AiPrompt;
use App\Assist\Application\AiRateLimited;
use App\Assist\Application\AiUnavailable;
use App\Assist\Application\ProviderChoice;
use App\Assist\Domain\AiFeature;
use App\Assist\Infrastructure\Platform\FakeProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FakeProviderTest extends TestCase
{
    #[Test]
    public function eachFeatureGetsItsOwnFixedTextWithTheChosenModelAndProviderFake(): void
    {
        $provider = new FakeProvider();
        $choice = ProviderChoice::fromSettings('ollama', 'llama3.2:3b');

        $summary = $provider->complete(new AiPrompt(AiFeature::Summary, 'Summarize.', 'The checkout fails.'), $choice);
        $explanation = $provider->complete(new AiPrompt(AiFeature::Explain, 'Explain.', 'The checkout fails.'), $choice);
        $intake = $provider->complete(new AiPrompt(AiFeature::Intake, 'Suggest fields.', 'The checkout fails.'), $choice);

        self::assertSame('Fake summary: the checkout button does nothing on the last step.', $summary->text);
        self::assertSame('Fake explanation: people cannot pay, because the last checkout step does not respond.', $explanation->text);
        self::assertSame('{}', $intake->text);
        self::assertSame('fake', $summary->provider);
        self::assertSame('llama3.2:3b', $summary->model);
    }

    #[Test]
    public function theSamePromptGetsTheSameAnswerAndTokensGrowWithTheText(): void
    {
        $provider = new FakeProvider();
        $choice = ProviderChoice::fromSettings(null);
        $shortPrompt = new AiPrompt(AiFeature::Summary, 'Summarize.', 'Short.');

        $first = $provider->complete($shortPrompt, $choice);
        $second = $provider->complete($shortPrompt, $choice);
        $longer = $provider->complete(new AiPrompt(AiFeature::Summary, 'Summarize.', str_repeat('A much longer ticket text. ', 20)), $choice);

        self::assertEquals($first, $second);
        self::assertSame(4, $first->inputTokens);
        self::assertSame(16, $first->outputTokens);
        self::assertGreaterThan($first->inputTokens, $longer->inputTokens);
    }

    #[Test]
    public function armedFailuresAreThrownOnceEachInOrderAndTheCallsAreStillRecorded(): void
    {
        $provider = new FakeProvider();
        $choice = ProviderChoice::fromSettings(null);
        $prompt = new AiPrompt(AiFeature::Explain, 'Explain.', 'Why can I not pay?');
        $rateLimited = new AiRateLimited('fake', 30);
        $unavailable = AiUnavailable::notConfigured('fake');
        $provider->failNextCallWith($rateLimited);
        $provider->failNextCallWith($unavailable);

        $thrown = [];
        foreach ([1, 2] as $attempt) {
            try {
                $provider->complete($prompt, $choice);
                self::fail('Attempt '.$attempt.' should have failed.');
            } catch (AiRateLimited|AiUnavailable $failure) {
                $thrown[] = $failure;
            }
        }
        $answer = $provider->complete($prompt, $choice);

        self::assertSame([$rateLimited, $unavailable], $thrown);
        self::assertSame(FakeProvider::fixedTextFor(AiFeature::Explain), $answer->text);
        self::assertCount(3, $provider->calls());
        self::assertSame($prompt, $provider->calls()[0]['prompt']);
        self::assertSame($choice, $provider->calls()[0]['choice']);
    }

    #[Test]
    public function anEmptyOrganizationKeyIsRefusedLikeTheRealProviderAndNoArmedFailureIsUsedUp(): void
    {
        $provider = new FakeProvider();
        $prompt = new AiPrompt(AiFeature::Summary, 'Summarize.', 'The checkout fails.');
        $armed = new AiRateLimited('fake', 30);
        $provider->failNextCallWith($armed);

        try {
            $provider->complete($prompt, ProviderChoice::fromSettings('anthropic', null, ''));
            self::fail('An empty organization key must refuse.');
        } catch (AiUnavailable $refused) {
            self::assertSame('The AI provider anthropic has no key or URL set.', $refused->getMessage());
            self::assertSame('anthropic', $refused->provider);
        }

        try {
            $provider->complete($prompt, ProviderChoice::fromSettings('anthropic', null, 'acme-own-key'));
            self::fail('The armed failure must still be thrown.');
        } catch (AiRateLimited $limited) {
            self::assertSame($armed, $limited);
        }
        self::assertCount(2, $provider->calls());
    }

    #[Test]
    public function resetForgetsTheCallsAndTheArmedFailures(): void
    {
        $provider = new FakeProvider();
        $choice = ProviderChoice::fromSettings(null);
        $prompt = new AiPrompt(AiFeature::Summary, 'Summarize.', 'Text.');
        $provider->complete($prompt, $choice);
        $provider->failNextCallWith(AiUnavailable::notConfigured('fake'));

        $provider->reset();
        $answer = $provider->complete($prompt, $choice);

        self::assertSame(FakeProvider::fixedTextFor(AiFeature::Summary), $answer->text);
        self::assertCount(1, $provider->calls());
    }
}
