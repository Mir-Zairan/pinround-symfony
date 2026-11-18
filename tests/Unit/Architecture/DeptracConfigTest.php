<?php

declare(strict_types=1);

namespace App\Tests\Unit\Architecture;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class DeptracConfigTest extends TestCase
{
    private const array PARTS = ['Api', 'Event', 'Domain', 'Application', 'Infrastructure', 'UI'];

    /** Layers that are not module parts; Assist_AiPlatform is the Platform folder cut out of Assist_Infrastructure. */
    private const array SPECIAL_LAYERS = ['SharedKernel', 'Kernel', 'Vendor', 'SymfonyAi', 'Assist_AiPlatform'];

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
    public function uiDomainApiAndEventLayersNeverMayUseAnEventLayer(): void
    {
        foreach ($this->config()['ruleset'] as $from => $allowed) {
            foreach (['_UI', '_Domain', '_Api', '_Event'] as $suffix) {
                if (!str_ends_with($from, $suffix)) {
                    continue;
                }
                $events = array_filter($allowed, static fn (string $name): bool => str_ends_with($name, '_Event'));
                self::assertSame([], array_values($events), $from.' must not use an Event layer');
            }
        }
    }

    #[Test]
    public function applicationInfrastructureAndUiMayCallExactlyTheApisInTheDependsOnLines(): void
    {
        $dependsOn = $this->dependsOnLines();
        self::assertSame($this->moduleFolders(), array_keys($dependsOn));

        foreach ($dependsOn as $module => ['all' => $all, 'uiOnly' => $uiOnly]) {
            foreach (['Application', 'Infrastructure', 'UI'] as $part) {
                $expected = 'UI' === $part ? $all : array_values(array_diff($all, $uiOnly));
                $otherApis = array_filter(
                    $this->config()['ruleset'][$module.'_'.$part],
                    static fn (string $name): bool => str_ends_with($name, '_Api') && $module.'_Api' !== $name,
                );
                self::assertEqualsCanonicalizing(
                    array_map(static fn (string $dependency): string => $dependency.'_Api', $expected),
                    array_values($otherApis),
                    \sprintf('%s_%s Api edges differ from the "Depends on" line of %s in modules.md', $module, $part, $module),
                );
            }
        }
    }

    #[Test]
    public function ownLayersAndSharedKernelAreAllowedWhereTheDocSays(): void
    {
        $ruleset = $this->config()['ruleset'];

        self::assertSame(['Vendor'], $ruleset['SharedKernel']);
        self::assertEqualsCanonicalizing(['Vendor', 'SharedKernel'], $ruleset['Kernel']);

        foreach ($this->moduleFolders() as $module) {
            foreach (['Api', 'Event', 'Domain'] as $part) {
                self::assertSame(['SharedKernel'], $ruleset[$module.'_'.$part]);
            }
            self::assertEqualsCanonicalizing(
                [$module.'_Domain', $module.'_Api', 'SharedKernel'],
                $this->ownLayersOnly($module, $ruleset[$module.'_Application']),
            );
            self::assertEqualsCanonicalizing(
                [$module.'_Domain', $module.'_Application', $module.'_Api', 'SharedKernel', 'Vendor'],
                $this->ownLayersOnly($module, $ruleset[$module.'_Infrastructure']),
            );
            self::assertEqualsCanonicalizing(
                [$module.'_Application', $module.'_Api', 'SharedKernel', 'Vendor'],
                array_values(array_filter($ruleset[$module.'_UI'], static fn (string $name): bool => !str_ends_with($name, '_Api') || $module.'_Api' === $name)),
            );
        }
    }

    #[Test]
    public function onlyAssistAiPlatformMayUseSymfonyAiAndOnlyItsOwnModule(): void
    {
        $ruleset = $this->config()['ruleset'];
        $layersAllowedSymfonyAi = array_keys(array_filter($ruleset, static fn (array $allowed): bool => \in_array('SymfonyAi', $allowed, true)));

        self::assertSame(['Assist_AiPlatform'], $layersAllowedSymfonyAi);
        self::assertEqualsCanonicalizing(
            ['Assist_Domain', 'Assist_Application', 'Assist_Api', 'SharedKernel', 'Vendor', 'SymfonyAi'],
            $ruleset['Assist_AiPlatform'],
        );
    }

