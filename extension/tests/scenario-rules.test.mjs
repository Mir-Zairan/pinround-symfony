import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { test } from 'node:test';
import {
    LOCATOR_KINDS, MAX_SCENARIOS, MAX_STEPS, ROLE_PATTERN, SAY_MAX_LENGTH, SCENARIO_FIELDS, SETUP_MAX_LENGTH, STEP_FIELDS,
    TEXT_VALUE_MAX_LENGTH, TITLE_MAX_LENGTH, URL_PATTERN, WHY_MAX_LENGTH, checkScenarios,
} from '../src/player/scenario-rules.js';

const SCHEMA = JSON.parse(await readFile(new URL('../scenario.v1.json', import.meta.url), 'utf8'));

const START_URL = 'http://shop.acme-foods.test:8000/basket';
const STEP_TYPES = ['go_to', 'click', 'type', 'select', 'wait_for', 'expect_text', 'expect_visible', 'pause', 'capture'];
const PAY_BUTTON = { role: 'button', name: 'Pay 12.40 EUR' };
const EMOJI = '\u{1F353}';

/** One step of each type, in ADR-0046's order. */
function everyStep() {
    return [
        { type: 'go_to', say: 'Open the basket.', url: 'http://shop.acme-foods.test:8000/basket?coupon=SHOP#top' },
        { type: 'click', say: 'Press pay.', target: PAY_BUTTON },
        { type: 'type', say: 'Type the email.', target: { label: 'Email for the receipt' }, text: 'mia@acme-foods.test' },
        { type: 'select', say: 'Choose the day.', target: { label: 'Delivery day' }, option: 'Tuesday' },
        { type: 'wait_for', say: 'Wait for the stock check.', target: { text: 'In stock' } },
        { type: 'expect_text', say: 'The total shows.', text: '12.40 EUR' },
        { type: 'expect_visible', say: 'The thank-you shows.', target: { role: 'status' } },
        { type: 'pause', say: 'Look at the receipt.' },
        { type: 'capture', say: 'Keep a picture.' },
    ];
}

function aScenario(fields = {}) {
    return { title: 'A paid basket', why: 'BasketTotal adds the coupon after tax.', setup: 'Omar has two jars in the basket.', start_url: START_URL, steps: everyStep(), ...fields };
}

const withStep = (step) => [aScenario({ steps: [step] })];
const clickStep = (target) => ({ type: 'click', say: 'Press it.', target });
const refusalOf = (value) => checkScenarios(value).refusal;

function assertPasses(value, message) {
    const checked = checkScenarios(value);
    assert.equal(checked.refusal, undefined, message);
    assert.ok(Array.isArray(checked.scenarios), message);
}

test('a scenario with a step of every type passes, as new frozen objects with only the known fields', () => {
    const given = [aScenario()];

    const { scenarios } = checkScenarios(given);

    assert.deepEqual(scenarios, given);
    assert.notEqual(scenarios[0], given[0]);
    assert.ok(Object.isFrozen(scenarios) && Object.isFrozen(scenarios[0]) && Object.isFrozen(scenarios[0].steps[1].target));
    assert.deepEqual(scenarios[0].steps.map((step) => step.type), STEP_TYPES);
});

test('the scenarios must be a list; an empty list passes', () => {
    for (const value of [{}, { 0: aScenario() }, null, undefined, 'scenarios', 3]) {
        assert.equal(refusalOf(value), 'Send the scenarios as a list.', String(value));
    }
    assert.deepEqual(checkScenarios([]), { scenarios: [] });
});

test('a notebook holds at most 20 scenarios: 20 pass, 21 are refused', () => {
    assert.equal(MAX_SCENARIOS, 20);
    assertPasses(Array.from({ length: 20 }, () => aScenario()));
    assert.equal(refusalOf(Array.from({ length: 21 }, () => aScenario())), 'A notebook holds at most 20 scenarios.');
});

test('a scenario has exactly its five fields: one missing, one more or no object is refused, naming the scenario', () => {
    const { setup, ...withoutSetup } = aScenario();
    assert.equal(setup.length > 0, true);
    // JSON.parse keeps "__proto__" as a field of its own, as pinround's answer would carry it.
    const withProtoField = JSON.parse(`{"__proto__": {"title": "x"}, ${JSON.stringify(aScenario()).slice(1)}`);
    assert.ok(Object.hasOwn(withProtoField, '__proto__'));
    for (const scenario of [withoutSetup, { ...aScenario(), author: 'Lena' }, withProtoField, [], null, 'A paid basket']) {
        assert.equal(refusalOf([aScenario(), scenario]), 'Scenario 2: a scenario has exactly title, why, setup, start_url and steps.');
    }
    assertPasses([aScenario(), aScenario()]);
});

