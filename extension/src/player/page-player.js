// The scenario player's hands in the page: one handler per step type that acts on the page, injected after
// player-text.js into the extension's isolated world. The extension reaches it through executeScript
// (globalThis.pinroundPagePlayer) and checks every answer again (step-runner.js). Steps go_to, pause and capture never
// run here: they are a navigation, a wait for the person and a screenshot, which the extension does.
//
// A step finds its element by role and accessible name, by visible text or by label, and needs exactly one visible
// match. The roles and names are a plain subset of ARIA and accname, enough for ordinary forms and pages; not covered:
// the page's shadow roots and frames, CSS generated content, aria-owns, names from nested aria-labelledby or from the
// value of a control inside a label, fieldset legends, figure and table captions, and a text field's placeholder.
//
// The highlight is drawn in a closed shadow root that only this script holds, with every style inside it, so none
// reach the page and the page's script cannot reach it. The whole file is one expression, so a second injection keeps
// the first player and its highlight.
globalThis.pinroundPagePlayer ??= (() => {
    const { collapsedWhitespace, cappedText } = globalThis.pinroundPlayerText;
    const HIGHLIGHT_TAG = 'pinround-player-highlight';
    // Hosts the extension adds to the page; their content is never part of what a step finds or reads.
    const OWN_HOSTS = new Set([HIGHLIGHT_TAG, 'pinround-overlay']);
    const DEFAULT_TIMEOUT_MS = 10_000;
    const MAX_TIMEOUT_MS = 60_000;
    const DEFAULT_POLL_MS = 100;
    const MIN_POLL_MS = 10;
    // The ring sits this far outside the element, so it never covers the element's own border or text.
    const HIGHLIGHT_GAP_PX = 4;
    const STYLES = `
        :host {
            all: initial !important;
            position: fixed !important;
            inset: 0 !important;
            z-index: 2147483647 !important;
            display: block !important;
            pointer-events: none !important;
        }
        .ring {
            position: absolute;
            box-sizing: border-box;
            border: 3px solid #2f6fd6;
            border-radius: 6px;
            box-shadow: 0 0 0 2px #ffffff, inset 0 0 0 1px #ffffff;
        }
    `;

    const INPUT_ROLES = {
        button: 'button',
        submit: 'button',
        reset: 'button',
        image: 'button',
        checkbox: 'checkbox',
        radio: 'radio',
        range: 'slider',
        number: 'spinbutton',
        search: 'searchbox',
        email: 'textbox',
        tel: 'textbox',
        text: 'textbox',
        url: 'textbox',
    };
    const TAG_ROLES = {
        button: 'button',
        textarea: 'textbox',
        option: 'option',
        h1: 'heading',
        h2: 'heading',
        h3: 'heading',
        h4: 'heading',
        h5: 'heading',
        h6: 'heading',
        ul: 'list',
        ol: 'list',
        li: 'listitem',
        nav: 'navigation',
        main: 'main',
        dialog: 'dialog',
        article: 'article',
        table: 'table',
        tr: 'row',
        td: 'cell',
        th: 'columnheader',
        progress: 'progressbar',
        output: 'status',
        hr: 'separator',
        form: 'form',
    };
    const NAME_FROM_CONTENT = new Set([
        'button', 'link', 'heading', 'option', 'cell', 'columnheader', 'rowheader', 'row', 'tab', 'menuitem',
        'menuitemcheckbox', 'menuitemradio', 'checkbox', 'radio', 'switch', 'treeitem', 'tooltip',
    ]);
    const FIELD_ROLES = new Set(['textbox', 'searchbox', 'combobox', 'listbox', 'checkbox', 'radio', 'switch', 'spinbutton', 'slider']);
    // What typing can fill: the types whose value is the text itself.
    const TYPEABLE_INPUT_TYPES = new Set(['text', 'email', 'search', 'tel', 'url', 'number']);
    const PASSWORD_AUTOCOMPLETE = new Set(['current-password', 'new-password']);
    // Neither found nor a pass may tell what a password field holds, or a scenario could test guesses one by one.
    const PASSWORD_NOT_READ = 'The player never reads a password field. Look at it yourself, then play on.';

    const make = (tag, className) => Object.assign(document.createElement(tag), { className });
    const stopped = (message, details = {}) => ({ stopped: true, message, ...details });
    const ok = () => ({ ok: true });
    const quoted = (text) => `"${cappedText(text)}"`;

    function implicitRoleOf(element) {
        const tag = element.localName;
        if ('input' === tag) {
            return INPUT_ROLES[element.type] ?? null;
        }
        if ('a' === tag || 'area' === tag) {
            return element.hasAttribute('href') ? 'link' : null;
        }
        if ('select' === tag) {
            return element.multiple || element.size > 1 ? 'listbox' : 'combobox';
        }
        if ('img' === tag) {
            return '' === element.getAttribute('alt') ? 'presentation' : 'img';
        }

        return TAG_ROLES[tag] ?? null;
    }

    function roleOf(element) {
        const [explicitRole] = (element.getAttribute('role') ?? '').trim().toLowerCase().split(/\s+/u);

        return '' === explicitRole || undefined === explicitRole ? implicitRoleOf(element) : explicitRole;
    }

    const isField = (element) => ['input', 'select', 'textarea'].includes(element.localName);

    // A label's own words: the text of a field it wraps is no part of them.
    function labelTextOf(label) {
        const walker = document.createTreeWalker(label, NodeFilter.SHOW_ELEMENT | NodeFilter.SHOW_TEXT, {
            acceptNode: (node) => (node.nodeType === Node.ELEMENT_NODE && isField(node) ? NodeFilter.FILTER_REJECT : NodeFilter.FILTER_ACCEPT),
        });
        const words = [];
        for (let node = walker.nextNode(); null !== node; node = walker.nextNode()) {
            if (node.nodeType === Node.TEXT_NODE) {
                words.push(node.data);
            }
        }

        return collapsedWhitespace(words.join(''));
    }

    // aria-labelledby, then aria-label, then the <label>s of a field: what both a role's name and a label start from.
    function authoredLabelOf(element) {
        const labelledBy = (element.getAttribute('aria-labelledby') ?? '').trim();
        if ('' !== labelledBy) {
            const named = labelledBy.split(/\s+/u).map((id) => document.getElementById(id)).filter((labelling) => null !== labelling);
            const name = collapsedWhitespace(named.map((labelling) => labelling.textContent).join(' '));
            if ('' !== name) {
                return name;
            }
        }
        const ariaLabel = collapsedWhitespace(element.getAttribute('aria-label') ?? '');
        if ('' !== ariaLabel) {
            return ariaLabel;
        }
        if (isField(element) && null !== element.labels) {
            return collapsedWhitespace([...element.labels].map(labelTextOf).join(' '));
        }

        return '';
    }

    function accessibleNameOf(element, role) {
        const authored = authoredLabelOf(element);
        if ('' !== authored) {
            return authored;
        }
        if ('img' === element.localName || 'area' === element.localName || ('input' === element.localName && 'image' === element.type)) {
            const alt = collapsedWhitespace(element.getAttribute('alt') ?? '');
            if ('' !== alt) {
                return alt;
            }
        }
        if ('input' === element.localName && ['button', 'submit', 'reset'].includes(element.type)) {
            const defaultName = { submit: 'Submit', reset: 'Reset' }[element.type] ?? '';

            return collapsedWhitespace(element.value) || defaultName;
        }
        if (NAME_FROM_CONTENT.has(role)) {
            const content = collapsedWhitespace(element.innerText);
            if ('' !== content) {
                return content;
            }
        }

        return collapsedWhitespace(element.getAttribute('title') ?? '');
    }

    function isVisible(element) {
        const box = element.getBoundingClientRect();

        return element.checkVisibility({ opacityProperty: true, visibilityProperty: true, checkOpacity: true, checkVisibilityCSS: true })
            && (box.width > 0 || box.height > 0);
    }

    const pageElements = () => [...document.querySelectorAll('body *')].filter((element) => !OWN_HOSTS.has(element.localName));

    /**
     * The visible elements the target names. A role and name compare whole after white space is collapsed, and an
     * element under aria-hidden has no role; a label the same. Visible text is held, not whole: the innermost elements
     * whose text holds it, as a parent holds its children's text, so "Hello world" over <b>Hello</b> <i>world</i>
     * finds the parent. A field's value is no visible text.
     */
    function matchesOf(target) {
        if (Object.hasOwn(target, 'role')) {
            const wantedName = Object.hasOwn(target, 'name') ? collapsedWhitespace(target.name) : null;

            return pageElements().filter((element) => {
                const role = roleOf(element);
                if (role !== target.role || null !== element.closest('[aria-hidden="true"]') || !isVisible(element)) {
                    return false;
                }

                return null === wantedName || accessibleNameOf(element, role) === wantedName;
            });
        }
        if (Object.hasOwn(target, 'label')) {
            const wantedLabel = collapsedWhitespace(target.label);

            return pageElements().filter((element) => (isField(element) || FIELD_ROLES.has(roleOf(element)))
                && 'hidden' !== element.type
                && isVisible(element)
                && authoredLabelOf(element) === wantedLabel);
        }
        const wantedText = collapsedWhitespace(target.text);
        const holding = new Set(pageElements().filter((element) => isVisible(element) && collapsedWhitespace(element.innerText).includes(wantedText)));

        return [...holding].filter((element) => ![...element.children].some((child) => holding.has(child)));
    }

    function describedTarget(target) {
        if (Object.hasOwn(target, 'role')) {
            return Object.hasOwn(target, 'name') ? `with role ${target.role} named ${quoted(target.name)}` : `with role ${target.role}`;
        }

        return Object.hasOwn(target, 'label') ? `labelled ${quoted(target.label)}` : `with the text ${quoted(target.text)}`;
    }

    /** @returns {{element: Element} | {count: number, message: string}} the one visible match, or why there is none */
    function locate(target) {
        const matches = matchesOf(target);
        if (1 === matches.length) {
            return { element: matches[0] };
        }
        const message = 0 === matches.length
            ? `Found no visible element ${describedTarget(target)}.`
            : `Found ${matches.length} visible elements ${describedTarget(target)}; a step needs exactly one.`;

        return { count: matches.length, message };
    }

    let shownHighlight = null;

    function clearHighlight() {
        if (null === shownHighlight) {
            return;
        }
        window.removeEventListener('scroll', shownHighlight.place, { capture: true });
        window.removeEventListener('resize', shownHighlight.place);
        shownHighlight.host.remove();
        shownHighlight = null;
    }

    function highlight(element) {
        clearHighlight();
        const host = document.createElement(HIGHLIGHT_TAG);
        const shadow = host.attachShadow({ mode: 'closed' });
        const sheet = new CSSStyleSheet();
        sheet.replaceSync(STYLES);
        shadow.adoptedStyleSheets = [sheet];
        const ring = make('div', 'ring');
        ring.setAttribute('aria-hidden', 'true');
        shadow.append(ring);
        const place = () => {
            const box = element.getBoundingClientRect();
            ring.style.left = `${box.left - HIGHLIGHT_GAP_PX}px`;
            ring.style.top = `${box.top - HIGHLIGHT_GAP_PX}px`;
            ring.style.width = `${box.width + (2 * HIGHLIGHT_GAP_PX)}px`;
            ring.style.height = `${box.height + (2 * HIGHLIGHT_GAP_PX)}px`;
        };
        window.addEventListener('scroll', place, { capture: true, passive: true });
        window.addEventListener('resize', place, { passive: true });
        document.documentElement.append(host);
        place();
        shownHighlight = { host, place };
    }

    function locateAndHighlight(target) {
        const found = locate(target);
        if (undefined !== found.element) {
            found.element.scrollIntoView({ block: 'center', inline: 'center' });
            highlight(found.element);
        }

        return found;
    }

    /** Shows where a step will act before it runs, or why it cannot; any earlier highlight goes. */
    function highlightTarget(target) {
        clearHighlight();
        const found = locateAndHighlight(target);

        return undefined === found.element ? stopped(found.message) : ok();
    }

    const isDisabled = (element) => element.matches(':disabled') || 'true' === element.getAttribute('aria-disabled');

    function isPasswordField(element) {
        const autocomplete = (element.getAttribute('autocomplete') ?? '').toLowerCase().split(/\s+/u);

        return ('input' === element.localName && 'password' === element.type) || autocomplete.some((token) => PASSWORD_AUTOCOMPLETE.has(token));
    }

    // The value the browser keeps for typed text: an input drops line breaks, an email or url field also drops the
    // white space at its ends, and a text area keeps every line break as a line feed.
    function keptValueOf(element, text) {
        if ('textarea' === element.localName) {
            return text.replace(/\r\n?/gu, '\n');
        }
        const oneLine = text.replace(/[\r\n]/gu, '');

        return ['email', 'url'].includes(element.type) ? oneLine.replace(/^[\t\n\f\r ]+|[\t\n\f\r ]+$/gu, '') : oneLine;
    }

    function shownTextOf(element) {
        if ('select' === element.localName) {
            return [...element.selectedOptions].map((option) => option.label).join(' ');
        }

        return isField(element) ? element.value : element.innerText;
    }

    const HANDLERS = {
        click(step) {
            const found = locateAndHighlight(step.target);
            if (undefined === found.element) {
                return stopped(found.message);
            }
            if (isDisabled(found.element)) {
                return stopped(`The element ${describedTarget(step.target)} is disabled, so it was not clicked.`);
            }
            found.element.click();

            return ok();
        },
        type(step) {
            const found = locateAndHighlight(step.target);
            if (undefined === found.element) {
                return stopped(found.message);
            }
            const field = found.element;
            if (isPasswordField(field)) {
                return stopped('The player never types into a password field. Type it yourself, then play on.');
            }
            if (!('textarea' === field.localName || ('input' === field.localName && TYPEABLE_INPUT_TYPES.has(field.type)))) {
                return stopped(`The element ${describedTarget(step.target)} is no text field, so nothing was typed.`);
            }
            if (field.disabled || field.readOnly) {
                return stopped(`The field ${describedTarget(step.target)} cannot be changed, so nothing was typed.`);
            }
            const kept = keptValueOf(field, step.text);
            if (field.maxLength >= 0 && kept.length > field.maxLength) {
                return stopped(`The field ${describedTarget(step.target)} takes at most ${field.maxLength} characters, so nothing was typed.`);
            }
            field.focus();
            field.value = step.text;
            field.dispatchEvent(new Event('input', { bubbles: true }));
            field.dispatchEvent(new Event('change', { bubbles: true }));
            if (field.value !== kept) {
                return stopped('The field did not keep the text.', { expected: kept, found: cappedText(field.value) });
            }

            return ok();
        },
        select(step) {
            const found = locateAndHighlight(step.target);
            if (undefined === found.element) {
                return stopped(found.message);
            }
            const list = found.element;
            if ('select' !== list.localName) {
                return stopped(`The element ${describedTarget(step.target)} is no list to choose from.`);
            }
            // A step names one option, and choosing it would leave the others as they were.
            if (list.multiple) {
                return stopped(`The list ${describedTarget(step.target)} takes more than one choice, and a step chooses one, so nothing was chosen.`);
            }
            if (list.disabled) {
                return stopped(`The list ${describedTarget(step.target)} cannot be changed, so nothing was chosen.`);
            }
            const wanted = collapsedWhitespace(step.option);
            const options = [...list.options].filter((option) => collapsedWhitespace(option.label) === wanted);
            if (1 !== options.length) {
                return stopped(0 === options.length
                    ? `Found no option ${quoted(step.option)} in the list.`
                    : `Found ${options.length} options ${quoted(step.option)} in the list; a step needs exactly one.`);
            }
            if (options[0].disabled) {
                return stopped(`The option ${quoted(step.option)} cannot be chosen.`);
            }
            options[0].selected = true;
            list.dispatchEvent(new Event('input', { bubbles: true }));
            list.dispatchEvent(new Event('change', { bubbles: true }));

            return ok();
        },
        // The attempts are counted, not timed, so a test's wait that resolves at once waits no real time.
        async wait_for(step, { wait, timeoutMs, pollMs }) {
            const attempts = Math.floor(timeoutMs / pollMs) + 1;
            for (let attempt = 1; ; attempt += 1) {
                const found = locateAndHighlight(step.target);
                if (undefined !== found.element) {
                    return ok();
                }
                if (attempt >= attempts) {
                    return stopped(`Waited ${Number((timeoutMs / 1000).toFixed(1))} seconds. ${found.message}`);
                }
                await wait(pollMs);
            }
        },
        expect_text(step) {
            let foundText = document.body.innerText;
            if (Object.hasOwn(step, 'target')) {
                const found = locateAndHighlight(step.target);
                if (undefined === found.element) {
                    return stopped(found.message);
                }
                if (isPasswordField(found.element)) {
                    return stopped(PASSWORD_NOT_READ);
                }
                foundText = shownTextOf(found.element);
            }
            const expected = collapsedWhitespace(step.text);
            const shown = collapsedWhitespace(foundText);
            if (shown.includes(expected)) {
                return ok();
            }

            return stopped('The page does not show the expected text.', { expected: cappedText(expected), found: cappedText(shown) });
        },
        expect_visible(step) {
            const found = locateAndHighlight(step.target);
            if (undefined !== found.element) {
                return ok();
            }

            return stopped(found.message, {
                expected: `One visible element ${describedTarget(step.target)}`,
                found: 0 === found.count ? 'None visible' : `${found.count} visible`,
            });
        },
    };

    const waitMs = (ms) => new Promise((resolve) => {
        setTimeout(resolve, ms);
    });
    const withinRange = (value, min, max, fallback) => (Number.isFinite(value) ? Math.min(max, Math.max(min, value)) : fallback);

    /**
     * Runs one step on the page. The highlight moves to the step's element and stays there until the next step,
     * highlightTarget or clearHighlight; a step that finds no element leaves none.
     *
     * @param {object} step a step that ScenarioRules (scenario-rules.js) accepted
     * @param {{wait?: (ms: number) => Promise<void>, timeoutMs?: number, pollMs?: number}} [options] wait_for's
     *   timeout, 0 to 60 000 ms, and its pause between looks, 10 ms up to the timeout
     * @returns {Promise<{ok: true} | {stopped: true, message: string, expected?: string, found?: string}>} found is
     *   capped page text with its control characters as they are; the extension makes them safe to show
     */
    async function runStep(step, { wait = waitMs, timeoutMs = DEFAULT_TIMEOUT_MS, pollMs = DEFAULT_POLL_MS } = {}) {
        clearHighlight();
        const handler = 'string' === typeof step?.type && Object.hasOwn(HANDLERS, step.type) ? HANDLERS[step.type] : null;
        if (null === handler) {
            return stopped('The page cannot run this step.');
        }
        const timeout = withinRange(timeoutMs, 0, MAX_TIMEOUT_MS, DEFAULT_TIMEOUT_MS);
        const poll = withinRange(pollMs, MIN_POLL_MS, Math.max(MIN_POLL_MS, timeout), DEFAULT_POLL_MS);
        try {
            return await handler(step, { wait, timeoutMs: timeout, pollMs: poll });
        } catch (error) {
            return stopped(`The step broke on this page: ${cappedText(String(error?.message ?? error))}`);
        }
    }

    return Object.freeze({ HIGHLIGHT_TAG, runStep, highlightTarget, clearHighlight });
})();
