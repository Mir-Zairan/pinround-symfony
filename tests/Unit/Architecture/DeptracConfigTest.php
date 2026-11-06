<?php

declare(strict_types=1);

namespace App\Tests\Unit\Architecture;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class DeptracConfigTest extends TestCase
{
    private const array PARTS = ['Api', 'Event', 'Domain', 'Application', 'Infrastructure', 'UI'];

    /** Layers that are not module parts. */
    private const array SPECIAL_LAYERS = ['SharedKernel', 'Kernel', 'Vendor'];

    #[Test]
    public function everyModuleFolderHasAllSixPartLayers(): void
    {
        $layers = $this->layerNames();

        foreach ($this->moduleFolders() as $module) {
            foreach (self::PARTS as $part) {
                self::assertContains($module.'_'.$part, $layers, \sprintf('Missing layer %s_%s', $module, $part));
            }
        }
    }

    #[Test]
    public function sharedKernelHasItsOwnLayer(): void
    {
        self::assertContains('SharedKernel', $this->layerNames());
    }

    #[Test]
    public function everyModuleLayerMatchesAFolderThatExists(): void
    {
        $modules = $this->moduleFolders();

        foreach ($this->layerNames() as $layer) {
            if (\in_array($layer, self::SPECIAL_LAYERS, true)) {
                continue;
            }

            [$module, $part] = explode('_', $layer, 2) + [1 => ''];
            self::assertContains($module, $modules, \sprintf('Layer %s has no folder src/%s', $layer, $module));
            self::assertContains($part, self::PARTS, \sprintf('Layer %s is not one of the six parts', $layer));
        }
    }

    #[Test]
    public function everyLayerNamedInTheRulesetExists(): void
    {
        $layers = $this->layerNames();

        foreach ($this->config()['ruleset'] as $from => $allowed) {
            self::assertContains($from, $layers, \sprintf('Ruleset source %s is not a layer', $from));
            foreach ($allowed as $to) {
                self::assertContains($to, $layers, \sprintf('%s may depend on %s, which is not a layer', $from, $to));
            }
        }
    }

    #[Test]
    public function onlyInfrastructureAndUiLayersMayDependOnVendor(): void
    {
        foreach ($this->config()['ruleset'] as $from => $allowed) {
            foreach (['_Domain', '_Api', '_Event', '_Application'] as $suffix) {
                if (!str_ends_with($from, $suffix)) {
                    continue;
                }
                self::assertNotContains('Vendor', $allowed, $from.' must not depend on Vendor');
            }
        }
    }

    #[Test]
    public function applicationAndInfrastructureLayersMayListenToEveryModulesEvents(): void
    {
        $eventLayers = array_values(array_filter($this->layerNames(), static fn (string $name): bool => str_ends_with($name, '_Event')));
        self::assertCount(\count($this->moduleFolders()), $eventLayers);

        foreach ($this->moduleFolders() as $module) {
            foreach (['Application', 'Infrastructure'] as $part) {
                self::assertEqualsCanonicalizing(
                    $eventLayers,
                    array_values(array_filter($this->config()['ruleset'][$module.'_'.$part], static fn (string $name): bool => str_ends_with($name, '_Event'))),
                    \sprintf('%s_%s must be allowed to use every Event layer', $module, $part),
                );
            }
            self::assertSame(['SharedKernel'], $this->config()['ruleset'][$module.'_Event']);
        }
    }

    #[Test]
    public function analysisIsLimitedToSrc(): void
    {
        self::assertSame(['./src'], $this->config()['paths']);
    }

    /**
     * @return list<string>
     */
    private function moduleFolders(): array
    {
        $folders = [];
        foreach (new \DirectoryIterator(\dirname(__DIR__, 3).'/src') as $entry) {
            if ($entry->isDir() && !$entry->isDot() && 'SharedKernel' !== $entry->getFilename()) {
                $folders[] = $entry->getFilename();
            }
        }
        sort($folders);

        return $folders;
    }

    /**
     * @return list<string>
     */
    private function layerNames(): array
    {
        return array_map(static fn (array $layer): string => $layer['name'], $this->config()['layers']);
    }

    /**
     * @return array{paths: list<string>, layers: list<array{name: string}>, ruleset: array<string, list<string>>}
     */
    private function config(): array
    {
        /** @var array{deptrac: array{paths: list<string>, layers: list<array{name: string}>, ruleset: array<string, list<string>>}} $parsed */
        $parsed = Yaml::parseFile(\dirname(__DIR__, 3).'/deptrac.yaml');

        return $parsed['deptrac'];
    }
}