test('title, why, setup, say and every text must be text, refused by name and place', () => {
    assert.equal(refusalOf([aScenario({ title: 7 })]), 'Scenario 1: title must be text.');
    assert.equal(refusalOf([aScenario({ why: null })]), 'Scenario 1: why must be text.');
    assert.equal(refusalOf([aScenario({ setup: ['x'] })]), 'Scenario 1: setup must be text.');
    assert.equal(refusalOf([aScenario({ start_url: { href: START_URL } })]), 'Scenario 1: start_url must be text.');
    assert.equal(refusalOf(withStep({ type: 'pause', say: 3 })), 'Scenario 1, step 1: say must be text.');
    assert.equal(refusalOf(withStep({ type: 'type', say: 'Type.', target: { label: 'Email' }, text: 5 })), 'Scenario 1, step 1: text must be text.');
    assert.equal(refusalOf(withStep({ type: 'select', say: 'Choose.', target: { label: 'Day' }, option: true })), 'Scenario 1, step 1: option must be text.');
    assert.equal(refusalOf(withStep(clickStep({ role: 'button', name: 1 }))), 'Scenario 1, step 1: target.name must be text.');
    assert.equal(refusalOf(withStep(clickStep({ role: 2 }))), 'Scenario 1, step 1: target.role must be text.');
});

test('a lone surrogate, which is no UTF-8, is refused; a whole emoji passes', () => {
    assert.equal(refusalOf([aScenario({ title: 'Jam \uD83C' })]), 'Scenario 1: title is not valid UTF-8.');
    assert.equal(refusalOf(withStep(clickStep({ text: '\uDF53 jam' }))), 'Scenario 1, step 1: target.text is not valid UTF-8.');
    assertPasses([aScenario({ title: `Jam ${EMOJI}` })]);
});

test('a NUL character is refused in any text, also where blank text is allowed', () => {
    assert.equal(refusalOf([aScenario({ setup: '\u0000' })]), 'Scenario 1: setup must not hold a NUL character.');
    assert.equal(refusalOf(withStep({ type: 'type', say: 'Type.', target: { label: 'Email' }, text: 'a\u0000b' })), 'Scenario 1, step 1: text must not hold a NUL character.');
    assertPasses([aScenario({ setup: '\u0001' })]);
});

test('each length cap counts characters, so an emoji is one and the cap itself passes', () => {
    const caps = [
        ['title', TITLE_MAX_LENGTH, 120, (text) => [aScenario({ title: text })], 'Scenario 1: keep title to 120 characters or fewer.'],
        ['why', WHY_MAX_LENGTH, 600, (text) => [aScenario({ why: text })], 'Scenario 1: keep why to 600 characters or fewer.'],
        ['setup', SETUP_MAX_LENGTH, 600, (text) => [aScenario({ setup: text })], 'Scenario 1: keep setup to 600 characters or fewer.'],
        ['say', SAY_MAX_LENGTH, 200, (text) => withStep({ type: 'pause', say: text }), 'Scenario 1, step 1: keep say to 200 characters or fewer.'],
        ['text', TEXT_VALUE_MAX_LENGTH, 1000, (text) => withStep({ type: 'expect_text', say: 'See it.', text }), 'Scenario 1, step 1: keep text to 1,000 characters or fewer.'],
        ['option', TEXT_VALUE_MAX_LENGTH, 1000, (text) => withStep({ type: 'select', say: 'Pick.', target: { label: 'Day' }, option: text }), 'Scenario 1, step 1: keep option to 1,000 characters or fewer.'],
        ['target.text', TEXT_VALUE_MAX_LENGTH, 1000, (text) => withStep(clickStep({ text })), 'Scenario 1, step 1: keep target.text to 1,000 characters or fewer.'],
        ['target.label', TEXT_VALUE_MAX_LENGTH, 1000, (text) => withStep(clickStep({ label: text })), 'Scenario 1, step 1: keep target.label to 1,000 characters or fewer.'],
        ['target.name', TEXT_VALUE_MAX_LENGTH, 1000, (text) => withStep(clickStep({ role: 'button', name: text })), 'Scenario 1, step 1: keep target.name to 1,000 characters or fewer.'],
    ];
    for (const [field, cap, expectedCap, valueWith, refusal] of caps) {
        assert.equal(cap, expectedCap, field);
        // Twice the cap in UTF-16 units, but the cap in characters.
        assertPasses(valueWith(EMOJI.repeat(cap)), field);
        assert.equal(refusalOf(valueWith(EMOJI.repeat(cap + 1))), refusal, field);
        assert.equal(refusalOf(valueWith('a'.repeat(cap + 1))), refusal, field);
    }
});

