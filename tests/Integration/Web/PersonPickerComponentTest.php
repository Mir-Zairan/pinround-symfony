<?php

declare(strict_types=1);

namespace App\Tests\Integration\Web;

use App\Web\UI\Component\PersonPicker;
use App\Web\UI\Component\PersonPickerMode;
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
    private const string OMAR = '0197a3c0-0000-7000-8000-00000000aab6';
    private const string LENA = '0197a3c0-0000-7000-8000-00000000aab7';

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
        self::assertSame(
            ['filter', self::TEAM, self::PROJECT, 'personPicked', '', '', '', false],
            [$props['mode'] ?? null, $props['team'] ?? null, $props['projectId'] ?? null, $props['pickedEvent'] ?? null, $props['pickForm'] ?? null, $props['pickField'] ?? null, $props['pressedPerson'] ?? null, $props['isOpened'] ?? null],
        );
        self::assertNull($search->attr('data-live-fingerprint-value'), 'No prop takes updates from the parent.');
    }

    /**
     * The trigger is the box's button, not a chip; the search gets the mode and the form its rows submit, and no event.
     */
    #[Test]
    public function theAssignModeOpensADialogFromAButtonWhoseSearchSubmitsTheGivenForm(): void
    {
        $crawler = $this->renderAssign(['isTriggerPrimary' => true]);

        $host = $crawler->filter('.person-picker__host');
        self::assertCount(0, $host->filter('button.chip'));
        $trigger = $host->filter('[data-controller="dialog"] > button.btn[data-person-picker-trigger]');
        self::assertSame(
            ['Give it to…', 'btn btn-filled', 'button', 'dialog', 'dialog#open person-picker#open', null],
            [$trigger->text(), $trigger->attr('class'), $trigger->attr('type'), $trigger->attr('aria-haspopup'), $trigger->attr('data-action'), $trigger->attr('aria-pressed')],
        );
        self::assertSame('Give SHOP-12 to…', $host->filter('dialog#picker .dialog__title')->text());
        self::assertSame('The ticket moves to their list. Sorted by how much is already waiting on them.', $host->filter('dialog#picker .person-picker__sub')->text());
        $search = $host->filter('dialog [data-live-name-value="PersonPickerSearch"]');
        $props = json_decode((string) $search->attr('data-live-props-value'), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($props);
        self::assertSame(
            ['assign', self::TEAM, '', '', 'give-it-to-form', 'assignee', false],
            [$props['mode'] ?? null, $props['team'] ?? null, $props['projectId'] ?? null, $props['pickedEvent'] ?? null, $props['pickForm'] ?? null, $props['pickField'] ?? null, $props['isOpened'] ?? null],
        );
    }

    #[Test]
    public function theAssignModesTriggerIsAnOutlineButtonUnlessItIsTheBoxsMainOne(): void
    {
        self::assertSame('btn btn-outline', $this->renderAssign([])->filter('button[data-person-picker-trigger]')->attr('class'));
    }

    /**
     * @return iterable<string, array{array<string, string>, string}>
     */
    public static function invalidAssignProps(): iterable
    {
        yield 'no ticket key' => [['ticketKey' => ''], 'needs the ticket key'];
        yield 'a blank ticket key' => [['ticketKey' => ' '], 'needs the ticket key'];
        yield 'no form' => [['pickForm' => ''], 'the form its rows submit'];
        yield 'no field' => [['pickField' => ''], 'the field they fill'];
        yield 'a blank trigger label' => [['triggerLabel' => ' '], 'needs a trigger label'];
    }

    /**
     * @param array<string, string> $invalidProps
     */
    #[Test]
    #[DataProvider('invalidAssignProps')]
    public function anInvalidAssignPropFailsAtMount(array $invalidProps, string $messagePart): void
    {
        $this->assertFailsAtMount(PersonPicker::class, [...self::assignProps(), ...$invalidProps], $messagePart);
    }

    /**
     * A row submits the form outside the dialog with the person's id and closes the dialog; it is no toggle, so it has
     * no pressed state. The count beside each is what waits on them, "0 waiting" for none.
     */
    #[Test]
    public function anAssignRowSubmitsTheFormWithThePersonAndShowsWhatWaitsOnThem(): void
    {
        $crawler = $this->renderRows(PersonPickerMode::Assign, 'give-it-to-form', 'assignee');

        $rows = $crawler->filter('.pick');
        self::assertSame(
            [
                ['Omar', '3 waiting', 'submit', 'give-it-to-form', 'assignee', self::OMAR, 'dialog#close', null],
                ['Lena', '0 waiting', 'submit', 'give-it-to-form', 'assignee', self::LENA, 'dialog#close', null],
            ],
            $rows->each(static fn (Crawler $row): array => [
                $row->filter('.pick__name')->text(),
                $row->filter('.pick__side')->text(),
                $row->attr('type'),
                $row->attr('form'),
                $row->attr('name'),
                $row->attr('value'),
                $row->attr('data-action'),
                $row->attr('aria-pressed'),
            ]),
        );
    }

    #[Test]
    public function aFilterRowEmitsThePickAndShowsTheOpenTicketsAssigned(): void
    {
        $crawler = $this->renderRows(PersonPickerMode::Filter, '', '');

        self::assertSame(
            [['Omar', '3 open', 'button', null, 'true', 'live#emitUp dialog#close'], ['Lena', '0 open', 'button', null, 'false', 'live#emitUp dialog#close']],
            $crawler->filter('.pick')->each(static fn (Crawler $row): array => [
                $row->filter('.pick__name')->text(),
                $row->filter('.pick__side')->text(),
                $row->attr('type'),
                $row->attr('form'),
                $row->attr('aria-pressed'),
                $row->attr('data-action'),
            ]),
        );
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
     * @return array<string, mixed>
     */
    private static function assignProps(): array
    {
        return ['mode' => 'assign', 'id' => 'picker', 'team' => self::TEAM, 'ticketKey' => 'SHOP-12', 'triggerLabel' => 'Give it to…', 'pickForm' => 'give-it-to-form', 'pickField' => 'assignee'];
    }

    /**
     * @param array<string, mixed> $props
     */
    private function render(array $props): Crawler
    {
        return new Crawler((string) $this->renderTwigComponent(PersonPicker::class, [...self::props(), ...$props]));
    }

    /**
     * @param array<string, mixed> $props
     */
    private function renderAssign(array $props): Crawler
    {
        return new Crawler((string) $this->renderTwigComponent(PersonPicker::class, [...self::assignProps(), ...$props]));
    }

    private function renderRows(PersonPickerMode $mode, string $pickForm, string $pickField): Crawler
    {
        return new Crawler($this->renderTemplate(
            '<twig:PersonPickerRows :mode="mode" :matchCount="2" :rows="rows" pickedEvent="personPicked" :pickForm="pickForm" :pickField="pickField"/>',
            [
                'mode' => $mode,
                'pickForm' => $pickForm,
                'pickField' => $pickField,
                'rows' => [
                    ['personId' => self::OMAR, 'name' => 'Omar', 'count' => 3, 'isPressed' => true],
                    ['personId' => self::LENA, 'name' => 'Lena', 'count' => 0, 'isPressed' => false],
                ],
            ],
        ));
    }
}
