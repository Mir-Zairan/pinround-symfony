// The pin overlay that "Pin this page" injects into the page, after pin-rules.js. The pins live in the extension's
// isolated world, where the popup reads them through executeScript (globalThis.pinroundOverlay) and checks them again.
// The shadow root is closed and only this script holds it, so the page's script cannot reach the pins or read the
// notes through the DOM, and every handler ignores an event the page dispatched (isTrusted false) or, for a note,
// wrote with document.execCommand. The page can still remove or hide the host element. The root holds every style, so
// none reach the page, and :host's !important declarations win over the page's own. Notes only ever go in through
// textContent.
(() => {
    if (undefined !== globalThis.pinroundOverlay) {
        globalThis.pinroundOverlay.show();
        globalThis.pinroundOverlay.focusSurface();

        return;
    }
    const rules = globalThis.pinroundPinRules;
    const ARROW_STEPS = { ArrowLeft: [-1, 0], ArrowRight: [1, 0], ArrowUp: [0, -1], ArrowDown: [0, 1] };
    const SHIFT_STEP_PERCENT = 10;
    // Stopped at the shadow root, so the page's listeners on the way up never hear a key or click meant for a pin; its
    // capture-phase listeners on window and document still run first.
    const OVERLAY_EVENTS = ['click', 'mousedown', 'mouseup', 'pointerdown', 'pointerup', 'keydown', 'keyup', 'keypress', 'input'];
    // A pin is a percent of the viewport, so the page must not scroll under it while the overlay is open, or the pin
    // lands on other content in the picture. Wheel and touch scrolling are blocked over the whole overlay, and these
    // keys everywhere but in the note field and Space on a button, which presses it. The page's own script, and a drag
    // on the page's scrollbar, can still scroll it.
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
        }
        * { box-sizing: border-box; font: 14px/1.4 system-ui, sans-serif; }
        .surface { position: absolute; inset: 0; background: rgb(22 29 27 / 8%); cursor: crosshair; outline: none; }
        .surface:focus-visible { box-shadow: inset 0 0 0 3px #2f6fd6; }
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
        .pin:focus-visible { outline: 2px solid #2f6fd6; outline-offset: 5px; }
        .pin__tab {
            position: absolute;
            top: -12px;
            right: -12px;
            min-width: 20px;
            padding: 0 4px;
            border-radius: 4px;
            background: #161d1b;
            color: #ffffff;
            font-size: 12px;
            font-weight: 700;
            line-height: 20px;
            text-align: center;
        }
        .pin__note {
            display: none;
            position: absolute;
            top: calc(100% + 8px);
            left: 50%;
            width: max-content;
            max-width: 240px;
            max-height: 160px;
            overflow: hidden;
            padding: 4px 8px;
            border-radius: 6px;
            background: #161d1b;
            color: #ffffff;
            text-align: left;
            white-space: pre-wrap;
            overflow-wrap: anywhere;
        }
        .pin--left .pin__note { left: auto; right: 50%; }
        .pin--above .pin__note { top: auto; bottom: calc(100% + 8px); }
        .pin:hover .pin__note:not([hidden]), .pin:focus-visible .pin__note:not([hidden]) { display: block; }
        .note-editor {
            --shift-x: 20px;
            --shift-y: 20px;
            position: absolute;
            display: grid;
            gap: 6px;
            width: 260px;
            padding: 8px;
            border-radius: 8px;
            background: #ffffff;
            color: #161d1b;
            box-shadow: 0 0 0 1px #161d1b, 0 4px 12px rgb(0 0 0 / 30%);
            transform: translate(var(--shift-x), var(--shift-y));
        }
        .note-editor[hidden] { display: none; }
        .note-editor--left { --shift-x: calc(-100% - 20px); }
        .note-editor--above { --shift-y: calc(-100% - 20px); }
        .note-editor label { display: grid; gap: 4px; }
        .note-editor textarea { width: 100%; padding: 6px 8px; border: 1px solid #8a8f98; border-radius: 6px; resize: vertical; color: inherit; background: #ffffff; }
        .note-editor button { justify-self: start; padding: 6px 12px; border: 1px solid #2f6fd6; border-radius: 6px; background: #2f6fd6; color: #ffffff; cursor: pointer; }
        .note-editor :focus-visible { outline: 2px solid #2f6fd6; outline-offset: 2px; }
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
    // The same sides for the note editor and a pin's note bubble, so neither runs off the viewport.
    const opensLeft = (point) => point.x_pct > 50;
    const opensAbove = (point) => point.y_pct > 50;
    const onlyTrusted = (handler) => (event) => {
        if (event.isTrusted) {
            handler(event);
        }
    };

    const host = document.createElement('pinround-overlay');
    const shadow = host.attachShadow({ mode: 'closed' });
    const sheet = new CSSStyleSheet();
    sheet.replaceSync(STYLES);
    shadow.adoptedStyleSheets = [sheet];

    const surface = make('div', 'surface', { tabIndex: 0 });
    surface.setAttribute('role', 'application');
    surface.setAttribute('aria-label', 'Pin overlay');
    surface.setAttribute('aria-describedby', 'pinround-keyboard-help');
    const crosshair = hiddenFromReaders(make('div', 'crosshair'));
    surface.append(crosshair);
    const pinLayer = make('div', 'pins');
    const editor = make('form', 'note-editor', { hidden: true });
    const editorCaption = make('span');
    const noteField = make('textarea', '', { maxLength: rules.NOTE_MAX_LENGTH, rows: 3 });
    const editorLabel = make('label');
    editorLabel.append(editorCaption, noteField);
    const saveNoteButton = make('button', '', { type: 'button', textContent: 'Save note' });
    editor.append(editorLabel, saveNoteButton);
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

    const say = (message) => {
        status.textContent = message;
    };

    function labelPins() {
        pins.forEach((pin, index) => {
            const note = pin.note.trim();
            pin.button.setAttribute('aria-label', rules.accessibleNameOf(index + 1, pin.note));
            pin.tab.textContent = String(index + 1);
            pin.bubble.textContent = note;
            pin.bubble.hidden = '' === note;
        });
        hint.hidden = pins.length > 0;
        if (null !== editing) {
            editorCaption.textContent = `Note for pin ${pins.indexOf(editing.pin) + 1}`;
        }
    }

    function openEditor(pin, returnFocusTo) {
        editing = { pin, returnFocusTo };
        noteField.value = pin.note;
        placeAt(editor, pin);
        editor.classList.toggle('note-editor--left', opensLeft(pin));
        editor.classList.toggle('note-editor--above', opensAbove(pin));
        editor.hidden = false;
        labelPins();
        noteField.focus();
    }

    // The note is kept as it is typed, so closing the editor never loses it.
    function closeEditor() {
        const returnFocusTo = editing?.returnFocusTo;
        editing = null;
        editor.hidden = true;
        if (returnFocusTo?.isConnected) {
            returnFocusTo.focus();
        }
    }

    function removePin(pin) {
        const index = pins.indexOf(pin);
        pins.splice(index, 1);
        pin.button.remove();
        if (editing?.pin === pin) {
            closeEditor();
        }
        labelPins();
        say('');
        const nextFocus = (pins[index - 1] ?? pins[0])?.button ?? surface;
        nextFocus.focus();
    }

    function dropPin(point, returnFocusTo) {
        if (pins.length >= rules.MAX_PINS) {
            say(rules.REFUSALS.tooManyPins);

            return;
        }
        say('');
        const button = make('button', 'pin', { type: 'button' });
        button.classList.toggle('pin--left', opensLeft(point));
        button.classList.toggle('pin--above', opensAbove(point));
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

    function close() {
        host.remove();
        delete globalThis.pinroundOverlay;
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
    const focusOrder = () => [surface, ...pins.map((pin) => pin.button), ...(editor.hidden ? [] : [noteField, saveNoteButton])];

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
    // The page's document.execCommand('insertText') writes into the focused field with a trusted input event, but with
    // no beforeinput first, as typing, pasting and composing have. So only an input right after a trusted beforeinput
    // counts, which also leaves out an input the page dispatched, and any other is undone.
    let isNoteInputFromPerson = false;
    noteField.addEventListener('beforeinput', (event) => {
        isNoteInputFromPerson = event.isTrusted;
    });
    noteField.addEventListener('input', () => {
        const isFromPerson = isNoteInputFromPerson;
        isNoteInputFromPerson = false;
        if (!isFromPerson) {
            noteField.value = editing?.pin.note ?? '';

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
    editor.addEventListener('submit', (event) => event.preventDefault());
    shadow.addEventListener('keydown', onlyTrusted((event) => {
        if ('Tab' === event.key) {
            keepFocusInside(event);
        } else if (SCROLL_KEYS.has(event.key) && noteField !== event.target && !(' ' === event.key && 'BUTTON' === event.target.tagName)) {
            event.preventDefault();
        } else if ('Escape' === event.key) {
            event.preventDefault();
            if (null !== editing && editor.contains(event.target)) {
                closeEditor();
            } else {
                close();
            }
        }
    }));
    // On the host: Chrome makes a wheel cancelable only over an element with a blocking listener, and a shadow root is
    // no element. The host's listener sees the host as the target, so the note field is found by the pointer over it.
    host.addEventListener('wheel', onlyTrusted((event) => {
        event.preventDefault();
        if (noteField.matches(':hover')) {
            noteField.scrollTop += event.deltaY;
        }
    }), { passive: false });
    for (const type of OVERLAY_EVENTS) {
        shadow.addEventListener(type, (event) => event.stopPropagation());
    }

    globalThis.pinroundOverlay = {
        show() {
            if (!host.isConnected) {
                document.documentElement.append(host);
            }
        },
        focusSurface() {
            surface.focus();
        },
        /** Takes the overlay out of the page until show(), and answers the pins once the page is painted without it. */
        async hideForCapture() {
            const answer = pins.map(({ x_pct, y_pct, note }) => ({ x_pct, y_pct, note }));
            host.remove();
            await afterNextPaint();

            return answer;
        },
        close,
    };
    placeAt(crosshair, crosshairAt);
    globalThis.pinroundOverlay.show();
    surface.focus();
})();