test('an over-long url or role is refused by its cap before its shape', () => {
    const longUrl = `${START_URL}?q=${'a'.repeat(1001 - START_URL.length - 3)}`;
    assert.equal([...longUrl].length, 1001);
    assert.equal(refusalOf([aScenario({ start_url: longUrl })]), 'Scenario 1: keep start_url to 1,000 characters or fewer.');
    assertPasses([aScenario({ start_url: longUrl.slice(0, -1), steps: [{ type: 'pause', say: 'Look.' }] })]);
    assert.equal(refusalOf(withStep(clickStep({ role: 'a'.repeat(1001) }))), 'Scenario 1, step 1: keep target.role to 1,000 characters or fewer.');
});

test('title, why, say, option and the target texts must not be blank, counting a byte order mark and a no-break space as blank', () => {
    for (const blank of ['', ' ', '\t\r\n', '\u{FEFF}', '\u{A0}\u{2003}', '\u{2028}']) {
        assert.equal(refusalOf([aScenario({ title: blank })]), 'Scenario 1: title must not be blank.', JSON.stringify(blank));
        assert.equal(refusalOf([aScenario({ why: blank })]), 'Scenario 1: why must not be blank.');
        assert.equal(refusalOf(withStep({ type: 'pause', say: blank })), 'Scenario 1, step 1: say must not be blank.');
        assert.equal(refusalOf(withStep({ type: 'select', say: 'Pick.', target: { label: 'Day' }, option: blank })), 'Scenario 1, step 1: option must not be blank.');
        assert.equal(refusalOf(withStep({ type: 'expect_text', say: 'See.', text: blank })), 'Scenario 1, step 1: text must not be blank.');
        assert.equal(refusalOf(withStep(clickStep({ text: blank }))), 'Scenario 1, step 1: target.text must not be blank.');
        assert.equal(refusalOf(withStep(clickStep({ label: blank }))), 'Scenario 1, step 1: target.label must not be blank.');
        assert.equal(refusalOf(withStep(clickStep({ role: 'button', name: blank }))), 'Scenario 1, step 1: target.name must not be blank.');
    }
    assertPasses([aScenario({ title: '\u{FEFF}x' })]);
});

test('setup and the text a type step types may be blank: typing nothing clears a field', () => {
    assertPasses([aScenario({ setup: '' })]);
    assertPasses(withStep({ type: 'type', say: 'Clear the email.', target: { label: 'Email' }, text: '' }));
    assertPasses(withStep({ type: 'type', say: 'Type spaces.', target: { label: 'Email' }, text: ' \r\n' }));
});

test('start_url must be a full http or https address with no user name, back slash, white space or control character', () => {
    const refused = [
        'shop.acme-foods.test/basket',
        'ftp://shop.acme-foods.test/',
        'HTTP://shop.acme-foods.test/',
        'javascript:alert(1)',
        'http://lena@shop.acme-foods.test/',
        'http://shop.acme-foods.test\\@evil.test/',
        'http://shop.acme-foods.test/a b',
        'http://shop.acme-foods.test/\u{A0}',
        'http://shop.acme-foods.test/\u{FEFF}',
        'http://shop.acme-foods.test/\u001B[31m',
        'http://shop.acme-foods.test/\u007F',
        'http://shop.acme-foods.test/basket\n',
        'http://shop.acme-foods.test/basket\r\n',
        'http://shop.acme-foods.test:/',
        'http://shop.acme-foods.test:123456/',
        'http://[::1/',
        'http://shop.acme-foods.test?x\u{2028}',
    ];
    for (const url of refused) {
        assert.equal(refusalOf([aScenario({ start_url: url, steps: [{ type: 'pause', say: 'Look.' }] })]), 'Scenario 1: start_url must be a full http or https address, like http://localhost:8000/orders.', JSON.stringify(url));
    }
    for (const url of ['https://shop.acme-foods.test', 'http://localhost:8000/orders?day=Tuesday#list', 'http://[::1]:8080/', 'http://127.0.0.1/', `http://shop.acme-foods.test/${EMOJI}`]) {
        assertPasses([aScenario({ start_url: url, steps: [{ type: 'pause', say: 'Look.' }] })], url);
    }
});

