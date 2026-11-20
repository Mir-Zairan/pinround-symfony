// The scenario format of ADR-0046 as the extension checks it again before it plays anything: the scenarios come from
// pinround, and the extension does not trust data from outside it. The same rules and messages as the server's
// ScenarioRules, which extension/scenario.v1.json (a copy of docs/api/scenario.v1.json) also states; a test keeps the
// caps, step types and patterns here in step with that copy. Pure: no DOM and no browser API.

export const MAX_SCENARIOS = 20;
export const MAX_STEPS = 30;
export const TITLE_MAX_LENGTH = 120;
export const WHY_MAX_LENGTH = 600;
export const SETUP_MAX_LENGTH = 600;
export const SAY_MAX_LENGTH = 200;
export const TEXT_VALUE_MAX_LENGTH = 1000;
const MAX_PORT = 65535;

/** The step types in ADR-0046's order, each with its fields besides type and say: true when the field is required. */
export const STEP_FIELDS = Object.freeze({
    go_to: Object.freeze({ url: true }),
    click: Object.freeze({ target: true }),
    type: Object.freeze({ target: true, text: true }),
    select: Object.freeze({ target: true, option: true }),
    wait_for: Object.freeze({ target: true }),
    expect_text: Object.freeze({ text: true, target: false }),
    expect_visible: Object.freeze({ target: true }),
    pause: Object.freeze({}),
    capture: Object.freeze({}),
});

/** The keys of a target, one of which it uses; name goes only with role. */
export const LOCATOR_KINDS = Object.freeze(['role', 'text', 'label']);

export const SCENARIO_FIELDS = Object.freeze(['title', 'why', 'setup', 'start_url', 'steps']);

// The schema's very strings; the byte order mark is written as itself, as the schema holds it.
export const URL_PATTERN = '^(https?)://([A-Za-z0-9.-]+|\\[[0-9A-Fa-f:.]+\\])(?::([0-9]{1,5}))?(?:[/?#][^\\s\\\\\\x00-\\x1f\\x7f\u{FEFF}]*)?$';
export const ROLE_PATTERN = '^[a-z]+(?:-[a-z]+)*$';

// Without the m flag, ECMAScript's $ is the true end, so a trailing newline is refused.
const URL_SHAPE = new RegExp(URL_PATTERN, 'u');
const ROLE_SHAPE = new RegExp(ROLE_PATTERN, 'u');
// ECMAScript's \s holds Unicode white space and the byte order mark, as the schema's "\S" reads them.
const NOT_BLANK = /\S/u;

export const REFUSALS = Object.freeze({
    notAList: 'Send the scenarios as a list.',
    tooManyScenarios: 'A notebook holds at most 20 scenarios.',
    scenarioFields: 'a scenario has exactly title, why, setup, start_url and steps.',
    stepsNotAList: 'steps must be a list.',
    noSteps: 'add at least one step.',
    tooManySteps: 'a scenario has at most 30 steps.',
    stepNotAnObject: 'write the step as an object with type and say.',
    stepType: (types) => `type must be one of ${types}.`,
    stepFields: (type, fields) => `a step of type ${type} takes ${fields}.`,
    notText: (field) => `${field} must be text.`,
    notUtf8: (field) => `${field} is not valid UTF-8.`,
    nul: (field) => `${field} must not hold a NUL character.`,
    blank: (field) => `${field} must not be blank.`,
    tooLong: (field, maxLength) => `keep ${field} to ${maxLength.toLocaleString('en-US')} characters or fewer.`,
    startUrl: 'start_url must be a full http or https address, like http://localhost:8000/orders.',
    goToUrl: 'url must be a full http or https address, like http://localhost:8000/orders.',
    goToOtherHost: 'go_to must stay on the scheme, host and port of start_url.',
    target: 'find the element by role and name, by visible text or by label, never by a CSS or XPath string.',
    role: 'target.role must be an ARIA role in lowercase, like button or alert.',
});

class ScenarioRefusal extends Error {}

const refusalAt = (place, reason) => new ScenarioRefusal(`${place}: ${reason}`);

