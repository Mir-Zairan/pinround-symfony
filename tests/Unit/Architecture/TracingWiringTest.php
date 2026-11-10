<?php

declare(strict_types=1);

namespace App\Tests\Unit\Architecture;

use App\SharedKernel\Infrastructure\Tracing\TracerProviderFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * The tracer provider factory as config/services.yaml defines it for dev and prod, read from the file: the test env
 * replaces it with an in-memory exporter, so no booted test kernel sees these arguments.
 */
final class TracingWiringTest extends TestCase
{
    #[Test]
    public function devAndProdWireEachOtelEnvNameToItsOwnArgumentAndNoTestExporter(): void
    {
        $services = self::services(self::config());

        self::assertSame([
            '$endpoint' => '%env(OTEL_EXPORTER_OTLP_ENDPOINT)%',
            '$serviceName' => '%env(OTEL_SERVICE_NAME)%',
            '$samplingRatio' => '%env(OTEL_TRACES_SAMPLER_ARG)%',
            '$protocol' => '%env(OTEL_EXPORTER_OTLP_PROTOCOL)%',
            '$exporter' => null,
            '$httpClient' => null,
        ], self::factoryArguments($services));
    }

    #[Test]
    public function onlyTheTestEnvHandsTheFactoryAnExporter(): void
    {
        $config = self::config();
        $testArguments = self::factoryArguments(self::services($config['when@test'] ?? null));

        self::assertSame('', $testArguments['$endpoint']);
        self::assertSame('@tracing.in_memory_exporter', $testArguments['$exporter']);

        $environmentsReplacingIt = [];
        foreach ($config as $key => $section) {
            if (str_starts_with((string) $key, 'when@') && \is_array($section) && \is_array($section['services'] ?? null)
                && \array_key_exists(TracerProviderFactory::class, $section['services'])) {
                $environmentsReplacingIt[] = $key;
            }
        }
        self::assertSame(['when@test'], $environmentsReplacingIt);
    }

    #[Test]
    public function theCommittedEnvDefaultsLeaveTracingOffAndSampleEverything(): void
    {
        $env = (string) file_get_contents(__DIR__.'/../../../.env');

        self::assertMatchesRegularExpression('/^OTEL_EXPORTER_OTLP_ENDPOINT=$/m', $env);
        self::assertMatchesRegularExpression('/^OTEL_SERVICE_NAME=pinround$/m', $env);
        self::assertMatchesRegularExpression('/^OTEL_TRACES_SAMPLER_ARG=1\.0$/m', $env);
        self::assertMatchesRegularExpression('/^OTEL_EXPORTER_OTLP_PROTOCOL=http\/protobuf$/m', $env);
    }

    /**
     * @param array<mixed> $services
     *
     * @return array<mixed>
     */
    private static function factoryArguments(array $services): array
    {
        $definition = $services[TracerProviderFactory::class] ?? null;
        self::assertIsArray($definition);
        self::assertIsArray($definition['arguments'] ?? null);

        return $definition['arguments'];
    }

    /**
     * @return array<mixed>
     */
    private static function config(): array
    {
        $config = Yaml::parseFile(__DIR__.'/../../../config/services.yaml', Yaml::PARSE_CUSTOM_TAGS);
        self::assertIsArray($config);

        return $config;
    }

    /**
     * @return array<mixed>
     */
    private static function services(mixed $section): array
    {
        self::assertIsArray($section);
        self::assertIsArray($section['services'] ?? null);

        return $section['services'];
    }
}