test('a port is 1 to 65535', () => {
    const scenarioOn = (url) => [aScenario({ start_url: url, steps: [{ type: 'pause', say: 'Look.' }] })];
    for (const url of ['http://localhost:0/', 'http://localhost:00000/', 'http://localhost:65536/', 'http://localhost:99999/']) {
        assert.equal(refusalOf(scenarioOn(url)), 'Scenario 1: start_url must be a full http or https address, like http://localhost:8000/orders.', url);
    }
    for (const url of ['http://localhost:1/', 'http://localhost:65535/', 'http://localhost:00080/']) {
        assertPasses(scenarioOn(url), url);
    }
    assert.equal(refusalOf(withStep({ type: 'go_to', say: 'Go.', url: 'http://shop.acme-foods.test:65536/' })), 'Scenario 1, step 1: url must be a full http or https address, like http://localhost:8000/orders.');
});

test('go_to stays on the scheme, host and port of start_url, with the default ports filled in and the host in any case', () => {
    const goTo = (startUrl, url) => [aScenario({ start_url: startUrl, steps: [{ type: 'pause', say: 'Look.' }, { type: 'go_to', say: 'Go.', url }] })];
    const refusal = 'Scenario 1, step 2: go_to must stay on the scheme, host and port of start_url.';
    for (const [startUrl, url] of [
        ['http://shop.acme-foods.test/', 'http://pinround.acme-foods.test/'],
        ['http://shop.acme-foods.test/', 'https://shop.acme-foods.test/'],
        ['http://shop.acme-foods.test:8000/', 'http://shop.acme-foods.test:8001/'],
        ['http://shop.acme-foods.test:8000/', 'http://shop.acme-foods.test/'],
        ['http://shop.acme-foods.test/', 'http://shop.acme-foods.test.evil.test/'],
        ['https://shop.acme-foods.test/', 'https://shop.acme-foods.test:80/'],
    ]) {
        assert.equal(refusalOf(goTo(startUrl, url)), refusal, `${startUrl} → ${url}`);
    }
    for (const [startUrl, url] of [
        ['http://shop.acme-foods.test/', 'http://shop.acme-foods.test:80/orders'],
        ['https://shop.acme-foods.test:443/', 'https://shop.acme-foods.test/orders'],
        ['http://Shop.Acme-Foods.test/', 'http://shop.acme-foods.TEST/orders'],
        ['http://shop.acme-foods.test:8000/', 'http://shop.acme-foods.test:8000?day=Monday'],
    ]) {
        assertPasses(goTo(startUrl, url), `${startUrl} → ${url}`);
    }
    assert.equal(refusalOf(goTo(START_URL, '/orders')), 'Scenario 1, step 2: url must be a full http or https address, like http://localhost:8000/orders.');
});

test('steps must be a list of 1 to 30', () => {
    assert.equal(MAX_STEPS, 30);
    const pause = { type: 'pause', say: 'Look.' };
    assert.equal(refusalOf([aScenario({ steps: { 0: pause } })]), 'Scenario 1: steps must be a list.');
    assert.equal(refusalOf([aScenario({ steps: [] })]), 'Scenario 1: add at least one step.');
    assert.equal(refusalOf([aScenario({ steps: Array.from({ length: 31 }, () => pause) })]), 'Scenario 1: a scenario has at most 30 steps.');
    assertPasses([aScenario({ steps: Array.from({ length: 30 }, () => pause) })]);
});

test('a step must be an object with a known type', () => {
    const types = 'Scenario 1, step 2: type must be one of go_to, click, type, select, wait_for, expect_text, expect_visible, pause, capture.';
    const pause = { type: 'pause', say: 'Look.' };
    for (const step of [{ type: 'run_script', say: 'Run.' }, { type: 'CLICK', say: 'Press.', target: PAY_BUTTON }, { type: 'toString', say: 'x' }, { type: '__proto__', say: 'x' }, { say: 'Look.' }, { type: 3, say: 'x' }]) {
        assert.equal(refusalOf([aScenario({ steps: [pause, step] })]), types, JSON.stringify(step));
    }
    for (const step of [null, 'pause', ['pause']]) {
        assert.equal(refusalOf([aScenario({ steps: [pause, step] })]), 'Scenario 1, step 2: write the step as an object with type and say.');
    }
    assertPasses([aScenario({ steps: [pause, { type: 'capture', say: 'Keep it.' }] })]);
});