/**
 * The scenarios as the player may play them, or the first rule they break, checking each scenario's fields in order,
 * then its steps, as the server does. The answer holds new frozen objects with only the known fields, never the value
 * given.
 *
 * @param {unknown} value the decoded JSON list of scenarios, as pinround sent it
 * @returns {{scenarios: ReadonlyArray<object>} | {refusal: string}} the refusal names the place: "Scenario 2, step 5: …"
 */
export function checkScenarios(value) {
    try {
        return { scenarios: Object.freeze(scenariosOf(value)) };
    } catch (error) {
        if (error instanceof ScenarioRefusal) {
            return { refusal: error.message };
        }
        throw error;
    }
}

function scenariosOf(value) {
    if (!Array.isArray(value)) {
        throw new ScenarioRefusal(REFUSALS.notAList);
    }
    if (value.length > MAX_SCENARIOS) {
        throw new ScenarioRefusal(REFUSALS.tooManyScenarios);
    }

    return value.map((scenario, index) => scenarioOf(scenario, `Scenario ${index + 1}`));
}

function scenarioOf(scenario, place) {
    if (!isObject(scenario)) {
        throw refusalAt(place, REFUSALS.scenarioFields);
    }
    refuseUnlessFieldsAre(scenario, SCENARIO_FIELDS, [], place, REFUSALS.scenarioFields);
    const title = textOf(scenario.title, 'title', TITLE_MAX_LENGTH, false, place);
    const why = textOf(scenario.why, 'why', WHY_MAX_LENGTH, false, place);
    const setup = textOf(scenario.setup, 'setup', SETUP_MAX_LENGTH, true, place);
    const startUrl = textOf(scenario.start_url, 'start_url', TEXT_VALUE_MAX_LENGTH, false, place);
    const startOrigin = originOf(startUrl);
    if (null === startOrigin) {
        throw refusalAt(place, REFUSALS.startUrl);
    }

    const { steps } = scenario;
    if (!Array.isArray(steps)) {
        throw refusalAt(place, REFUSALS.stepsNotAList);
    }
    if (0 === steps.length) {
        throw refusalAt(place, REFUSALS.noSteps);
    }
    if (steps.length > MAX_STEPS) {
        throw refusalAt(place, REFUSALS.tooManySteps);
    }
    const checkedSteps = steps.map((step, index) => stepOf(step, startOrigin, `${place}, step ${index + 1}`));

    return Object.freeze({ title, why, setup, start_url: startUrl, steps: Object.freeze(checkedSteps) });
}

function stepOf(step, startOrigin, place) {
    if (!isObject(step)) {
        throw refusalAt(place, REFUSALS.stepNotAnObject);
    }
    const { type } = step;
    if ('string' !== typeof type || !Object.hasOwn(STEP_FIELDS, type)) {
        throw refusalAt(place, REFUSALS.stepType(Object.keys(STEP_FIELDS).join(', ')));
    }
    const fieldsOfType = Object.entries(STEP_FIELDS[type]);
    const required = ['type', 'say', ...fieldsOfType.filter(([, isRequired]) => isRequired).map(([field]) => field)];
    const optional = fieldsOfType.filter(([, isRequired]) => !isRequired).map(([field]) => field);
    refuseUnlessFieldsAre(step, required, optional, place, REFUSALS.stepFields(type, described(required, optional)));

    const checked = { type, say: textOf(step.say, 'say', SAY_MAX_LENGTH, false, place) };
    if (Object.hasOwn(step, 'target')) {
        checked.target = targetOf(step.target, place);
    }
    if (Object.hasOwn(step, 'text')) {
        // Typing nothing clears a field, which can be the very case a scenario shows.
        checked.text = textOf(step.text, 'text', TEXT_VALUE_MAX_LENGTH, 'type' === type, place);
    }
    if (Object.hasOwn(step, 'option')) {
        checked.option = textOf(step.option, 'option', TEXT_VALUE_MAX_LENGTH, false, place);
    }
    if (Object.hasOwn(step, 'url')) {
        checked.url = textOf(step.url, 'url', TEXT_VALUE_MAX_LENGTH, false, place);
        const origin = originOf(checked.url);
        if (null === origin) {
            throw refusalAt(place, REFUSALS.goToUrl);
        }
        if (origin !== startOrigin) {
            throw refusalAt(place, REFUSALS.goToOtherHost);
        }
    }

    return Object.freeze(checked);
}