    #[Test]
    public function symfonyAiClassesAreNotVendorAndThePlatformFolderIsNotAssistInfrastructure(): void
    {
        $layers = array_column($this->config()['layers'], null, 'name');

        self::assertSame(
            [['type' => 'classNameRegex', 'value' => '#^App\\\\#'], ['type' => 'classNameRegex', 'value' => '#^Symfony\\\\AI\\\\#'], ['type' => 'php_internal', 'value' => '.']],
            $layers['Vendor']['collectors'][0]['must_not'] ?? null,
        );
        self::assertSame([['type' => 'classNameRegex', 'value' => '#^Symfony\\\\AI\\\\#']], $layers['SymfonyAi']['collectors']);
        self::assertSame(
            [['type' => 'classNameRegex', 'value' => '#^App\\\\Assist\\\\Infrastructure\\\\Platform\\\\#']],
            $layers['Assist_Infrastructure']['collectors'][0]['must_not'] ?? null,
        );
        self::assertSame([['type' => 'classNameRegex', 'value' => '#^App\\\\Assist\\\\Infrastructure\\\\Platform\\\\#']], $layers['Assist_AiPlatform']['collectors']);
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
     * Drops Event layers and other modules' Api layers; those edges are checked by their own tests.
     *
     * @param list<string> $allowed
     *
     * @return list<string>
     */
    private function ownLayersOnly(string $module, array $allowed): array
    {
        return array_values(array_filter(
            $allowed,
            static fn (string $name): bool => !str_ends_with($name, '_Event') && (!str_ends_with($name, '_Api') || $module.'_Api' === $name),
        ));
    }

    /**
     * A module named as "X Api (UI only: …)" may be called from the UI layer alone.
     *
     * @return array<string, array{all: list<string>, uiOnly: list<string>}> module => modules named in its "Depends on" line of modules.md
     */
    private function dependsOnLines(): array
    {
        $modules = $this->moduleFolders();
        $lines = file(\dirname(__DIR__, 3).'/docs/02-architecture/modules.md', \FILE_IGNORE_NEW_LINES);
        self::assertIsArray($lines);

        $current = null;
        $result = [];
        foreach ($lines as $line) {
            if (1 === preg_match('/^### (\w+)/', $line, $heading)) {
                $current = $heading[1];
            } elseif (null !== $current && \in_array($current, $modules, true) && str_starts_with($line, '- **Depends on:**')) {
                $dependsOn = substr($line, \strlen('- **Depends on:**'));
                $withoutNotes = (string) preg_replace('/\([^)]*\)/', '', $dependsOn);
                $text = explode('.', $withoutNotes)[0];
                $all = array_values(array_filter($modules, static fn (string $module): bool => $module !== $current && 1 === preg_match('/\b'.$module.'\b/', $text)));
                $uiOnly = array_values(array_filter($all, static fn (string $module): bool => 1 === preg_match('/\b'.$module.' Api \(UI only\b/', $dependsOn)));
                $result[$current] = ['all' => $all, 'uiOnly' => $uiOnly];
            }
        }
        ksort($result);

        return $result;
    }

    /**
     * @return list<string>
     */
    private function layerNames(): array
    {
        return array_map(static fn (array $layer): string => $layer['name'], $this->config()['layers']);
    }

    /**
     * @return array{paths: list<string>, layers: list<array{name: string, collectors: list<array<string, mixed>>}>, ruleset: array<string, list<string>>}
     */
    private function config(): array
    {
        /** @var array{deptrac: array{paths: list<string>, layers: list<array{name: string, collectors: list<array<string, mixed>>}>, ruleset: array<string, list<string>>}} $parsed */
        $parsed = Yaml::parseFile(\dirname(__DIR__, 3).'/deptrac.yaml');

        return $parsed['deptrac'];
    }
}