test('each step type takes exactly its fields: one missing or one more is refused, naming them', () => {
    const takes = {
        go_to: 'type, say and url',
        click: 'type, say and target',
        type: 'type, say, target and text',
        select: 'type, say, target and option',
        wait_for: 'type, say and target',
        expect_text: 'type, say and text, and may take target',
        expect_visible: 'type, say and target',
        pause: 'type and say',
        capture: 'type and say',
    };
    const steps = everyStep();
    assert.deepEqual(steps.map((step) => step.type), Object.keys(takes));
    for (const step of steps) {
        const refusal = `Scenario 1, step 1: a step of type ${step.type} takes ${takes[step.type]}.`;
        assertPasses(withStep(step), step.type);
        assert.equal(refusalOf(withStep({ ...step, selector: '#pay' })), refusal, step.type);
        for (const field of Object.keys(step)) {
            if ('type' !== field) {
                const { [field]: _, ...without } = step;
                assert.equal(refusalOf(withStep(without)), refusal, `${step.type} without ${field}`);
            }
        }
    }
    assert.equal(refusalOf(withStep({ type: 'pause', say: 'Look.', target: PAY_BUTTON })), 'Scenario 1, step 1: a step of type pause takes type and say.');
    assertPasses(withStep({ type: 'expect_text', say: 'See it in the total.', text: '12.40', target: { text: 'Total' } }));
});

test('a target is exactly one of role with an optional name, text or label, never a CSS or XPath string', () => {
    const refusal = 'Scenario 1, step 1: find the element by role and name, by visible text or by label, never by a CSS or XPath string.';
    for (const target of [
        { css: '#pay' },
        { selector: 'button.pay' },
        { xpath: '//button' },
        { role: 'button', css: '#pay' },
        { role: 'button', text: 'Pay' },
        { text: 'Pay', label: 'Pay' },
        { text: 'Pay', name: 'Pay' },
        { label: 'Email', name: 'Email' },
        { name: 'Pay' },
        {},
        '#pay',
        ['button'],
        null,
    ]) {
        assert.equal(refusalOf(withStep(clickStep(target))), refusal, JSON.stringify(target));
    }
    for (const target of [{ role: 'button' }, { role: 'button', name: 'Pay' }, { text: 'Pay' }, { label: 'Email' }]) {
        assertPasses(withStep(clickStep(target)), JSON.stringify(target));
    }
});

test('a role is an ARIA role in lowercase', () => {
    const refusal = 'Scenario 1, step 1: target.role must be an ARIA role in lowercase, like button or alert.';
    for (const role of ['Button', 'button ', 'button\n', 'menu item', '-menuitem', 'menu--item', 'button;', '[role=button]', 'h1']) {
        assert.equal(refusalOf(withStep(clickStep({ role }))), refusal, JSON.stringify(role));
    }
    for (const role of ['button', 'alert', 'menuitem-checkbox']) {
        assertPasses(withStep(clickStep({ role })), role);
    }
});

test('the first broken rule is named in scenario order, then field order', () => {
    assert.equal(refusalOf([aScenario(), aScenario({ title: '', why: '' }), aScenario({ title: '' })]), 'Scenario 2: title must not be blank.');
    assert.equal(refusalOf([aScenario({ steps: [{ type: 'pause', say: '' }, { type: 'nope', say: 'x' }] })]), 'Scenario 1, step 1: say must not be blank.');
});