function targetOf(target, place) {
    if (!isObject(target)) {
        throw refusalAt(place, REFUSALS.target);
    }
    const keys = Object.keys(target);
    if (keys.some((key) => 'name' !== key && !LOCATOR_KINDS.includes(key))) {
        throw refusalAt(place, REFUSALS.target);
    }
    const kinds = LOCATOR_KINDS.filter((kind) => Object.hasOwn(target, kind));
    if (1 !== kinds.length || (Object.hasOwn(target, 'name') && 'role' !== kinds[0])) {
        throw refusalAt(place, REFUSALS.target);
    }
    if ('text' === kinds[0]) {
        return Object.freeze({ text: textOf(target.text, 'target.text', TEXT_VALUE_MAX_LENGTH, false, place) });
    }
    if ('label' === kinds[0]) {
        return Object.freeze({ label: textOf(target.label, 'target.label', TEXT_VALUE_MAX_LENGTH, false, place) });
    }
    const role = textOf(target.role, 'target.role', TEXT_VALUE_MAX_LENGTH, false, place);
    if (!ROLE_SHAPE.test(role)) {
        throw refusalAt(place, REFUSALS.role);
    }
    if (!Object.hasOwn(target, 'name')) {
        return Object.freeze({ role });
    }

    return Object.freeze({ role, name: textOf(target.name, 'target.name', TEXT_VALUE_MAX_LENGTH, false, place) });
}

/**
 * Text as the server keeps it, not trimmed. A lone surrogate, which only an unpaired \uD800 to \uDFFF escape in the
 * JSON gives, has no UTF-8 form, so it is refused as the server refuses it; maxLength counts code points, as PHP's
 * mb_strlen does, so an emoji is one character, not two UTF-16 units.
 */
function textOf(value, field, maxLength, mayBeBlank, place) {
    if ('string' !== typeof value) {
        throw refusalAt(place, REFUSALS.notText(field));
    }
    if (!value.isWellFormed()) {
        throw refusalAt(place, REFUSALS.notUtf8(field));
    }
    if (value.includes('\u0000')) {
        throw refusalAt(place, REFUSALS.nul(field));
    }
    if (codePointCountOf(value, maxLength) > maxLength) {
        throw refusalAt(place, REFUSALS.tooLong(field, maxLength));
    }
    if (!mayBeBlank && !NOT_BLANK.test(value)) {
        throw refusalAt(place, REFUSALS.blank(field));
    }

    return value;
}

// Stops one past the cap, so a long text from outside is not read through before the refusal.
function codePointCountOf(value, cap) {
    let count = 0;
    for (const _ of value) {
        count += 1;
        if (count > cap) {
            break;
        }
    }

    return count;
}

/** "scheme://host:port" in lowercase with the default port filled in, or null for no full http or https address. */
function originOf(url) {
    const parts = URL_SHAPE.exec(url);
    if (null === parts) {
        return null;
    }
    const [, scheme, host, givenPort] = parts;
    const port = undefined === givenPort ? ('https' === scheme ? 443 : 80) : Number(givenPort);
    if (port < 1 || port > MAX_PORT) {
        return null;
    }

    return `${scheme}://${host.toLowerCase()}:${port}`;
}

const isObject = (value) => null !== value && 'object' === typeof value && !Array.isArray(value);

function refuseUnlessFieldsAre(fields, required, optional, place, reason) {
    const keys = Object.keys(fields);
    if (required.some((field) => !Object.hasOwn(fields, field)) || keys.some((key) => !required.includes(key) && !optional.includes(key))) {
        throw refusalAt(place, reason);
    }
}

function described(required, optional) {
    const leading = required.slice(0, -1);
    const last = required.at(-1);
    const fields = 0 === leading.length ? last : `${leading.join(', ')} and ${last}`;

    return 0 === optional.length ? fields : `${fields}, and may take ${optional.join(' and ')}`;
}
