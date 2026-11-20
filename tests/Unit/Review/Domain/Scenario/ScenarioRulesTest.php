<?php

declare(strict_types=1);

namespace App\Tests\Unit\Review\Domain\Scenario;

use App\Review\Domain\Scenario\ScenarioRefused;
use App\Review\Domain\Scenario\ScenarioRules;
use App\Review\Domain\Scenario\ScenarioStep;
use App\Review\Domain\Scenario\ScenarioTarget;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ScenarioRulesTest extends TestCase
{
    private const string START_URL = 'http://localhost:8000/orders';
    private const string SCHEMA_FILE = __DIR__.'/../../../../../docs/api/scenario.v1.json';

    #[Test]
    public function scenariosAtEveryCapAreKeptAsSentInCharactersNotBytes(): void
    {
        $steps = [
            ['type' => 'go_to', 'say' => str_repeat('é', 200), 'url' => 'http://LOCALHOST:8000/orders/12?tab=lines#total'],
            ['type' => 'click', 'say' => 'Open the order.', 'target' => ['role' => 'button', 'name' => str_repeat('😀', 1000)]],
            ['type' => 'type', 'say' => 'Clear the amount.', 'target' => ['label' => 'Quantity'], 'text' => ''],
            ['type' => 'select', 'say' => 'Pick a shop.', 'target' => ['role' => 'combobox'], 'option' => 'Acme Foods'],
            ['type' => 'wait_for', 'say' => 'Wait for the banner.', 'target' => ['role' => 'alert']],
            ['type' => 'expect_text', 'say' => 'The warning shows.', 'text' => str_repeat('ü', 1000)],
            ['type' => 'expect_text', 'say' => "\u{FEFF}In the banner.\r\n", 'text' => 'Out of stock', 'target' => ['text' => 'Stock']],
            ['type' => 'expect_visible', 'say' => 'The grey button.', 'target' => ['role' => 'graphics-document', 'name' => 'Ship']],
            ['type' => 'pause', 'say' => 'Wait until the seed command is done.'],
            ...array_fill(0, 21, ['type' => 'capture', 'say' => 'Keep a picture.']),
        ];
        $scenario = ['title' => str_repeat('é', 120), 'why' => str_repeat('w', 600), 'setup' => str_repeat('ß', 600), 'start_url' => self::START_URL, 'steps' => $steps];

        $parsed = ScenarioRules::parse(array_fill(0, 20, $scenario))->all();

        self::assertCount(20, $parsed);
        $first = $parsed[0];
        self::assertSame([str_repeat('é', 120), str_repeat('w', 600), str_repeat('ß', 600), self::START_URL], [$first->title, $first->why, $first->setup, $first->startUrl]);
        self::assertCount(30, $first->steps);
        self::assertEquals(new ScenarioStep('go_to', str_repeat('é', 200), url: 'http://LOCALHOST:8000/orders/12?tab=lines#total'), $first->steps[0]);
        self::assertEquals(ScenarioTarget::byRole('button', str_repeat('😀', 1000)), $first->steps[1]->target);
        self::assertSame('', $first->steps[2]->text, 'Typing nothing clears a field.');
        self::assertEquals(ScenarioTarget::byLabel('Quantity'), $first->steps[2]->target);
        self::assertEquals(new ScenarioStep('select', 'Pick a shop.', ScenarioTarget::byRole('combobox', null), option: 'Acme Foods'), $first->steps[3]);
        self::assertNull($first->steps[5]->target, 'expect_text without a target looks at the whole page.');
        self::assertEquals(new ScenarioStep('expect_text', "\u{FEFF}In the banner.\r\n", ScenarioTarget::byVisibleText('Stock'), 'Out of stock'), $first->steps[6]);
        self::assertEquals(new ScenarioStep('pause', 'Wait until the seed command is done.'), $first->steps[8]);
    }

    #[Test]
    public function noScenariosAreAnEmptyList(): void
    {
        self::assertSame([], ScenarioRules::parse([])->all());
    }

    #[Test]
    public function scenariosWrittenBackAsArraysParseToTheSameScenarios(): void
    {
        $sent = [self::scenario([
            ['type' => 'go_to', 'say' => 'Open it.', 'url' => 'http://localhost:8000/orders/12'],
            ['type' => 'type', 'say' => 'Type.', 'target' => ['role' => 'textbox', 'name' => 'Quantity'], 'text' => '3'],
            ['type' => 'expect_text', 'say' => 'Check.', 'text' => 'Out of stock'],
            ['type' => 'select', 'say' => 'Pick.', 'target' => ['label' => 'Shop'], 'option' => 'North'],
        ])];

        $parsed = ScenarioRules::parse($sent);

        self::assertSame($sent, $parsed->toArray());
        self::assertEquals($parsed, ScenarioRules::parse($parsed->toArray()));
        self::assertEquals($parsed, ScenarioRules::parse(json_decode(json_encode($sent, \JSON_THROW_ON_ERROR), false, 512, \JSON_THROW_ON_ERROR)), 'Objects decoded as stdClass read the same.');
    }

    /**
     * Each rule with the input it refuses, the plain error, and a twin that differs only in what the rule is about.
     *
     * @return iterable<string, array{mixed, string, mixed}>
     */
    public static function rulesWithTheirValidTwin(): iterable
    {
        yield 'an unknown step type' => [
            [self::scenario([self::click(), ['type' => 'hover', 'say' => 'Hover.', 'target' => ['text' => 'Menu']]])],
            'Scenario 1, step 2: type must be one of go_to, click, type, select, wait_for, expect_text, expect_visible, pause, capture.',
            [self::scenario([self::click(), ['type' => 'click', 'say' => 'Hover.', 'target' => ['text' => 'Menu']]])],
        ];
        yield 'a step type in another case' => [
            [self::scenario([['type' => 'Click', 'say' => 'Save.', 'target' => ['text' => 'Save']]])],
            'Scenario 1, step 1: type must be one of go_to, click, type, select, wait_for, expect_text, expect_visible, pause, capture.',
            [self::scenario([['type' => 'click', 'say' => 'Save.', 'target' => ['text' => 'Save']]])],
        ];
        yield 'a CSS target' => [
            [self::scenario([self::click(), self::click(), self::click(['css' => '#save'])])],
            'Scenario 1, step 3: find the element by role and name, by visible text or by label, never by a CSS or XPath string.',
            [self::scenario([self::click(), self::click(), self::click(['role' => 'button', 'name' => 'Save'])])],
        ];
        yield 'an XPath target' => [
            [self::scenario([self::click(['xpath' => '//button[@id="save"]'])])],
            'Scenario 1, step 1: find the element by role and name, by visible text or by label, never by a CSS or XPath string.',
            [self::scenario([self::click(['text' => 'Save'])])],
        ];
        yield 'a target given as a selector string' => [
            [self::scenario([self::click('button.primary')])],
            'Scenario 1, step 1: find the element by role and name, by visible text or by label, never by a CSS or XPath string.',
            [self::scenario([self::click(['label' => 'button.primary'])])],
        ];
        yield 'a selector beside a role' => [
            [self::scenario([self::click(['role' => 'button', 'selector' => '.save'])])],
            'Scenario 1, step 1: find the element by role and name, by visible text or by label, never by a CSS or XPath string.',
            [self::scenario([self::click(['role' => 'button'])])],
        ];
        yield 'two locator kinds' => [
            [self::scenario([self::click(['text' => 'Save', 'label' => 'Save'])])],
            'Scenario 1, step 1: find the element by role and name, by visible text or by label, never by a CSS or XPath string.',
            [self::scenario([self::click(['text' => 'Save'])])],
        ];
        yield 'a name without a role' => [
            [self::scenario([self::click(['text' => 'Save', 'name' => 'Save'])])],
            'Scenario 1, step 1: find the element by role and name, by visible text or by label, never by a CSS or XPath string.',
            [self::scenario([self::click(['role' => 'button', 'name' => 'Save'])])],
        ];
        yield 'no locator at all' => [
            [self::scenario([self::click([])])],
            'Scenario 1, step 1: find the element by role and name, by visible text or by label, never by a CSS or XPath string.',
            [self::scenario([self::click(['label' => 'Save'])])],
        ];
        yield 'a role that is a selector' => [
            [self::scenario([self::click(['role' => 'button.primary'])])],
            'Scenario 1, step 1: target.role must be an ARIA role in lowercase, like button or alert.',
            [self::scenario([self::click(['role' => 'button'])])],
        ];
        yield 'a role with a trailing newline' => [
            [self::scenario([self::click(['role' => "button\n"])])],
            'Scenario 1, step 1: target.role must be an ARIA role in lowercase, like button or alert.',
            [self::scenario([self::click(['role' => 'button'])])],
        ];
        yield '31 steps' => [
            [self::scenario(array_fill(0, 31, self::click()))],
            'Scenario 1: a scenario has at most 30 steps.',
            [self::scenario(array_fill(0, 30, self::click()))],
        ];
        yield 'no steps' => [
            [self::scenario([])],
            'Scenario 1: add at least one step.',
            [self::scenario([self::click()])],
        ];
        yield 'steps that are no list' => [
            [self::scenario(['first' => self::click()])],
            'Scenario 1: steps must be a list.',
            [self::scenario([self::click()])],
        ];
        yield '21 scenarios' => [
            array_fill(0, 21, self::scenario([self::click()])),
            'A notebook holds at most 20 scenarios.',
            array_fill(0, 20, self::scenario([self::click()])),
        ];
        yield 'scenarios that are no list' => [
            ['first' => self::scenario([self::click()])],
            'Send the scenarios as a list.',
            [self::scenario([self::click()])],
        ];
        yield 'scenarios sent as an empty JSON object' => [
            json_decode('{}', false, 512, \JSON_THROW_ON_ERROR),
            'Send the scenarios as a list.',
            json_decode('[]', false, 512, \JSON_THROW_ON_ERROR),
        ];
        yield 'scenarios sent as a JSON object keyed 0' => [
            json_decode('{"0": '.self::json(self::scenario([self::click()])).'}', false, 512, \JSON_THROW_ON_ERROR),
            'Send the scenarios as a list.',
            json_decode('['.self::json(self::scenario([self::click()])).']', false, 512, \JSON_THROW_ON_ERROR),
        ];
        yield 'steps sent as a JSON object keyed 0' => [
            json_decode('['.self::json(self::scenario([], ['steps' => (object) ['0' => self::click()]])).']', false, 512, \JSON_THROW_ON_ERROR),
            'Scenario 1: steps must be a list.',
            json_decode('['.self::json(self::scenario([self::click()])).']', false, 512, \JSON_THROW_ON_ERROR),
        ];
        yield 'scenarios sent as one string' => [
            '[{"title": "The stock warning shows"}]',
            'Send the scenarios as a list.',
            [],
        ];
        yield 'a title of 121 characters' => [
            [self::scenario([self::click()]), self::scenario([self::click()], ['title' => str_repeat('é', 121)])],
            'Scenario 2: keep title to 120 characters or fewer.',
            [self::scenario([self::click()]), self::scenario([self::click()], ['title' => str_repeat('é', 120)])],
        ];
        yield 'a why of 601 characters' => [
            [self::scenario([self::click()], ['why' => str_repeat('😀', 601)])],
            'Scenario 1: keep why to 600 characters or fewer.',
            [self::scenario([self::click()], ['why' => str_repeat('😀', 600)])],
        ];
        yield 'a setup of 601 characters' => [
            [self::scenario([self::click()], ['setup' => str_repeat('ß', 601)])],
            'Scenario 1: keep setup to 600 characters or fewer.',
            [self::scenario([self::click()], ['setup' => str_repeat('ß', 600)])],
        ];
        yield 'a say line of 201 characters' => [
            [self::scenario([self::click(), self::click(), self::click(), self::click(), ['type' => 'pause', 'say' => str_repeat('é', 201)]])],
            'Scenario 1, step 5: keep say to 200 characters or fewer.',
            [self::scenario([self::click(), self::click(), self::click(), self::click(), ['type' => 'pause', 'say' => str_repeat('é', 200)]])],
        ];
        yield 'a typed text of 1,001 characters' => [
            [self::scenario([['type' => 'type', 'say' => 'Type.', 'target' => ['label' => 'Note'], 'text' => str_repeat('😀', 1001)]])],
            'Scenario 1, step 1: keep text to 1,000 characters or fewer.',
            [self::scenario([['type' => 'type', 'say' => 'Type.', 'target' => ['label' => 'Note'], 'text' => str_repeat('😀', 1000)]])],
        ];
        yield 'an option of 1,001 characters' => [
            [self::scenario([['type' => 'select', 'say' => 'Pick.', 'target' => ['label' => 'Shop'], 'option' => str_repeat('o', 1001)]])],
            'Scenario 1, step 1: keep option to 1,000 characters or fewer.',
            [self::scenario([['type' => 'select', 'say' => 'Pick.', 'target' => ['label' => 'Shop'], 'option' => str_repeat('o', 1000)]])],
        ];
        yield 'a target name of 1,001 characters' => [
            [self::scenario([self::click(['role' => 'button', 'name' => str_repeat('ü', 1001)])])],
            'Scenario 1, step 1: keep target.name to 1,000 characters or fewer.',
            [self::scenario([self::click(['role' => 'button', 'name' => str_repeat('ü', 1000)])])],
        ];
        yield 'a target text of 1,001 characters' => [
            [self::scenario([self::click(['text' => str_repeat('t', 1001)])])],
            'Scenario 1, step 1: keep target.text to 1,000 characters or fewer.',
            [self::scenario([self::click(['text' => str_repeat('t', 1000)])])],
        ];
        yield 'a target label of 1,001 characters' => [
            [self::scenario([self::click(['label' => str_repeat('l', 1001)])])],
            'Scenario 1, step 1: keep target.label to 1,000 characters or fewer.',
            [self::scenario([self::click(['label' => str_repeat('l', 1000)])])],
        ];
        yield 'a target role of 1,001 characters' => [
            [self::scenario([self::click(['role' => str_repeat('a', 1001)])])],
            'Scenario 1, step 1: keep target.role to 1,000 characters or fewer.',
            [self::scenario([self::click(['role' => str_repeat('a', 1000)])])],
        ];
        yield 'a go_to url of 1,001 characters' => [
            [self::scenario([self::goTo(self::urlOfLength(1001))])],
            'Scenario 1, step 1: keep url to 1,000 characters or fewer.',
            [self::scenario([self::goTo(self::urlOfLength(1000))])],
        ];
        yield 'a start_url of 1,001 characters' => [
            [self::scenario([self::click()], ['start_url' => self::urlOfLength(1001)])],
            'Scenario 1: keep start_url to 1,000 characters or fewer.',
            [self::scenario([self::click()], ['start_url' => self::urlOfLength(1000)])],
        ];
        yield 'a blank title' => [
            [self::scenario([self::click()], ['title' => " \r\n\t"])],
            'Scenario 1: title must not be blank.',
            [self::scenario([self::click()], ['title' => " \r\nx"])],
        ];
        yield 'a why of only spaces that are not ASCII' => [
            [self::scenario([self::click()], ['why' => "\u{00A0}\u{2028}\u{3000}"])],
            'Scenario 1: why must not be blank.',
            [self::scenario([self::click()], ['why' => "\u{00A0}x\u{3000}"])],
        ];
        yield 'a say line of only a byte order mark' => [
            [self::scenario([['type' => 'capture', 'say' => "\u{FEFF}"]])],
            'Scenario 1, step 1: say must not be blank.',
            [self::scenario([['type' => 'capture', 'say' => "\u{FEFF}Keep it."]])],
        ];
        yield 'a blank expected text' => [
            [self::scenario([['type' => 'expect_text', 'say' => 'Check.', 'text' => ' ']])],
            'Scenario 1, step 1: text must not be blank.',
            [self::scenario([['type' => 'type', 'say' => 'Check.', 'target' => ['label' => 'Note'], 'text' => ' ']])],
        ];
        yield 'a blank target text' => [
            [self::scenario([self::click(['text' => ''])])],
            'Scenario 1, step 1: target.text must not be blank.',
            [self::scenario([self::click(['text' => '0'])])],
        ];
        yield 'a blank target name' => [
            [self::scenario([self::click(['role' => 'button', 'name' => "\t"])])],
            'Scenario 1, step 1: target.name must not be blank.',
            [self::scenario([self::click(['role' => 'button'])])],
        ];
        yield 'a blank target label' => [
            [self::scenario([self::click(['label' => ' '])])],
            'Scenario 1, step 1: target.label must not be blank.',
            [self::scenario([self::click(['label' => 'Note'])])],
        ];
        yield 'a blank option' => [
            [self::scenario([['type' => 'select', 'say' => 'Pick.', 'target' => ['label' => 'Shop'], 'option' => '']])],
            'Scenario 1, step 1: option must not be blank.',
            [self::scenario([['type' => 'select', 'say' => 'Pick.', 'target' => ['label' => 'Shop'], 'option' => 'North']])],
        ];
        yield 'a say line that is no UTF-8' => [
            [self::scenario([['type' => 'pause', 'say' => "Caf\xC3\x28"]])],
            'Scenario 1, step 1: say is not valid UTF-8.',
            [self::scenario([['type' => 'pause', 'say' => "Caf\xC3\xA9"]])],
        ];
        yield 'a title with a NUL character' => [
            [self::scenario([self::click()], ['title' => "The stock\0warning"])],
            'Scenario 1: title must not hold a NUL character.',
            [self::scenario([self::click()], ['title' => 'The stock warning'])],
        ];
        yield 'a setup of only a NUL character' => [
            [self::scenario([self::click()], ['setup' => "\0"])],
            'Scenario 1: setup must not hold a NUL character.',
            [self::scenario([self::click()], ['setup' => ''])],
        ];
        yield 'a typed text with a NUL character' => [
            [self::scenario([self::click(), ['type' => 'type', 'say' => 'Type.', 'target' => ['label' => 'Note'], 'text' => "3\0"]])],
            'Scenario 1, step 2: text must not hold a NUL character.',
            [self::scenario([self::click(), ['type' => 'type', 'say' => 'Type.', 'target' => ['label' => 'Note'], 'text' => '3']])],
        ];
        yield 'a target label with a NUL character' => [
            [self::scenario([self::click(['label' => "\0Note"])])],
            'Scenario 1, step 1: target.label must not hold a NUL character.',
            [self::scenario([self::click(['label' => 'Note'])])],
        ];
        yield 'a target name that is no UTF-8' => [
            [self::scenario([self::click(['role' => 'button', 'name' => "Sav\xC3\x28"])])],
            'Scenario 1, step 1: target.name is not valid UTF-8.',
            [self::scenario([self::click(['role' => 'button', 'name' => "Sav\xC3\xA9"])])],
        ];
        yield 'a target name nested one level deeper than text' => [
            [self::scenario([self::click(['role' => 'button', 'name' => ['text' => 'Save']])])],
            'Scenario 1, step 1: target.name must be text.',
            [self::scenario([self::click(['role' => 'button', 'name' => 'Save'])])],
        ];
        yield 'a title that is a number' => [
            [self::scenario([self::click()], ['title' => 12])],
            'Scenario 1: title must be text.',
            [self::scenario([self::click()], ['title' => '12'])],
        ];
        yield 'a scenario with a field the format does not know' => [
            [self::scenario([self::click()], ['script' => 'alert(1)'])],
            'Scenario 1: a scenario has exactly title, why, setup, start_url and steps.',
            [self::scenario([self::click()])],
        ];
        yield 'a scenario without setup' => [
            [array_diff_key(self::scenario([self::click()]), ['setup' => true])],
            'Scenario 1: a scenario has exactly title, why, setup, start_url and steps.',
            [self::scenario([self::click()], ['setup' => ''])],
        ];
        yield 'a scenario that is no object' => [
            ['Show the stock warning'],
            'Scenario 1: a scenario has exactly title, why, setup, start_url and steps.',
            [self::scenario([self::click()])],
        ];
        yield 'a step that is no object' => [
            [self::scenario(['click Save'])],
            'Scenario 1, step 1: write the step as an object with type and say.',
            [self::scenario([self::click()])],
        ];
        yield 'a click with a text' => [
            [self::scenario([['type' => 'click', 'say' => 'Save.', 'target' => ['text' => 'Save'], 'text' => 'Save']])],
            'Scenario 1, step 1: a step of type click takes type, say and target.',
            [self::scenario([['type' => 'click', 'say' => 'Save.', 'target' => ['text' => 'Save']]])],
        ];
        yield 'a type step without text' => [
            [self::scenario([['type' => 'type', 'say' => 'Type.', 'target' => ['label' => 'Note']]])],
            'Scenario 1, step 1: a step of type type takes type, say, target and text.',
            [self::scenario([['type' => 'type', 'say' => 'Type.', 'target' => ['label' => 'Note'], 'text' => 'Hi']])],
        ];
        yield 'an expect_text with an option' => [
            [self::scenario([['type' => 'expect_text', 'say' => 'Check.', 'text' => 'Hi', 'option' => 'Hi']])],
            'Scenario 1, step 1: a step of type expect_text takes type, say and text, and may take target.',
            [self::scenario([['type' => 'expect_text', 'say' => 'Check.', 'text' => 'Hi', 'target' => ['role' => 'alert']]])],
        ];
        yield 'a pause without a say line' => [
            [self::scenario([['type' => 'pause']])],
            'Scenario 1, step 1: a step of type pause takes type and say.',
            [self::scenario([['type' => 'pause', 'say' => 'Wait.']])],
        ];
        yield 'a go_to to another host' => [
            [self::scenario([self::click(), self::goTo('http://shop.example.test:8000/orders')])],
            'Scenario 1, step 2: go_to must stay on the scheme, host and port of start_url.',
            [self::scenario([self::click(), self::goTo('http://localhost:8000/products?page=2')])],
        ];
        yield 'a go_to to another port' => [
            [self::scenario([self::goTo('http://localhost:8001/orders')])],
            'Scenario 1, step 1: go_to must stay on the scheme, host and port of start_url.',
            [self::scenario([self::goTo('http://localhost:8000')])],
        ];
        yield 'a go_to to another scheme' => [
            [self::scenario([self::goTo('https://localhost:8000/orders')])],
            'Scenario 1, step 1: go_to must stay on the scheme, host and port of start_url.',
            [self::scenario([self::goTo('http://Localhost:8000/orders')])],
        ];
        yield 'a go_to on the default port when start_url names another' => [
            [self::scenario([self::goTo('https://localhost/orders')], ['start_url' => 'https://localhost:80/'])],
            'Scenario 1, step 1: go_to must stay on the scheme, host and port of start_url.',
            [self::scenario([self::goTo('https://localhost/orders')], ['start_url' => 'https://localhost:443/'])],
        ];
        yield 'a go_to with a user name before the host' => [
            [self::scenario([self::goTo('http://localhost:8000@shop.example.test/')])],
            'Scenario 1, step 1: url must be a full http or https address, like http://localhost:8000/orders.',
            [self::scenario([self::goTo('http://localhost:8000/@shop.example.test/')])],
        ];
        yield 'a go_to with a backslash' => [
            [self::scenario([self::goTo('http://localhost:8000/a\\b')])],
            'Scenario 1, step 1: url must be a full http or https address, like http://localhost:8000/orders.',
            [self::scenario([self::goTo('http://localhost:8000/a/b')])],
        ];
        yield 'a relative go_to' => [
            [self::scenario([self::goTo('//shop.example.test/orders')])],
            'Scenario 1, step 1: url must be a full http or https address, like http://localhost:8000/orders.',
            [self::scenario([self::goTo('http://localhost:8000/orders')])],
        ];
        yield 'a javascript: start_url' => [
            [self::scenario([self::click()], ['start_url' => 'javascript:alert(1)'])],
            'Scenario 1: start_url must be a full http or https address, like http://localhost:8000/orders.',
            [self::scenario([self::click()], ['start_url' => 'https://localhost/'])],
        ];
        yield 'a start_url with a trailing newline' => [
            [self::scenario([self::click()], ['start_url' => self::START_URL."\n"])],
            'Scenario 1: start_url must be a full http or https address, like http://localhost:8000/orders.',
            [self::scenario([self::click()], ['start_url' => self::START_URL])],
        ];
        yield 'a start_url with a line separator' => [
            [self::scenario([self::click()], ['start_url' => self::START_URL."\u{2028}x"])],
            'Scenario 1: start_url must be a full http or https address, like http://localhost:8000/orders.',
            [self::scenario([self::click()], ['start_url' => self::START_URL.'/ü'])],
        ];
        yield 'a start_url with a carriage return' => [
            [self::scenario([self::click()], ['start_url' => "http://localhost:8000/\r\nx"])],
            'Scenario 1: start_url must be a full http or https address, like http://localhost:8000/orders.',
            [self::scenario([self::click()], ['start_url' => 'http://[::1]:8000/x'])],
        ];
        yield 'a start_url with a byte order mark' => [
            [self::scenario([self::click()], ['start_url' => self::START_URL."\u{FEFF}x"])],
            'Scenario 1: start_url must be a full http or https address, like http://localhost:8000/orders.',
            [self::scenario([self::click()], ['start_url' => self::START_URL."\u{200B}x"])],
        ];
        yield 'a start_url on port 65536' => [
            [self::scenario([self::click()], ['start_url' => 'http://localhost:65536/orders'])],
            'Scenario 1: start_url must be a full http or https address, like http://localhost:8000/orders.',
            [self::scenario([self::click()], ['start_url' => 'http://localhost:65535/orders'])],
        ];
        yield 'a start_url on port 0' => [
            [self::scenario([self::click()], ['start_url' => 'https://localhost:0/orders'])],
            'Scenario 1: start_url must be a full http or https address, like http://localhost:8000/orders.',
            [self::scenario([self::click()], ['start_url' => 'https://localhost:1/orders'])],
        ];
        yield 'a go_to on port 65536' => [
            [self::scenario([self::goTo('http://localhost:65536/cart')], ['start_url' => 'http://localhost:65535/orders'])],
            'Scenario 1, step 1: url must be a full http or https address, like http://localhost:8000/orders.',
            [self::scenario([self::goTo('http://localhost:65535/cart')], ['start_url' => 'http://localhost:65535/orders'])],
        ];
        yield 'a go_to on port 0' => [
            [self::scenario([self::goTo('http://localhost:00000/cart')], ['start_url' => 'http://localhost:1/orders'])],
            'Scenario 1, step 1: url must be a full http or https address, like http://localhost:8000/orders.',
            [self::scenario([self::goTo('http://localhost:00001/cart')], ['start_url' => 'http://localhost:1/orders'])],
        ];
        yield 'a file: start_url' => [
            [self::scenario([self::click()], ['start_url' => 'file:///etc/passwd'])],
            'Scenario 1: start_url must be a full http or https address, like http://localhost:8000/orders.',
            [self::scenario([self::click()], ['start_url' => 'http://127.0.0.1/'])],
        ];
    }

    #[Test]
    #[DataProvider('rulesWithTheirValidTwin')]
    public function aScenarioBreakingARuleIsRefusedWithOnePlainErrorAndItsTwinIsKept(mixed $refused, string $expectedReason, mixed $twin): void
    {
        try {
            ScenarioRules::parse($refused);
            self::fail('The scenarios were kept.');
        } catch (ScenarioRefused $refusal) {
            self::assertSame($expectedReason, $refusal->reason);
            self::assertSame($expectedReason, $refusal->getMessage());
        }

        self::assertIsArray($twin);
        self::assertCount(\count($twin), ScenarioRules::parse($twin)->all());
    }

    #[Test]
    public function aRefusalNamesTheFirstBrokenRuleAndNeverRepeatsWhatWasSent(): void
    {
        $sent = [
            self::scenario([self::click()]),
            self::scenario([self::click(), self::click(['css' => "\e[31m<script>"]), ['type' => "\e]0;x", 'say' => 'x']]),
        ];

        try {
            ScenarioRules::parse($sent);
            self::fail('The scenarios were kept.');
        } catch (ScenarioRefused $refusal) {
            self::assertSame('Scenario 2, step 2: find the element by role and name, by visible text or by label, never by a CSS or XPath string.', $refusal->reason);
        }
    }

    #[Test]
    public function theSchemaFileListsTheSameStepTypesFieldsCapsAndLocatorKindsAsTheRules(): void
    {
        $schema = json_decode((string) file_get_contents(self::SCHEMA_FILE), true, 512, \JSON_THROW_ON_ERROR);

        self::assertSame('https://json-schema.org/draft/2020-12/schema', self::node($schema, '$schema'));
        self::assertSame(ScenarioRules::SCENARIO_FIELDS, self::node($schema, 'required'));
        self::assertSame(ScenarioRules::SCENARIO_FIELDS, array_keys(self::arrayAt($schema, 'properties')));
        self::assertFalse(self::node($schema, 'additionalProperties'));
        self::assertSame(ScenarioRules::TITLE_MAX_LENGTH, self::node($schema, 'properties', 'title', 'maxLength'));
        self::assertSame(ScenarioRules::WHY_MAX_LENGTH, self::node($schema, 'properties', 'why', 'maxLength'));
        self::assertSame(ScenarioRules::SETUP_MAX_LENGTH, self::node($schema, 'properties', 'setup', 'maxLength'));
        self::assertSame(1, self::node($schema, 'properties', 'steps', 'minItems'));
        self::assertSame(ScenarioRules::MAX_STEPS, self::node($schema, 'properties', 'steps', 'maxItems'));
        self::assertSame(ScenarioRules::MAX_SCENARIOS, self::node($schema, '$defs', 'scenarios', 'maxItems'));
        self::assertSame('#', self::node($schema, '$defs', 'scenarios', 'items', '$ref'));
        self::assertSame(ScenarioRules::SAY_MAX_LENGTH, self::node($schema, '$defs', 'say', 'maxLength'));
        foreach (['url', 'textValue', 'filledTextValue'] as $textDefinition) {
            self::assertSame(ScenarioRules::TEXT_VALUE_MAX_LENGTH, self::node($schema, '$defs', $textDefinition, 'maxLength'), $textDefinition);
        }
        self::assertSame(ScenarioRules::URL_PATTERN, self::node($schema, '$defs', 'url', 'pattern'));
        self::assertSame('#/$defs/url', self::node($schema, 'properties', 'start_url', '$ref'));
        // The fields the rules refuse when blank carry "\S"; setup and a typed text may be empty.
        foreach ([['properties', 'title'], ['properties', 'why'], ['$defs', 'say'], ['$defs', 'filledTextValue']] as $path) {
            self::assertSame('\S', self::node($schema, ...[...$path, 'pattern']), implode('/', $path));
        }
        self::assertArrayNotHasKey('pattern', self::arrayAt($schema, 'properties', 'setup'));
        self::assertArrayNotHasKey('pattern', self::arrayAt($schema, '$defs', 'textValue'));
        // Every text the rules check for a NUL refuses one in the schema too; url and role patterns leave none out.
        foreach ([['properties', 'title'], ['properties', 'why'], ['properties', 'setup'], ['$defs', 'say'], ['$defs', 'textValue'], ['$defs', 'filledTextValue']] as $path) {
            self::assertSame(['pattern' => '\x00'], self::node($schema, ...[...$path, 'not']), implode('/', $path));
        }
        self::assertSame('#/$defs/step', self::node($schema, 'properties', 'steps', 'items', '$ref'));

        $stepDefinitions = self::listAt($schema, '$defs', 'step', 'oneOf');
        self::assertSame(array_keys(ScenarioRules::STEP_FIELDS), array_map(static fn (mixed $step): mixed => self::node($step, 'properties', 'type', 'const'), $stepDefinitions));
        foreach ($stepDefinitions as $step) {
            $type = self::node($step, 'properties', 'type', 'const');
            self::assertIsString($type);
            $fields = ScenarioRules::STEP_FIELDS[$type];
            self::assertFalse(self::node($step, 'additionalProperties'), $type);
            self::assertEqualsCanonicalizing(['type', 'say', ...array_keys(array_filter($fields))], self::node($step, 'required'), $type);
            self::assertEqualsCanonicalizing(['type', 'say', ...array_keys($fields)], array_keys(self::arrayAt($step, 'properties')), $type);
            $stepFieldReferences = array_map(static fn (mixed $field): mixed => self::node($field, '$ref'), array_diff_key(self::arrayAt($step, 'properties'), ['type' => true]));
            self::assertSame('#/$defs/say', $stepFieldReferences['say'], $type);
            foreach (['target' => '#/$defs/target', 'url' => '#/$defs/url', 'option' => '#/$defs/filledTextValue'] as $field => $reference) {
                if (isset($stepFieldReferences[$field])) {
                    self::assertSame($reference, $stepFieldReferences[$field], $type);
                }
            }
            if (isset($stepFieldReferences['text'])) {
                self::assertSame('type' === $type ? '#/$defs/textValue' : '#/$defs/filledTextValue', $stepFieldReferences['text'], $type);
            }
        }

        $targetDefinitions = self::listAt($schema, '$defs', 'target', 'oneOf');
        self::assertSame(ScenarioRules::LOCATOR_KINDS, array_map(static fn (mixed $target): mixed => self::node($target, 'required', '0'), $targetDefinitions));
        foreach ($targetDefinitions as $target) {
            self::assertFalse(self::node($target, 'additionalProperties'));
        }
        self::assertSame(['role', 'name'], array_keys(self::arrayAt($targetDefinitions[0], 'properties')));
        self::assertSame('#/$defs/filledTextValue', self::node($targetDefinitions[0], 'properties', 'name', '$ref'));
        self::assertSame('#/$defs/filledTextValue', self::node($targetDefinitions[1], 'properties', 'text', '$ref'));
        self::assertSame('#/$defs/filledTextValue', self::node($targetDefinitions[2], 'properties', 'label', '$ref'));
        self::assertSame(ScenarioRules::ROLE_PATTERN, self::node($targetDefinitions[0], 'properties', 'role', 'pattern'));
        self::assertSame(ScenarioRules::TEXT_VALUE_MAX_LENGTH, self::node($targetDefinitions[0], 'properties', 'role', 'maxLength'));
        self::assertSame(['text'], array_keys(self::arrayAt($targetDefinitions[1], 'properties')));
        self::assertSame(['label'], array_keys(self::arrayAt($targetDefinitions[2], 'properties')));
    }

    /**
     * @param array<mixed>         $steps
     * @param array<string, mixed> $fields
     *
     * @return array<string, mixed>
     */
    private static function scenario(array $steps, array $fields = []): array
    {
        return array_merge(
            ['title' => 'The stock warning shows', 'why' => 'OrderLine::warn() runs when stock is 0.', 'setup' => 'One SHOP product with no stock.', 'start_url' => self::START_URL, 'steps' => $steps],
            $fields,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function click(mixed $target = ['role' => 'button', 'name' => 'Save']): array
    {
        return ['type' => 'click', 'say' => 'Save the order.', 'target' => $target];
    }

    /**
     * @return array<string, string>
     */
    private static function goTo(string $url): array
    {
        return ['type' => 'go_to', 'say' => 'Open the page.', 'url' => $url];
    }

    /**
     * @param array<string, mixed> $scenario
     */
    private static function json(array $scenario): string
    {
        return json_encode($scenario, \JSON_THROW_ON_ERROR);
    }

    private static function urlOfLength(int $length): string
    {
        return 'http://localhost:8000/'.str_repeat('a', $length - 22);
    }

    private static function node(mixed $schema, string ...$path): mixed
    {
        foreach ($path as $key) {
            self::assertIsArray($schema);
            $key = ctype_digit($key) ? (int) $key : $key;
            self::assertArrayHasKey($key, $schema);
            $schema = $schema[$key];
        }

        return $schema;
    }

    /**
     * @return array<mixed>
     */
    private static function arrayAt(mixed $schema, string ...$path): array
    {
        $node = self::node($schema, ...$path);
        self::assertIsArray($node);

        return $node;
    }

    /**
     * @return list<mixed>
     */
    private static function listAt(mixed $schema, string ...$path): array
    {
        $node = self::arrayAt($schema, ...$path);
        self::assertTrue(array_is_list($node));
        self::assertNotEmpty($node);

        return $node;
    }
}