// The copy in extension/ is what this code must agree with, and scenario-copy.test.mjs keeps it equal to docs/api/.
test('the caps, step types, target kinds, fields and patterns agree with extension/scenario.v1.json', () => {
    const { $defs: defs, properties } = SCHEMA;
    assert.deepEqual(SCHEMA.required, [...SCENARIO_FIELDS]);
    assert.deepEqual(Object.keys(properties), [...SCENARIO_FIELDS]);
    assert.equal(properties.title.maxLength, TITLE_MAX_LENGTH);
    assert.equal(properties.why.maxLength, WHY_MAX_LENGTH);
    assert.equal(properties.setup.maxLength, SETUP_MAX_LENGTH);
    assert.equal(properties.steps.maxItems, MAX_STEPS);
    assert.equal(properties.steps.minItems, 1);
    assert.equal(defs.scenarios.maxItems, MAX_SCENARIOS);
    assert.equal(defs.say.maxLength, SAY_MAX_LENGTH);
    for (const name of ['url', 'textValue', 'filledTextValue']) {
        assert.equal(defs[name].maxLength, TEXT_VALUE_MAX_LENGTH, name);
    }
    assert.equal(defs.url.pattern, URL_PATTERN);

    const stepVariants = defs.step.oneOf;
    assert.ok(stepVariants.length > 0);
    assert.deepEqual(stepVariants.map((variant) => variant.properties.type.const), Object.keys(STEP_FIELDS));
    for (const variant of stepVariants) {
        const fields = STEP_FIELDS[variant.properties.type.const];
        const required = Object.keys(fields).filter((field) => fields[field]);
        assert.deepEqual(new Set(variant.required), new Set(['type', 'say', ...required]), variant.properties.type.const);
        assert.deepEqual(new Set(Object.keys(variant.properties)), new Set(['type', 'say', ...Object.keys(fields)]), variant.properties.type.const);
        assert.equal(variant.additionalProperties, false);
    }

    const targetVariants = defs.target.oneOf;
    assert.deepEqual(targetVariants.map((variant) => variant.required), LOCATOR_KINDS.map((kind) => [kind]));
    const byRole = targetVariants.find((variant) => variant.required[0] === 'role');
    assert.equal(byRole.properties.role.pattern, ROLE_PATTERN);
    assert.equal(byRole.properties.role.maxLength, TEXT_VALUE_MAX_LENGTH);
    assert.deepEqual(Object.keys(byRole.properties), ['role', 'name']);
    for (const variant of targetVariants) {
        assert.equal(variant.additionalProperties, false);
    }
});

test('the texts that may be blank are the same here and in extension/scenario.v1.json: setup and the text a type step types', () => {
    const { $defs: defs, properties } = SCHEMA;
    const stepProperties = (type) => defs.step.oneOf.find((variant) => variant.properties.type.const === type).properties;
    const targetProperties = (kind) => defs.target.oneOf.find((variant) => variant.required[0] === kind).properties;
    const resolved = (schema) => (undefined === schema.$ref ? schema : defs[schema.$ref.replace('#/$defs/', '')]);
    const typeStep = (text) => ({ type: 'type', say: 'Type the coupon.', target: { label: 'Coupon code' }, text });
    const texts = [
        ['title', properties.title, (blank) => [aScenario({ title: blank })]],
        ['why', properties.why, (blank) => [aScenario({ why: blank })]],
        ['setup', properties.setup, (blank) => [aScenario({ setup: blank })]],
        ['say', stepProperties('click').say, (blank) => withStep({ ...clickStep(PAY_BUTTON), say: blank })],
        ['type text', stepProperties('type').text, (blank) => withStep(typeStep(blank))],
        ['select option', stepProperties('select').option, (blank) => withStep({ type: 'select', say: 'Choose.', target: { label: 'Delivery day' }, option: blank })],
        ['expect_text text', stepProperties('expect_text').text, (blank) => withStep({ type: 'expect_text', say: 'It shows.', text: blank })],
        ['target.name', targetProperties('role').name, (blank) => withStep(clickStep({ role: 'button', name: blank }))],
        ['target.text', targetProperties('text').text, (blank) => withStep(clickStep({ text: blank }))],
        ['target.label', targetProperties('label').label, (blank) => withStep(clickStep({ label: blank }))],
    ];
    const isFilled = (schema) => '\\S' === resolved(schema).pattern;

    assert.deepEqual(texts.filter(([, schema]) => !isFilled(schema)).map(([field]) => field), ['setup', 'type text']);
    assert.equal(stepProperties('type').text.$ref, '#/$defs/textValue');
    for (const [, schema] of texts.slice(5)) {
        assert.equal(schema.$ref, '#/$defs/filledTextValue');
    }
    for (const [field, schema, withBlank] of texts) {
        for (const blank of ['', ' \u{A0}\r\n', '\u{FEFF}']) {
            assert.equal(refusalOf(withBlank(blank)) === undefined, !isFilled(schema), `${field} ${JSON.stringify(blank)}`);
        }
    }
});
