<?php

declare(strict_types=1);

namespace App\Tests\Integration\Web;

use App\Web\UI\Component\PersonPicker;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DomCrawler\Crawler;

final class PersonPickerComponentTest extends KernelTestCase
{
    use RendersTwigComponents;

    private const string TEAM = '0197a3c0-0000-7000-8000-00000000aaa1';
    private const string PROJECT = '0197a3c0-0000-7000-8000-00000000aad1';
    private const string EVA = '0197a3c0-0000-7000-8000-00000000aab5';

    /**
     * The search gets only props that never change after the page renders, so the parent's re-renders send it no
     * request; the pressed person sits on the controller instead, which hands it to the search as the dialog opens.
     */
    #[Test]
    public function theFilterModeOpensADialogWhoseSearchGetsOnlyFixedProps(): void
    {
        $crawler = $this->render(['pressedPerson' => self::EVA, 'isTriggerPressed' => true, 'triggerLabel' => 'Eva']);

        $host = $crawler->filter('.person-picker__host');
        self::assertSame(['person-picker', self::EVA], [$host->attr('data-controller'), $host->attr('data-person-picker-pressed-person-value')]);
        $chip = $host->filter('[data-controller="dialog"] > button.chip[data-person-picker-trigger]');
        self::assertSame(['Eva', 'true', 'dialog', 'dialog#open person-picker#open'], [$chip->text(), $chip->attr('aria-pressed'), $chip->attr('aria-haspopup'), $chip->attr('data-action')]);
        self::assertSame('Show tickets of…', $host->filter('dialog#picker .dialog__title')->text());
        self::assertSame('Sorted by open tickets assigned to them.', $host->filter('dialog#picker .person-picker__sub')->text());
        self::assertSame('Cancel', $host->filter('dialog#picker .dialog__actions button[data-action="dialog#close"]')->text());
        $search = $host->filter('dialog [data-live-name-value="PersonPickerSearch"]');
        $props = json_decode((string) $search->attr('data-live-props-value'), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($props);
        self::assertSame([self::TEAM, self::PROJECT, 'personPicked', '', false], [$props['team'] ?? null, $props['projectId'] ?? null, $props['pickedEvent'] ?? null, $props['pressedPerson'] ?? null, $props['isOpened'] ?? null]);
        self::assertNull($search->attr('data-live-fingerprint-value'), 'No prop takes updates from the parent.');
    }

    #[Test]
    public function anUnknownModeFailsAtMount(): void
    {
        $this->assertFailsAtMount(PersonPicker::class, [...self::props(), 'mode' => 'assignee'], '"assignee" is not a valid backing value', \ValueError::class);
    }

    #[Test]
    public function aMissingModeFailsAtMount(): void
    {
        $props = self::props();
        unset($props['mode']);

        $this->assertFailsAtMount(PersonPicker::class, $props, 'needs a mode');
    }

    /**
     * @return iterable<string, array{array<string, string>, string}>
     */
    public static function invalidProps(): iterable
    {
        yield 'no team' => [['team' => ''], 'needs a team'];
        yield 'an uppercase team' => [['team' => strtoupper(self::TEAM)], 'needs a team'];
        yield 'a project key for the project id' => [['projectId' => 'SHOP'], 'project must be a canonical uuid'];
        yield 'a pressed person that is no uuid' => [['pressedPerson' => 'eva'], 'pressed person must be a canonical uuid'];
        yield 'a blank trigger label' => [['triggerLabel' => ' '], 'needs a trigger label'];
        yield 'no picked event' => [['pickedEvent' => ''], 'needs a trigger label and the event'];
    }

    /**
     * @param array<string, string> $invalidProps
     */
    #[Test]
    #[DataProvider('invalidProps')]
    public function anInvalidPropFailsAtMount(array $invalidProps, string $messagePart): void
    {
        $this->assertFailsAtMount(PersonPicker::class, [...self::props(), ...$invalidProps], $messagePart);
    }

    /**
     * @return array<string, mixed>
     */
    private static function props(): array
    {
        return ['mode' => 'filter', 'id' => 'picker', 'team' => self::TEAM, 'projectId' => self::PROJECT, 'triggerLabel' => 'Person ▾', 'pickedEvent' => 'personPicked'];
    }

    /**
     * @param array<string, mixed> $props
     */
    private function render(array $props): Crawler
    {
        return new Crawler((string) $this->renderTwigComponent(PersonPicker::class, [...self::props(), ...$props]));
    }
}
