<?php

declare(strict_types=1);

namespace App\Tests\Unit\Assist\Application;

use App\Assist\Application\PromptVersion;
use App\Assist\Application\UnknownPromptTemplate;
use App\Assist\Domain\AiFeature;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PromptVersionTest extends TestCase
{
    #[Test]
    public function theNameIsTheFeatureAndTheVersionNumber(): void
    {
        self::assertSame('intake.v12', new PromptVersion(AiFeature::Intake, 12)->name());
    }

    #[Test]
    public function aNameReadsBackToTheSameVersion(): void
    {
        $version = PromptVersion::fromName('summary.v3');

        self::assertSame(AiFeature::Summary, $version->feature);
        self::assertSame(3, $version->number);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedNames(): iterable
    {
        yield 'unknown feature' => ['report.v1'];
        yield 'version zero' => ['summary.v0'];
        yield 'leading zero' => ['summary.v01'];
        yield 'no version' => ['summary'];
        yield 'upper case' => ['Summary.v1'];
        yield 'trailing newline' => ["summary.v1\n"];
        yield 'path' => ['../summary.v1'];
        yield 'over 999' => ['summary.v1000'];
        yield 'empty' => [''];
    }

    #[Test]
    #[DataProvider('malformedNames')]
    public function aMalformedNameIsRefused(string $name): void
    {
        $this->expectException(UnknownPromptTemplate::class);
        $this->expectExceptionMessage('The prompt version is not a feature name and a version number, like summary.v1.');

        PromptVersion::fromName($name);
    }

    #[Test]
    public function aVersionBelowOneIsRefused(): void
    {
        $this->expectException(UnknownPromptTemplate::class);
        $this->expectExceptionMessage('The prompt version is not a feature name and a version number, like summary.v1.');

        new PromptVersion(AiFeature::Summary, 0);
    }
}
