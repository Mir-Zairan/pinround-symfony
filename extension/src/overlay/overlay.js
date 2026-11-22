// The pin overlay that "Pin this page" injects into the page, after pin-rules.js. The pins live in the extension's
// isolated world, where the popup reads them through executeScript (globalThis.pinroundOverlay) and checks them again.
// The shadow root is closed and only this script holds it, so the page's script cannot reach the pins or read the
// notes through the DOM, and every handler ignores an event the page dispatched (isTrusted false) or, for a note,
// wrote with document.execCommand, but for the gaps the note guard below names. The page can still remove or hide the
// host element. The root holds every style, so none reach the page, and :host's !important declarations win over the
// page's own. Notes only ever go in through textContent.
// The page still sees every key typed into a note: its capture-phase keydown and beforeinput listeners on window and
// document run before the shadow root, so a page can log a note as it is typed. Only a note editor in an iframe of the
// extension's own origin would hide the keys.
// The same root holds the dock (dock.js) once openDock() is called: one host, one closed root. Pinning is a mode: the
// tinted surface that takes clicks shows only while it is on; the pins stay on the page between modes. With the dock
// open, Escape and a saved capture drop the pins and end pinning but keep the dock; without it they remove the host.
// A click on a pin chooses it (a ring) and opens its note form just below it, with Save and Remove; a pin with a note
// shows it in a dark label beside it. The dock's "Clear N pins" drops them all without changing the mode.
// The dock's scenario run (dock-run.js) lives in the extension's local storage, not here, so the step card comes back
// as it was whenever the dock opens again on a page, after a reload or a navigation too.
// Injecting this file only defines globalThis.pinroundOverlay: the extension then calls startPinning() or openDock().
(() => {
    if (undefined !== globalThis.pinroundOverlay) {
        return;
    }
    const rules = globalThis.pinroundPinRules;
    const dockRules = globalThis.pinroundDock;
    const runRules = globalThis.pinroundDockRun;
    const ARROW_STEPS = { ArrowLeft: [-1, 0], ArrowRight: [1, 0], ArrowUp: [0, -1], ArrowDown: [0, 1] };
    const SHIFT_STEP_PERCENT = 10;
    /** How long the white flash after a capture shows, as its CSS animation runs. */
    const FLASH_MS = 300;
    // Stopped at the shadow root, so the page's listeners on the way up never hear a key or click meant for a pin; its
    // capture-phase listeners on window and document still run first.
    const OVERLAY_EVENTS = ['click', 'mousedown', 'mouseup', 'pointerdown', 'pointerup', 'keydown', 'keyup', 'keypress', 'beforeinput', 'input'];
    // A pin is a percent of the viewport, so the page must not scroll under it while the overlay is open, or the pin
    // lands on other content in the picture. Wheel (not Ctrl+wheel, which zooms) and touch scrolling are blocked over
    // the whole overlay, and these keys everywhere but in the note field and Space on a button, which presses it. The
    // page's own script, and a drag on the page's scrollbar, can still scroll it.
    const SCROLL_KEYS = new Set(['PageUp', 'PageDown', 'Home', 'End', ' ', 'ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight']);
    // The ring of FR-RND-02 as assets/styles/review.css draws it: hollow, dark outside, white inside, number tab.
    const STYLES = `
        :host {
            all: initial !important;
            position: fixed !important;
            top: 0 !important;
            left: 0 !important;
            width: 100vw !important;
            height: 100vh !important;
            z-index: 2147483647 !important;
            display: block !important;
            touch-action: none !important;
            pointer-events: none !important;
            --font: 'Atkinson Hyperlegible Next', 'Atkinson Hyperlegible', system-ui, sans-serif !important;
            --heading-font: 'Space Grotesk', 'Atkinson Hyperlegible Next', system-ui, sans-serif !important;
            --bg: #f4fbf8 !important;
            --panel: #ffffff !important;
            --line: #bec9c5 !important;
            --fg: #161d1b !important;
            --muted: #3f4946 !important;
            --primary: #006b5f !important;
            --on-primary: #ffffff !important;
            --dock: #2b3230 !important;
            --dock-fg: #ecf2ef !important;
            --dock-hover: #3d4643 !important;
            --danger: #ba1a1a !important;
        }
        @media (prefers-color-scheme: dark) {
            :host {
                --bg: #0e1513 !important;
                --panel: #1a211f !important;
                --line: #3f4946 !important;
                --fg: #dde4e1 !important;
                --muted: #bec9c5 !important;
                --primary: #82d5c6 !important;
                --on-primary: #003731 !important;
                --dock: #dde4e1 !important;
                --dock-fg: #161d1b !important;
                --dock-hover: #c4ccc9 !important;
                --danger: #ffb4ab !important;
            }
        }
        * { box-sizing: border-box; }
        .surface, .pins, .note-editor, .hint, .status { font: 14px/1.4 var(--font); }
        .note-editor input, .note-editor button { font: inherit; }
        .surface { position: absolute; inset: 0; background: rgb(22 29 27 / 6%); cursor: crosshair; outline: none; pointer-events: auto; }
        .surface[hidden] { display: none; }
        .surface:focus-visible { box-shadow: inset 0 0 0 3px var(--primary); }
        .crosshair { display: none; position: absolute; width: 24px; height: 24px; transform: translate(-50%, -50%); pointer-events: none; }
        .surface:focus .crosshair { display: block; }
        .crosshair::before, .crosshair::after { content: ''; position: absolute; background: #161d1b; box-shadow: 0 0 0 1px #ffffff; }
        .crosshair::before { left: 11px; top: 0; width: 2px; height: 24px; }
        .crosshair::after { left: 0; top: 11px; width: 24px; height: 2px; }
        .pins { position: absolute; inset: 0; pointer-events: none; }
        .pin {
            position: absolute;
            width: 28px;
            height: 28px;
            padding: 0;
            border: 2px solid #ffffff;
            border-radius: 50%;
            background: none;
            box-shadow: 0 0 0 2px #161d1b, inset 0 0 0 2px #161d1b;
            transform: translate(-50%, -50%);
            pointer-events: auto;
            cursor: pointer;
        }
        .pin--selected { box-shadow: 0 0 0 2px #161d1b, inset 0 0 0 2px #161d1b, 0 0 0 6px var(--primary); }
        .pin:focus-visible { outline: 2px solid var(--primary); outline-offset: 6px; }
        .pin__tab {
            position: absolute;
            top: -10px;
            right: -10px;
            min-width: 18px;
            padding: 0 5px;
            border-radius: 999px;
            background: #161d1b;
            color: #ffffff;
            font-size: 0.7rem;
            font-weight: 700;
            line-height: 18px;
            text-align: center;
        }
        .pin__note {
            position: absolute;
            top: 50%;
            left: calc(100% + 10px);
            max-width: 200px;
            overflow: hidden;
            padding: 3px 8px;
            border-radius: 6px;
            background: #161d1b;
            color: #ffffff;
            font-size: 0.75rem;
            text-align: left;
            white-space: nowrap;
            text-overflow: ellipsis;
            transform: translateY(-50%);
        }
        .pin__note[hidden] { display: none; }
        .pin--left .pin__note { left: auto; right: calc(100% + 10px); }
        .pin--left .pin__tab { right: auto; left: -10px; }
        /* --pin-x is the pin's left in percent. The form starts 14px left of the pin (ends 14px right of it when it opens
           left) but is held 8px inside the viewport, so a pin at an edge keeps its whole form on screen. */
        .note-editor {
            --width: min(300px, calc(100% - 16px));
            --wanted-left: calc(var(--pin-x) - 14px);
            position: absolute;
            left: clamp(8px, var(--wanted-left), calc(100% - var(--width) - 8px));
            display: grid;
            grid-template-columns: 1fr auto auto;
            align-items: center;
            gap: 6px;
            width: var(--width);
            padding: 8px;
            border: 1px solid var(--line);
            border-radius: 12px;
            background: var(--panel);
            color: var(--fg);
            box-shadow: 0 8px 24px rgb(0 0 0 / 18%);
            transform: translateY(22px);
            pointer-events: auto;
        }
        .note-editor[hidden] { display: none; }
        .note-editor--left { --wanted-left: calc(var(--pin-x) + 14px - var(--width)); }
        .note-editor--above { transform: translateY(calc(-100% - 22px)); }
        .note-editor input { width: 100%; min-width: 0; padding: 7px 9px; border: 1px solid var(--line); border-radius: 8px; color: inherit; background: var(--panel); font-size: 0.875rem; }
        .note-editor button { padding: 7px 12px; border: 1px solid var(--primary); border-radius: 8px; background: var(--primary); color: var(--on-primary); font-weight: 500; cursor: pointer; }
        .note-editor .note-editor__remove { border-color: var(--danger); background: transparent; color: var(--danger); }
        .note-editor :focus-visible { outline: 2px solid var(--primary); outline-offset: 2px; }
        .flash { position: absolute; inset: 0; background: #ffffff; opacity: 0; pointer-events: none; animation: pinround-flash 300ms ease-out; }
        @keyframes pinround-flash { from { opacity: 0.85; } to { opacity: 0; } }
        @media (prefers-reduced-motion: reduce) { .flash { animation-duration: 1ms; } }
        .hint, .status {
            position: absolute;
            left: 50%;
            max-width: calc(100% - 16px);
            margin: 0;
            padding: 6px 12px;
            border-radius: 8px;
            background: #161d1b;
            color: #ffffff;
            transform: translateX(-50%);
            pointer-events: none;
        }
        .hint { top: 8px; }
        .status { bottom: 8px; }
        .status:empty { display: none; }
    `;

    const make = (tag, className, properties = {}) => Object.assign(document.createElement(tag), { className, ...properties });
    const hiddenFromReaders = (element) => {
        element.setAttribute('aria-hidden', 'true');

        return element;
    };
    const placeAt = (element, point) => {
        element.style.left = `${point.x_pct}%`;
        element.style.top = `${point.y_pct}%`;
    };
    const toPercentRange = (value) => Math.min(100, Math.max(0, value));
    // The same sides for the note editor and a pin's note bubble, number tab and Remove, so none runs off the viewport.
    const opensLeft = (point) => point.x_pct > 50;
    // The note form opens below its pin, as near the pin as can be, unless the pin is so low the form would leave the page.
    const editorOpensAbove = (point) => point.y_pct > 75;
    const onlyTrusted = (handler) => (event) => {
        if (event.isTrusted) {
            handler(event);
        }
    };
    // Chrome hands a textarea's typed, pasted and composed text in data, and at a composition's beforeinput the
    // selection spans the composed text the new one replaces.
    const INSERTING_KINDS = new Set(['insertText', 'insertFromPaste', 'insertCompositionText', 'insertLineBreak', 'insertParagraph']);
    const DELETING_BACKWARD_KINDS = new Set(['deleteContentBackward', 'deleteWordBackward', 'deleteSoftLineBackward', 'deleteHardLineBackward']);
    const DELETING_FORWARD_KINDS = new Set(['deleteContentForward', 'deleteWordForward', 'deleteSoftLineForward', 'deleteHardLineForward', 'deleteByCut', 'deleteContent']);
    // Chrome cuts text at a field's maxLength, but never between the two halves of a surrogate pair.
    const fittingText = (text, room) => {
        if (text.length <= room) {
            return text;
        }
        const cut = text.slice(0, Math.max(0, room));
        const lastCode = cut.charCodeAt(cut.length - 1);

        return lastCode >= 0xD800 && lastCode <= 0xDBFF ? cut.slice(0, -1) : cut;
    };

    const isOneLine = (field) => 'TEXTAREA' !== field.tagName;
    const lineBreakIn = (field) => (isOneLine(field) ? '' : '\n');
    // A textarea's value holds every line break as \n. Chrome drops the line breaks that end text put into a one-line
    // input and turns the others into spaces, so pasted lines stay apart.
    const insertedTextIn = (field, text) => (isOneLine(field) ? text.replace(/[\r\n]+$/, '').replace(/\r\n?|\n/g, ' ') : text.replace(/\r\n?/g, '\n'));

    /**
     * What a trusted beforeinput of this kind does to the field, or null for a kind with no model here. intended is the
     * field's whole value after the edit, and null for a deletion with nothing selected, whose length is not known.
     */
    function personEditOf(inputType, data, field) {
        const before = field.value.slice(0, field.selectionStart);
        const after = field.value.slice(field.selectionEnd);
        const isBackward = DELETING_BACKWARD_KINDS.has(inputType);
        if (INSERTING_KINDS.has(inputType)) {
            const isLineBreak = 'insertLineBreak' === inputType || 'insertParagraph' === inputType;
            const text = isLineBreak ? lineBreakIn(field) : insertedTextIn(field, data ?? '');
            const inserted = fittingText(text, field.maxLength - before.length - after.length);

            return { before, after, isBackward, intended: before + inserted + after, caret: before.length + inserted.length };
        }
        if (!isBackward && !DELETING_FORWARD_KINDS.has(inputType)) {
            return null;
        }
        const hasSelection = before.length + after.length < field.value.length;

        return { before, after, isBackward, intended: hasSelection ? before + after : null, caret: before.length };
    }

    // With nothing selected, a deletion keeps all of the text on the caret's other side and the start (backward) or
    // the end (forward) of the text on its own side.
    function isDeletionBy(edit, value) {
        if (value.length >= edit.before.length + edit.after.length) {
            return false;
        }
        if (edit.isBackward) {
            return value.endsWith(edit.after) && edit.before.startsWith(value.slice(0, value.length - edit.after.length));
        }

        return value.startsWith(edit.before) && edit.after.endsWith(value.slice(edit.before.length));
    }

    /** The value each guarded field held after the person's last edit, or as the overlay last set it. */
    const keptValues = new Map();
    /** @type {null|{field: HTMLInputElement, before: string, after: string, isBackward: boolean, intended: null|string, caret: number}} */
    let personEdit = null;

    /**
     * Holds the field to what the person types, against the page's script writing into it (see the beforeinput listener
     * below), and answers how the overlay sets its value.
     *
     * @returns {(value: string) => void}
     */
    function guardTyping(field) {
        keptValues.set(field, field.value);
        field.addEventListener('input', () => {
            const edit = personEdit?.field === field ? personEdit : null;
            const isPersonResult = null !== edit && (field.value === edit.intended || (null === edit.intended && isDeletionBy(edit, field.value)));
            if (!isPersonResult && null !== edit && null !== edit.intended) {
                field.value = edit.intended;
                field.setSelectionRange(edit.caret, edit.caret);
            } else if (!isPersonResult) {
                field.value = keptValues.get(field);
            }
            keptValues.set(field, field.value);
        });

        return (value) => {
            field.value = value;
            keptValues.set(field, value);
        };
    }

    const host = document.createElement('pinround-overlay');
    const shadow = host.attachShadow({ mode: 'closed' });
    const sheet = new CSSStyleSheet();
    sheet.replaceSync(STYLES);
    shadow.adoptedStyleSheets = [sheet];

    const surface = make('div', 'surface', { tabIndex: 0, hidden: true });
    surface.setAttribute('role', 'application');
    surface.setAttribute('aria-label', 'Pin overlay');
    surface.setAttribute('aria-describedby', 'pinround-keyboard-help');
    const crosshair = hiddenFromReaders(make('div', 'crosshair'));
    surface.append(crosshair);
    const pinLayer = make('div', 'pins');
    const editor = make('form', 'note-editor', { hidden: true });
    const noteField = make('input', 'note-field', { type: 'text', maxLength: rules.NOTE_MAX_LENGTH, placeholder: 'What is wrong here?', autocomplete: 'off' });
    const saveNoteButton = make('button', 'note-editor__save', { type: 'button', textContent: 'Save' });
    const removeNoteButton = make('button', 'note-editor__remove', { type: 'button', textContent: 'Remove' });
    editor.append(noteField, saveNoteButton, removeNoteButton);
    const setNoteField = guardTyping(noteField);
    // Shown until the first pin, so it never hides what people pin; the keys stay in the surface's description.
    const hint = hiddenFromReaders(make('p', 'hint', { textContent: 'Click to drop a pin. Escape closes and drops the pins.' }));
    const keyboardHelp = make('p', '', {
        id: 'pinround-keyboard-help',
        hidden: true,
        textContent: 'Click, or move with the arrow keys and press Enter, to drop a pin. Tab goes through the pins, and Delete removes one. Escape closes and drops the pins.',
    });
    const status = make('p', 'status');
    status.setAttribute('role', 'status');
    // Later is on top: the pins and the note editor over the hint and the status.
    shadow.append(surface, keyboardHelp, hint, status, pinLayer, editor);

    /** @type {Array<{x_pct: number, y_pct: number, note: string, button: HTMLButtonElement, tab: HTMLElement, bubble: HTMLElement}>} */
    const pins = [];
    const crosshairAt = { x_pct: 50, y_pct: 50 };
    /** @type {null|{pin: object, returnFocusTo: HTMLElement}} */
    let editing = null;
    let isPinning = false;
    /** Whether pinning was on when hideForCapture took the host out, for show() to put it back as it was. */
    let wasPinningBeforeCapture = false;
    /** @type {null|ReturnType<typeof dockRules.mountDock>} */
    let dock = null;
    let isAsking = false;
    /** @type {null|ReturnType<typeof runRules.mountStepCard>} */
    let stepCard = null;
    /** The run the card shows, as last read from or written to storage; storage is the truth, another tab may move it. */
    let run = null;
    /** True while an answer, a start or a stop of the run is being kept, so a second press cannot overwrite it. */
    let isKeepingRun = false;

    const say = (message) => {
        status.textContent = message;
    };
    const attachHost = () => {
        if (!host.isConnected) {
            document.documentElement.append(host);
        }
    };

    function labelPins() {
        pins.forEach((pin, index) => {
            const note = pin.note.trim();
            const isChosen = editing?.pin === pin;
            pin.button.setAttribute('aria-label', rules.accessibleNameOf(index + 1, pin.note));
            pin.button.classList.toggle('pin--selected', isChosen);
            pin.tab.textContent = String(index + 1);
            pin.bubble.textContent = note;
            pin.bubble.hidden = '' === note || isChosen;
        });
        hint.hidden = !isPinning || pins.length > 0;
        dock?.setPinCount(pins.length);
        if (null !== editing) {
            const pinNumber = pins.indexOf(editing.pin) + 1;
            noteField.setAttribute('aria-label', `Note for pin ${pinNumber}`);
            removeNoteButton.setAttribute('aria-label', `Remove pin ${pinNumber}`);
        }
    }

    function openEditor(pin, returnFocusTo) {
        editing = { pin, returnFocusTo };
        setNoteField(pin.note);
        editor.style.setProperty('--pin-x', `${pin.x_pct}%`);
        editor.style.top = `${pin.y_pct}%`;
        editor.classList.toggle('note-editor--left', opensLeft(pin));
        editor.classList.toggle('note-editor--above', editorOpensAbove(pin));
        editor.hidden = false;
        labelPins();
        noteField.focus();
    }

    // The note is kept as it is typed, so closing the editor never loses it.
    function closeEditor() {
        const returnFocusTo = editing?.returnFocusTo;
        editing = null;
        editor.hidden = true;
        labelPins();
        if (returnFocusTo?.isConnected) {
            returnFocusTo.focus();
        }
    }

    // Where focus goes once the control that had it is gone: the surface while pinning, else the dock's Pin button,
    // never the hidden surface.
    function focusAfterPinsGone() {
        if (isPinning) {
            surface.focus();
        } else {
            dock?.focusPinButton();
        }
    }

    function takeOffPage(pin) {
        pin.button.remove();
    }

    function removePin(pin) {
        const hadFocus = null !== shadow.activeElement;
        const index = pins.indexOf(pin);
        pins.splice(index, 1);
        takeOffPage(pin);
        if (editing?.pin === pin) {
            closeEditor();
        }
        labelPins();
        say('');
        if (!hadFocus) {
            return;
        }
        const neighbour = pins[index - 1] ?? pins[0];
        if (undefined === neighbour) {
            focusAfterPinsGone();
        } else {
            neighbour.button.focus();
        }
    }

    /** "Clear N pins": every pin goes, and pinning stays as it was. */
    function clearPins() {
        const hadFocus = null !== shadow.activeElement;
        if (null !== editing) {
            closeEditor();
        }
        for (const pin of pins.splice(0)) {
            takeOffPage(pin);
        }
        labelPins();
        say('');
        if (hadFocus) {
            focusAfterPinsGone();
        }
    }

    function dropPin(point, returnFocusTo) {
        if (pins.length >= rules.MAX_PINS) {
            say(rules.REFUSALS.tooManyPins);

            return;
        }
        say('');
        const button = make('button', 'pin', { type: 'button' });
        button.classList.toggle('pin--left', opensLeft(point));
        const tab = hiddenFromReaders(make('span', 'pin__tab'));
        const bubble = hiddenFromReaders(make('span', 'pin__note'));
        button.append(tab, bubble);
        const pin = { x_pct: point.x_pct, y_pct: point.y_pct, note: '', button, tab, bubble };
        placeAt(button, pin);
        button.addEventListener('click', onlyTrusted(() => openEditor(pin, button)));
        button.addEventListener('keydown', onlyTrusted((event) => {
            if ('Delete' === event.key || 'Backspace' === event.key) {
                event.preventDefault();
                removePin(pin);
            }
        }));
        pins.push(pin);
        pinLayer.append(button);
        openEditor(pin, returnFocusTo);
    }

    function startPinning() {
        attachHost();
        isPinning = true;
        surface.hidden = false;
        dock?.setPinning(true);
        labelPins();
        surface.focus();
    }

    function stopPinning(hadFocus = null !== shadow.activeElement) {
        isPinning = false;
        surface.hidden = true;
        dock?.setPinning(false);
        labelPins();
        if (hadFocus) {
            dock?.focusPinButton();
        }
    }

    /** Drops every pin; with the dock open it stays on the page and pinning ends, else the overlay goes. */
    function close() {
        if (null === dock) {
            host.remove();
            delete globalThis.pinroundOverlay;

            return;
        }
        const hadFocus = null !== shadow.activeElement;
        if (null !== editing) {
            closeEditor();
        }
        for (const pin of pins.splice(0)) {
            takeOffPage(pin);
        }
        say('');
        stopPinning(hadFocus);
    }

    /**
     * Asks the extension's service worker (src/background/) to act for the dock, and shows its answer there. One
     * request at a time: a press while one runs asks nothing and says to wait, as the dock's busy buttons do.
     */
    async function askExtension(action, value) {
        if (isAsking) {
            dock.say(dockRules.TEXTS.busy);

            return undefined;
        }
        isAsking = true;
        dock.setBusy(true);
        try {
            const answer = await chrome.runtime.sendMessage({ pinroundDock: action, value });
            dock.showAnswer(answer, action);

            return answer;
        } catch {
            dock.say(dockRules.TEXTS.notAnswered, true);

            return undefined;
        } finally {
            isAsking = false;
            dock.setBusy(false);
        }
    }

    // Every change to the stored run waits for the one before, so a capture clearing its tag never writes over an
    // answer given meanwhile.
    let runChanges = Promise.resolve();
    const afterRunChanges = (change) => {
        const changed = runChanges.then(change);
        runChanges = changed.catch(() => undefined);

        return changed;
    };

    function showRun(shownRun) {
        run = shownRun;
        stepCard.show(shownRun);
        dock.setPlaying(null !== shownRun);
    }

    /** A press on Play or the card: one at a time, a second press while one is kept is ignored; storage refusing is said. */
    function keepRunFor(press) {
        if (isKeepingRun) {
            return Promise.resolve();
        }
        isKeepingRun = true;

        return afterRunChanges(press).catch(() => dock.say(runRules.TEXTS.notKept, true)).finally(() => {
            isKeepingRun = false;
        });
    }

    // getRandomValues, not randomUUID: a content script on a plain http page is no secure context.
    const newRunId = () => Array.from(crypto.getRandomValues(new Uint8Array(16)), (byte) => byte.toString(16).padStart(2, '0')).join('');

    /**
     * Loads the notebook's scenario from the extension, which refuses it on a host not allowed, keeps it as the run and
     * shows its first step; the start page opens when the tab is elsewhere. isNext replaces a finished run with the
     * notebook's next scenario; otherwise an open run is never replaced.
     */
    function playScenario(scenarioIndex, isNext) {
        return keepRunFor(async () => {
            const stored = await runRules.loadRun(chrome.storage.local);
            if (isNext ? !runRules.isSameStep(stored, run) : null !== stored) {
                showRun(stored);
                dock.say(isNext ? runRules.TEXTS.runMoved : runRules.TEXTS.runOpen, true);

                return;
            }
            const answer = await askExtension('scenario', scenarioIndex);
            if (null === answer || 'object' !== typeof answer || !('scenario' in answer)) {
                return;
            }
            const scenario = runRules.checkedScenario(answer.scenario);
            if (null === scenario) {
                dock.say(dockRules.TEXTS.notAnswered, true);

                return;
            }
            const started = runRules.startedRun(scenario, newRunId(), stored?.pendingTag ?? null);
            await runRules.saveRun(chrome.storage.local, started);
            showRun(started);
            if (!runRules.isAtAddress(location.href, started.startUrl)) {
                askExtension('open-start', started.startUrl);
            }
        });
    }

    /** Records the card's step as the person answered it, unless another tab moved the run on or stopped it first. */
    function answerStep(result) {
        const answeredStep = run;

        return keepRunFor(async () => {
            const stored = await runRules.loadRun(chrome.storage.local);
            if (!runRules.isSameStep(stored, answeredStep)) {
                showRun(stored);
                dock.say(null === stored ? runRules.TEXTS.runStopped : runRules.TEXTS.runMoved, true);

                return;
            }
            const hadFocus = null !== shadow.activeElement;
            const answered = runRules.answeredRun(stored, result);
            await runRules.saveRun(chrome.storage.local, answered);
            showRun(answered);
            // The card's summary is no live region, so the status line says it as the last answer lands.
            const summary = runRules.isDone(answered) ? runRules.TEXTS.done(runRules.countsOf(answered)) : '';
            if ('problem' === result) {
                dock.say([runRules.TEXTS.problemTagged(answered.pendingTag.stepNumber), summary].filter((text) => '' !== text).join(' '));
                startPinning();

                return;
            }
            dock.say(summary);
            if (hadFocus) {
                stepCard.focusFirstButton();
            }
        });
    }

    function stopRun() {
        return keepRunFor(async () => {
            const hadFocus = null !== shadow.activeElement;
            await runRules.forgetRun(chrome.storage.local);
            showRun(null);
            dock.say('');
            if (hadFocus) {
                dock.focusPinButton();
            }
        });
    }

    const isSavedCapture = (answer) => null !== answer && 'object' === typeof answer && 'string' === typeof answer.message && true !== answer.isError;

    /**
     * Asks for a capture that carries the step "Found a problem" tagged, if one waits; the tag goes once that capture
     * is saved, so the capture after it, or a retry after a refusal, gets no tag or keeps it.
     */
    async function captureWithStepTag() {
        let tag;
        try {
            tag = (await afterRunChanges(() => runRules.loadRun(chrome.storage.local)))?.pendingTag ?? null;
        } catch {
            dock.say(runRules.TEXTS.notKept, true);

            return;
        }
        const answer = await askExtension('capture', tag ?? undefined);
        if (null === tag || !isSavedCapture(answer)) {
            return;
        }
        await afterRunChanges(async () => {
            const stored = await runRules.loadRun(chrome.storage.local);
            if (null === stored || !runRules.isSameTag(stored.pendingTag, tag)) {
                return;
            }
            const untagged = { ...stored, pendingTag: null };
            await runRules.saveRun(chrome.storage.local, untagged);
            showRun(untagged);
        }).catch(() => dock.say(runRules.TEXTS.notKept, true));
    }

    function runDockAction(action, value) {
        if ('pin' === action) {
            if (isPinning) {
                stopPinning();
            } else {
                startPinning();
            }
        } else if ('clear-pins' === action) {
            clearPins();
        } else if ('shrink' === action) {
            dock.toggleShrunk();
        } else if ('play' === action) {
            playScenario(0, false);
        } else if ('capture' === action) {
            captureWithStepTag();
        } else {
            askExtension(action, value);
        }
    }

    function openDock() {
        attachHost();
        if (null !== dock) {
            return dock.ready;
        }
        dock = dockRules.mountDock(shadow, {
            storage: chrome.storage.local,
            siteHost: location.host,
            viewport: { width: () => innerWidth, height: () => innerHeight },
            onAction: runDockAction,
            guardTyping,
        });
        dock.setPinning(isPinning);
        dock.setPinCount(pins.length);
        stepCard = runRules.mountStepCard(shadow, dock.stepSlot, {
            onAnswer: answerStep,
            onStop: stopRun,
            onNextScenario: () => playScenario((run?.scenarioIndex ?? -1) + 1, true),
        });
        // A run started before a click, a navigation or a reload shows its step again.
        afterRunChanges(async () => showRun(await runRules.loadRun(chrome.storage.local))).catch(() => dock.say(runRules.TEXTS.notKept, true));
        // Keys typed outside the overlay, only while the dock's Letter keys switch is on (WCAG 2.1.4); the shadow root's
        // own keydown listener below takes the ones inside it, always.
        document.addEventListener('keydown', onlyTrusted((event) => {
            const action = dockRules.actionOfKey(event);
            if (null !== action && host.isConnected && host !== event.target && dock.areLetterKeysOn()) {
                runDockAction(action);
            }
        }));
        askExtension('state');

        return dock.ready;
    }

    // rAF never fires in a hidden tab, so a capture there does not wait for ever.
    const afterNextPaint = () => new Promise((resolve) => {
        const fallback = setTimeout(resolve, 200);
        requestAnimationFrame(() => requestAnimationFrame(() => {
            clearTimeout(fallback);
            resolve();
        }));
    });

    // The focusable parts in Tab order, the order they have in the shadow root.
    const focusOrder = () => [
        surface,
        ...pins.map((pin) => pin.button),
        ...(editor.hidden ? [] : [noteField, saveNoteButton, removeNoteButton]),
        ...(null === dock ? [] : dock.focusables()),
        ...(null === stepCard || dock.isShrunk() ? [] : stepCard.focusables()),
    ];

    // Tab and Shift+Tab wrap inside the overlay, so focus never reaches the page's own controls under the tint.
    function keepFocusInside(event) {
        const order = focusOrder();
        const at = order.indexOf(shadow.activeElement);
        const wrapTo = event.shiftKey ? (at <= 0 ? order.at(-1) : null) : (order.length - 1 === at ? order[0] : null);
        if (null !== wrapTo) {
            event.preventDefault();
            wrapTo.focus();
        }
    }

    surface.addEventListener('click', onlyTrusted((event) => {
        const bounds = surface.getBoundingClientRect();
        dropPin({ x_pct: rules.percentOf(event.clientX - bounds.left, bounds.width), y_pct: rules.percentOf(event.clientY - bounds.top, bounds.height) }, surface);
    }));
    surface.addEventListener('keydown', onlyTrusted((event) => {
        if (Object.hasOwn(ARROW_STEPS, event.key)) {
            event.preventDefault();
            const [across, down] = ARROW_STEPS[event.key];
            const step = event.shiftKey ? SHIFT_STEP_PERCENT : 1;
            crosshairAt.x_pct = toPercentRange(crosshairAt.x_pct + (across * step));
            crosshairAt.y_pct = toPercentRange(crosshairAt.y_pct + (down * step));
            placeAt(crosshair, crosshairAt);
            say(`Crosshair at ${crosshairAt.x_pct}% from the left and ${crosshairAt.y_pct}% from the top.`);
        } else if ('Enter' === event.key || ' ' === event.key) {
            event.preventDefault();
            dropPin({ ...crosshairAt }, surface);
        }
    }));
    // The page's document.execCommand writes into the focused field with a trusted input event, but with no beforeinput
    // first, as typing, pasting and composing have. So at the person's trusted beforeinput the note keeps the field's
    // value and selection, and until that task ends takes an input only when the field holds exactly what the person's
    // edit makes of them. Anything else, such as the page replaying the key over the whole note from its capture-phase
    // input listener or a timer, is overwritten with that result, or with the note as it was when the result is not
    // known in full. Kinds with no model here (undo, redo, drop, a spelling fix) are undone. The beforeinput is heard
    // on the shadow root, where it stops, so no page listener runs after it: one the page cancelled lets nothing in.
    // Two gaps stay until the note editor moves into an iframe of the extension: with nothing selected, a deletion is
    // only held to the text on the caret's side it deletes from, so the page can delete more of that side during it;
    // and the selection is kept as it is at the beforeinput, so a page that selects the whole note (execCommand
    // selectAll) from its capture-phase keydown or beforeinput listener has the person's key replace it.
    shadow.addEventListener('beforeinput', (event) => {
        if (!keptValues.has(event.target) || !event.isTrusted || event.defaultPrevented) {
            return;
        }
        const modelled = personEditOf(event.inputType, event.data, event.target);
        const edit = null === modelled ? null : { ...modelled, field: event.target };
        personEdit = edit;
        setTimeout(() => {
            if (personEdit === edit) {
                personEdit = null;
            }
        }, 0);
    });
    // After the guard's own input listener, which guardTyping added first.
    noteField.addEventListener('input', () => {
        if (null === editing) {
            setNoteField('');

            return;
        }
        editing.pin.note = noteField.value;
        labelPins();
    });
    noteField.addEventListener('keydown', onlyTrusted((event) => {
        if ('Enter' === event.key && !event.shiftKey) {
            event.preventDefault();
            closeEditor();
        }
    }));
    // A submit is trusted even when the page's script asks for it (requestSubmit), so the editor closes on the button's
    // click instead, and a submit only ever stays on the page.
    saveNoteButton.addEventListener('click', onlyTrusted(closeEditor));
    removeNoteButton.addEventListener('click', onlyTrusted(() => {
        if (null !== editing) {
            removePin(editing.pin);
        }
    }));
    editor.addEventListener('submit', (event) => event.preventDefault());
    shadow.addEventListener('keydown', onlyTrusted((event) => {
        const dockAction = null === dock ? null : dockRules.actionOfKey(event);
        if ('Tab' === event.key) {
            if (isPinning) {
                keepFocusInside(event);
            }
        } else if (null !== dockAction) {
            event.preventDefault();
            runDockAction(dockAction);
        } else if (isPinning && SCROLL_KEYS.has(event.key) && !dockRules.isTypingTarget(event.target) && !(' ' === event.key && 'BUTTON' === event.target.tagName)) {
            event.preventDefault();
        } else if ('Escape' === event.key && null !== editing && editor.contains(event.target)) {
            event.preventDefault();
            closeEditor();
        } else if ('Escape' === event.key && null !== dock && dock.closePanels()) {
            event.preventDefault();
        } else if ('Escape' === event.key && isPinning) {
            event.preventDefault();
            close();
        }
    }));
    // On the host: Chrome makes a wheel cancelable only over an element with a blocking listener, and a shadow root is
    // no element. The host's listener sees the host as the target, so the note field is found by the pointer over it.
    // Ctrl+wheel is the browser's zoom, which keeps every pin in place, since a pin is a percent of the viewport.
    host.addEventListener('wheel', onlyTrusted((event) => {
        if (event.ctrlKey || !isPinning) {
            return;
        }
        event.preventDefault();
        // The note is one line, so it scrolls sideways; a mouse wheel only turns up and down.
        if (noteField.matches(':hover')) {
            noteField.scrollLeft += 0 !== event.deltaX ? event.deltaX : event.deltaY;
        }
    }), { passive: false });
    for (const type of OVERLAY_EVENTS) {
        shadow.addEventListener(type, (event) => event.stopPropagation());
    }

    globalThis.pinroundOverlay = {
        /** Turns pinning on, with the overlay in the page and focus on its surface, where a keyboard user starts. */
        startPinning,
        /** Opens the dock in the overlay's root, as the site last placed it; resolves once it is placed. */
        openDock,
        /** Puts the overlay back after hideForCapture, with a white flash for the shot taken and focus on the surface if pinning was on. */
        show() {
            attachHost();
            const flash = hiddenFromReaders(make('div', 'flash'));
            shadow.append(flash);
            setTimeout(() => flash.remove(), FLASH_MS);
            if (wasPinningBeforeCapture) {
                surface.focus();
            }
        },
        /** Takes the overlay out of the page until show(), and answers the pins once the page is painted without it. */
        async hideForCapture() {
            const answer = pins.map(({ x_pct, y_pct, note }) => ({ x_pct, y_pct, note }));
            wasPinningBeforeCapture = isPinning;
            host.remove();
            await afterNextPaint();

            return answer;
        },
        close,
    };
    placeAt(crosshair, crosshairAt);
    labelPins();
})();
