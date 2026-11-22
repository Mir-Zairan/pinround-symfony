// The dock: pinround's one-row toolbar on the tested page, built by overlay.js inside the overlay's own closed shadow
// root, so the page can neither find nor read it. Injected as a classic script before overlay.js, like pin-rules.js;
// node --test imports it for its rules. The whole file is one expression, so a second injection keeps the first.
// Every text from pinround or the page goes in through textContent or an option's text, never as markup. The colours
// are the overlay's tokens (overlay.js :host), so the bar is dark on a light screen and light on a dark one.
globalThis.pinroundDock ??= (() => {
    /** The local storage key's prefix; the site's host follows, so each site keeps its own side and height. */
    const PLACE_KEY_PREFIX = 'pinroundDockPlace:';
    /** The local storage key of the Letter keys switch, one for every site; only a stored false turns the keys off. */
    const LETTER_KEYS_KEY = 'pinroundDockLetterKeys';
    const SIDES = ['left', 'right'];
    const DEFAULT_PLACE = Object.freeze({ side: 'right', topPct: 50 });
    /** The gap between the dock and the viewport's edges, in CSS pixels. */
    const EDGE_GAP_PX = 8;
    /** How far one arrow key on the grip moves the dock up or down, in percent of the room it has. */
    const GRIP_STEP_PCT = 5;
    /** How far a press on the shrunk puck may move, in CSS pixels, and still be a click that opens the dock. */
    const PUCK_CLICK_SLOP_PX = 4;
    const KEY_ACTIONS = Object.freeze({ p: 'pin', c: 'capture', h: 'shrink' });
    const TYPING_TAGS = new Set(['INPUT', 'TEXTAREA', 'SELECT']);
    /** A cap on what the extension's answer may put in the picker, so a broken answer cannot flood the page. */
    const MAX_CHOICES = 200;
    // The caps of capture.js and new-report.js, which a classic script cannot import; a test keeps them in step.
    const MAX_SHOTS = 10;
    const MAX_PIN_SPOTS = 100;
    const REPORT_TITLE_MAX_LENGTH = 200;
    const PROJECT_KEY_MAX_LENGTH = 20;
    // waiting-captures.js's thumbnail: its prefix, caps and the box it covers, which is this panel's box at 2x.
    const THUMBNAIL_DATA_URL_PREFIX = 'data:image/jpeg;base64,';
    const MAX_THUMBNAIL_DATA_URL_LENGTH = 200_000;
    const MAX_THUMBNAIL_SIDE_PX = 2000;
    const THUMBNAIL_BOX_PX = Object.freeze({ width: 184, height: 120 });

    const TEXTS = Object.freeze({
        toolbar: 'pinround dock',
        grip: 'Move the dock',
        gripHint: 'Drag to move. Arrow keys move it too.',
        target: 'Save to',
        noTarget: 'Choose where to save',
        pin: 'Pin',
        pinHint: 'Pin mode (P)',
        clearPins: (pinCount) => `Clear ${pinCount} ${1 === pinCount ? 'pin' : 'pins'}`,
        clearPinsHint: 'Remove every pin on this page',
        capture: 'Capture',
        captureHint: 'Capture (C)',
        play: 'Play',
        saveDraft: 'Save draft',
        saveDraftHint: 'Review the captures',
        saveDraftCannot: 'Draft rounds and notebooks keep each capture as you take it.',
        saveDraftCount: (count) => `Save draft, ${count} in it`,
        shrink: 'Shrink the dock',
        shrinkHint: 'Shrink (H)',
        letterKeys: 'Letter keys',
        letterKeysShort: 'Keys',
        letterKeysHint: 'While on, P, C and H work anywhere on the page. Off, they work only in the dock.',
        switchedOn: 'On',
        switchedOff: 'Off',
        busy: 'Still working on the last request. Try again when it is done.',
        open: 'Open the pinround dock',
        openWithCount: (count) => `Open the pinround dock, ${count} ${1 === count ? 'capture' : 'captures'}`,
        notAnswered: 'The extension did not answer. Open its popup and try again.',
        savePanel: 'Save draft',
        saveCount: (count) => `Save ${count} ${1 === count ? 'capture' : 'captures'}`,
        shotCaption: (shotNumber, pinCount) => `Shot ${shotNumber} · ${pinCount} ${1 === pinCount ? 'pin' : 'pins'}`,
        reportTitle: 'What is wrong, in one line',
        project: 'Project key',
        projectExample: 'SHOP',
        goesTo: 'Goes to a new report draft. Nobody is told yet. You send it from pinround.',
        keepTesting: 'Keep testing',
        titleRefused: 'Write what is wrong in one line, at most 200 characters.',
        projectRefused: 'Type the project key, for example SHOP.',
    });

    const STYLES = `
        .dock, .dock-handle {
            --top-pct: 50;
            position: absolute;
            top: calc(${EDGE_GAP_PX}px + (100% - ${2 * EDGE_GAP_PX}px) * var(--top-pct) / 100);
            transform: translateY(calc(var(--top-pct) * -1%));
            pointer-events: auto;
            font: 500 0.8rem/1.2 var(--font);
        }
        .dock--left { left: ${EDGE_GAP_PX}px; }
        .dock--right { right: ${EDGE_GAP_PX}px; }
        .dock--dragging { transform: none; right: auto; }
        .dock[hidden], .dock-handle[hidden], .dock [hidden] { display: none; }
        .dock__bar {
            display: flex;
            align-items: center;
            gap: 2px;
            padding: 4px;
            border-radius: 12px;
            background: var(--dock);
            color: var(--dock-fg);
            box-shadow: 0 8px 24px rgb(0 0 0 / 25%);
            white-space: nowrap;
            /* A phone is narrower than the bar: it wraps onto a second row rather than leave the screen. */
            flex-wrap: wrap;
            max-width: calc(100vw - ${2 * EDGE_GAP_PX}px);
        }
        .dock button, .dock select, .dock input { font: inherit; color: inherit; }
        .dock__bar button {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 8px;
            border: none;
            border-radius: 8px;
            background: transparent;
            cursor: pointer;
        }
        .dock__bar button:hover { background: var(--dock-hover); }
        .dock__bar button[aria-pressed="true"] { background: var(--primary); color: var(--on-primary); }
        .dock__bar button[aria-disabled="true"] { opacity: 0.45; cursor: default; }
        .dock__bar button[aria-disabled="true"]:hover { background: transparent; }
        .dock :focus-visible, .dock-handle:focus-visible { outline: 2px solid var(--primary); outline-offset: 2px; }
        .dock__grip { cursor: grab; padding: 4px 6px; }
        .dock--dragging .dock__grip { cursor: grabbing; }
        /* The app's logo mark (base.html.twig, a 26-unit square) in shares of its size: a pin's head and stem. */
        .dock__logo { position: relative; display: block; flex: none; width: 22px; height: 22px; border-radius: 27%; background: #006b5f; }
        .dock__logo::before, .dock__logo::after { content: ''; position: absolute; left: 50%; background: #ffffff; transform: translateX(-50%); }
        .dock__logo::before { top: 26.9%; width: 34.6%; height: 34.6%; border-radius: 50%; }
        .dock__logo::after { top: 57.3%; width: 8.5%; height: 27.7%; border-radius: 999px; }
        .dock__dots { flex: none; width: 6px; height: 14px; opacity: 0.7; background: radial-gradient(circle, currentColor 1px, transparent 1.5px) 0 0 / 3px 4.67px; }
        .dock__target {
            max-width: 150px;
            padding: 5px 6px;
            border: 1px solid currentColor;
            border-radius: 8px;
            background: transparent;
            text-overflow: ellipsis;
            cursor: pointer;
        }
        .dock__target option { color: var(--fg); background: var(--panel); }
        .dock__target[aria-disabled="true"] { opacity: 0.45; }
        .dock__sep { flex: none; width: 1px; height: 22px; margin: 0 4px; background: currentColor; opacity: 0.2; }
        .dock kbd { font: inherit; font-size: 0.7rem; opacity: 0.6; }
        .dock__icon { position: relative; flex: none; display: inline-block; box-sizing: border-box; }
        .dock__icon--pin { width: 12px; height: 12px; border: 2px solid currentColor; border-radius: 50%; }
        .dock__icon--capture { width: 15px; height: 11px; border: 2px solid currentColor; border-radius: 3px; }
        .dock__icon--capture::after {
            content: '';
            position: absolute;
            left: 50%;
            top: 50%;
            width: 4px;
            height: 4px;
            border-radius: 50%;
            background: currentColor;
            transform: translate(-50%, -50%);
        }
        .dock__icon--play { width: 0; height: 0; border-left: 9px solid currentColor; border-top: 6px solid transparent; border-bottom: 6px solid transparent; }
        .dock__icon--shrink { width: 12px; height: 2px; background: currentColor; }
        .dock__send { background: var(--primary) !important; color: var(--on-primary); }
        .dock__count {
            min-width: 18px;
            padding: 0 5px;
            border-radius: 999px;
            background: var(--on-primary);
            color: var(--primary);
            font-size: 0.7rem;
            font-weight: 700;
            line-height: 18px;
            text-align: center;
        }
        /* Secondary: no fill when on, the state is the word beside it. */
        .dock__bar .dock__keys { padding: 6px 6px; font-size: 0.7rem; opacity: 0.8; }
        .dock__bar .dock__keys[aria-pressed="true"] { background: transparent; color: inherit; }
        .dock__keys .dock__switch { font-weight: 700; }
        .dock__pops {
            position: absolute;
            top: calc(100% + 8px);
            display: grid;
            gap: 8px;
            justify-items: end;
            width: max-content;
        }
        .dock--left .dock__pops { left: 0; justify-items: start; }
        .dock--right .dock__pops { right: 0; }
        .dock--low .dock__pops { top: auto; bottom: calc(100% + 8px); }
        .dock-pop, .dock-step {
            display: grid;
            gap: 8px;
            box-sizing: border-box;
            width: min(340px, calc(100vw - 64px));
            padding: 12px;
            border: 1px solid var(--line);
            border-radius: 12px;
            background: var(--panel);
            color: var(--fg);
            box-shadow: 0 8px 24px rgb(0 0 0 / 18%);
            font: 400 0.875rem/1.4 var(--font);
            white-space: normal;
        }
        .dock-pop h3 { margin: 0; font: 700 1rem/1.3 var(--heading-font); }
        .dock-pop p { margin: 0; }
        .dock-save__shots { display: flex; flex-wrap: wrap; gap: 8px; max-height: 220px; overflow: auto; }
        .dock-save__shot { display: grid; gap: 4px; margin: 0; font-size: 0.75rem; color: var(--muted); }
        .dock-save__thumb { position: relative; width: ${THUMBNAIL_BOX_PX.width / 2}px; height: ${THUMBNAIL_BOX_PX.height / 2}px; border: 1px solid var(--line); border-radius: 6px; background: var(--bg); overflow: hidden; }
        .dock-save__picture { position: absolute; inset: 0; display: block; width: 100%; height: 100%; }
        .dock-save__dot {
            position: absolute;
            z-index: 1;
            width: 8px;
            height: 8px;
            border: 1.5px solid #ffffff;
            border-radius: 50%;
            box-shadow: 0 0 0 1px #161d1b;
            transform: translate(-50%, -50%);
        }
        .dock-save__field { display: grid; gap: 4px; font-weight: 500; }
        .dock-save__field input {
            box-sizing: border-box;
            width: 100%;
            padding: 8px 10px;
            border: 1px solid var(--line);
            border-radius: 8px;
            background: var(--panel);
        }
        .dock-save__field input[aria-invalid="true"] { border-color: var(--danger); }
        .dock-save__error { color: var(--danger); font-weight: 500; }
        .dock-save__error:empty { display: none; }
        .dock-save__goes { color: var(--muted); }
        .dock-save__buttons, .dock-step__buttons { display: flex; flex-wrap: wrap; gap: 8px; }
        .dock-pop button, .dock-step button {
            padding: 8px 14px;
            border: 1px solid var(--line);
            border-radius: 8px;
            background: transparent;
            font-weight: 500;
            cursor: pointer;
        }
        .dock-pop .dock-btn--primary { border-color: var(--primary); background: var(--primary); color: var(--on-primary); }
        .dock__status {
            max-width: min(340px, calc(100vw - 64px));
            max-height: 120px;
            margin: 0;
            padding: 8px 12px;
            overflow: auto;
            border-radius: 10px;
            background: var(--dock);
            color: var(--dock-fg);
            box-shadow: 0 8px 24px rgb(0 0 0 / 18%);
            font: 500 0.8rem/1.4 var(--font);
            white-space: normal;
            overflow-wrap: anywhere;
        }
        .dock__status:empty { display: none; }
        .dock__status--error { background: var(--panel); color: var(--danger); border: 1px solid var(--danger); }
        .dock-handle {
            display: grid;
            place-items: center;
            width: 46px;
            height: 46px;
            padding: 0;
            /* The brand teal in both schemes, not --primary: 6.4:1 on a white page and 3.3:1 on a black one. */
            border: 3px solid #006b5f;
            border-radius: 50%;
            background: var(--dock);
            box-shadow: 0 4px 16px rgb(0 0 0 / 45%);
            cursor: pointer;
            touch-action: none;
        }
        .dock-handle .dock__logo { width: 30px; height: 30px; }
        .dock-handle .dock__count { position: absolute; top: -4px; right: -4px; background: var(--primary); color: var(--on-primary); }
        @media (max-width: 600px) {
            .dock__label, .dock kbd { display: none; }
            .dock__target { max-width: 96px; }
        }
    `;

    const clampedPercent = (value) => Math.min(100, Math.max(0, value));

    /** @returns {'left'|'right'} the side whose edge is nearer the dock's centre; the exact middle goes right */
    function nearerSide(centerX, viewportWidth) {
        return centerX < viewportWidth / 2 ? 'left' : 'right';
    }

    /**
     * The dock's height on its side, from where its top edge was dropped: 0 at the top gap, 100 where its bottom edge
     * meets the bottom gap, so a stored height keeps the whole dock in view at any viewport size.
     *
     * @returns {number} a whole percent, 0 when the viewport has no room to move the dock
     */
    function topPercentOf(topPx, viewportHeight, dockHeight) {
        const room = viewportHeight - (2 * EDGE_GAP_PX) - dockHeight;
        if (!(room > 0) || !Number.isFinite(topPx)) {
            return 0;
        }

        return Math.round(clampedPercent(((topPx - EDGE_GAP_PX) / room) * 100));
    }

    /** @returns {{side: 'left'|'right', topPct: number}} what is stored, or the default for anything else */
    function checkedPlace(stored) {
        if (null === stored || 'object' !== typeof stored || !SIDES.includes(stored.side)
            || 'number' !== typeof stored.topPct || !Number.isFinite(stored.topPct)) {
            return { ...DEFAULT_PLACE };
        }

        return { side: stored.side, topPct: Math.round(clampedPercent(stored.topPct)) };
    }

    const placeKeyOf = (siteHost) => `${PLACE_KEY_PREFIX}${siteHost}`;

    async function loadPlace(storage, siteHost) {
        const key = placeKeyOf(siteHost);

        return checkedPlace((await storage.get(key))[key] ?? null);
    }

    async function savePlace(storage, siteHost, place) {
        await storage.set({ [placeKeyOf(siteHost)]: checkedPlace(place) });
    }

    /** @returns {Promise<boolean>} whether P, C and H work anywhere on the page; on unless turned off */
    async function loadLetterKeys(storage) {
        return false !== (await storage.get(LETTER_KEYS_KEY))[LETTER_KEYS_KEY];
    }

    /** True for a field the person types in: the page's own keys there are never the dock's. */
    function isTypingTarget(element) {
        if (null === element || 'object' !== typeof element) {
            return false;
        }

        return true === element.isContentEditable || TYPING_TAGS.has(String(element.tagName ?? '').toUpperCase());
    }

    /**
     * The dock action a key press asks for, or null: only a trusted P, C or H with no Ctrl, Alt or Meta (Ctrl+C stays
     * copy), not held down, not mid-composition, and not typed into a field.
     *
     * @returns {null|'pin'|'capture'|'shrink'}
     */
    function actionOfKey(event) {
        if (!event.isTrusted || event.ctrlKey || event.altKey || event.metaKey || event.repeat || event.isComposing || 'string' !== typeof event.key) {
            return null;
        }
        // In the page's own open shadow roots the field is the path's first node; event.target is only its host.
        const typedInto = event.composedPath?.()[0] ?? event.target;
        if (isTypingTarget(typedInto) || isTypingTarget(event.target)) {
            return null;
        }

        return KEY_ACTIONS[event.key.toLowerCase()] ?? null;
    }

    const isPercent = (value) => 'number' === typeof value && Number.isFinite(value) && value >= 0 && value <= 100;
    const isSide = (value) => Number.isInteger(value) && value >= 1 && value <= MAX_THUMBNAIL_SIDE_PX;

    /** @returns {{dataUrl: string, width: number, height: number}|null} the thumbnail, or null for none or one it cannot read */
    function checkedThumbnail(value) {
        if (null === value || 'object' !== typeof value || 'string' !== typeof value.dataUrl || !value.dataUrl.startsWith(THUMBNAIL_DATA_URL_PREFIX)
            || value.dataUrl.length > MAX_THUMBNAIL_DATA_URL_LENGTH || !isSide(value.width) || !isSide(value.height)) {
            return null;
        }

        return { dataUrl: value.dataUrl, width: value.width, height: value.height };
    }

    /**
     * The part of a picture that covers a box of another shape, as CSS's object-fit: cover shows it: the whole of one
     * side, the middle of the other.
     *
     * @returns {{x: number, y: number, width: number, height: number}} in the picture's pixels
     */
    function coverCropOf(pictureWidth, pictureHeight, boxWidth, boxHeight) {
        const scale = Math.max(boxWidth / pictureWidth, boxHeight / pictureHeight);
        const width = boxWidth / scale;
        const height = boxHeight / scale;

        return { x: (pictureWidth - width) / 2, y: (pictureHeight - height) / 2, width, height };
    }

    /**
     * Where a pin of the whole picture shows in the box its cover crop fills; a pin cut off by the crop stays at the
     * box's edge, so every pin the caption counts is seen.
     *
     * @returns {{x_pct: number, y_pct: number}}
     */
    function coverSpotOf(spot, pictureWidth, pictureHeight, boxWidth, boxHeight) {
        const crop = coverCropOf(pictureWidth, pictureHeight, boxWidth, boxHeight);

        return {
            x_pct: clampedPercent((((spot.x_pct / 100) * pictureWidth) - crop.x) / crop.width * 100),
            y_pct: clampedPercent((((spot.y_pct / 100) * pictureHeight) - crop.y) / crop.height * 100),
        };
    }

    /** Draws the thumbnail into the canvas, cropped to cover it. A canvas, not an img: the page's CSP governs img sources. */
    async function drawCover(canvas, thumbnail) {
        const bytes = Uint8Array.from(atob(thumbnail.dataUrl.slice(THUMBNAIL_DATA_URL_PREFIX.length)), (character) => character.charCodeAt(0));
        const picture = await createImageBitmap(new Blob([bytes], { type: 'image/jpeg' }));
        try {
            const crop = coverCropOf(picture.width, picture.height, canvas.width, canvas.height);
            canvas.getContext('2d').drawImage(picture, crop.x, crop.y, crop.width, crop.height, 0, 0, canvas.width, canvas.height);
        } finally {
            picture.close();
        }
    }

    /** @returns {{shots: Array<{pinSpots: Array<{x_pct: number, y_pct: number}>, thumbnail: null|{dataUrl: string, width: number, height: number}}>, needsTitle: boolean, needsProject: boolean}|null|undefined} undefined when unreadable */
    function checkedWaitingReport(value) {
        if (undefined === value || null === value) {
            return null;
        }
        if ('object' !== typeof value || !Array.isArray(value.shots) || value.shots.length > MAX_SHOTS
            || 'boolean' !== typeof value.needsTitle || 'boolean' !== typeof value.needsProject) {
            return undefined;
        }
        const shots = [];
        for (const shot of value.shots) {
            if (null === shot || 'object' !== typeof shot || !Array.isArray(shot.pinSpots) || shot.pinSpots.length > MAX_PIN_SPOTS
                || !shot.pinSpots.every((spot) => null !== spot && 'object' === typeof spot && isPercent(spot.x_pct) && isPercent(spot.y_pct))) {
                return undefined;
            }
            // A thumbnail it cannot read only costs the picture: the shot and its pins still show.
            shots.push({ pinSpots: shot.pinSpots.map(({ x_pct, y_pct }) => ({ x_pct, y_pct })), thumbnail: checkedThumbnail(shot.thumbnail ?? null) });
        }

        return { shots, needsTitle: value.needsTitle, needsProject: value.needsProject };
    }

    /**
     * @typedef {{choices: Array<{value: string, label: string}>, chosen: string, count: number|null, canSave: boolean,
     *   newReport: null|{shots: Array<{pinSpots: Array<{x_pct: number, y_pct: number}>, thumbnail: null|{dataUrl: string,
     *   width: number, height: number}}>, needsTitle: boolean, needsProject: boolean}}} DockState
     * @returns {DockState|null} the state as the extension answered it, or null when it is not one
     */
    function checkedState(state) {
        if (null === state || 'object' !== typeof state || !Array.isArray(state.choices) || 'string' !== typeof state.chosen
            || 'boolean' !== typeof state.canSave || !(null === state.count || (Number.isInteger(state.count) && state.count >= 0))) {
            return null;
        }
        const newReport = checkedWaitingReport(state.newReport);
        if (undefined === newReport) {
            return null;
        }
        const choices = state.choices.slice(0, MAX_CHOICES).filter((choice) => 'string' === typeof choice?.value && 'string' === typeof choice.label);

        return { choices: choices.map(({ value, label }) => ({ value, label })), chosen: state.chosen, count: state.count, canSave: state.canSave, newReport };
    }

    /**
     * Why the Save draft panel cannot send what was typed, as new-report.js would refuse it, or null when it can.
     *
     * @param {{reportTitle: string, project: string}} typed
     * @param {{needsTitle: boolean, needsProject: boolean}} asked which fields the panel shows
     * @returns {null|string}
     */
    function refusalOfTyped(typed, asked) {
        const reportTitle = typed.reportTitle.trim();
        if (asked.needsTitle && ('' === reportTitle || [...reportTitle].length > REPORT_TITLE_MAX_LENGTH)) {
            return TEXTS.titleRefused;
        }
        const project = typed.project.trim();
        if (asked.needsProject && ('' === project || [...project].length > PROJECT_KEY_MAX_LENGTH)) {
            return TEXTS.projectRefused;
        }

        return null;
    }

    /**
     * Builds the dock into `shadow` and places it where this site last had it.
     *
     * @param {ShadowRoot} shadow the overlay's closed root
     * @param {{storage: {get: Function, set: Function, onChanged: object}, siteHost: string, viewport: {width: () => number,
     *   height: () => number}, onAction: (action: string, value?: unknown) => void, guardTyping?: (field: HTMLInputElement) => (value: string) => void}} options
     *   storage is the extension's local storage; onAction hears pin, clear-pins, capture, play, choose (with the picked
     *   value) and save-draft (with {reportTitle, project}); shrink, Letter keys and the Save draft panel stay in here.
     *   guardTyping puts the overlay's guard against the page writing into a field the person types in on the field, and
     *   answers how the dock sets that field's value. Play's step card goes in the answer's stepSlot.
     */
    function mountDock(shadow, { storage, siteHost, viewport, onAction, guardTyping = (field) => (value) => { field.value = value; } }) {
        const make = (tag, className, properties = {}) => Object.assign(document.createElement(tag), { className, ...properties });
        const hiddenFromReaders = (element) => {
            element.setAttribute('aria-hidden', 'true');

            return element;
        };
        const onlyTrusted = (handler) => (event) => {
            if (event.isTrusted) {
                handler(event);
            }
        };
        const sheet = new CSSStyleSheet();
        sheet.replaceSync(STYLES);
        shadow.adoptedStyleSheets = [...shadow.adoptedStyleSheets, sheet];

        const dock = make('div', 'dock dock--right');
        const bar = make('div', 'dock__bar');
        bar.setAttribute('role', 'toolbar');
        bar.setAttribute('aria-label', TEXTS.toolbar);
        const grip = make('button', 'dock__grip', { type: 'button', title: TEXTS.gripHint });
        grip.setAttribute('aria-label', TEXTS.grip);
        grip.append(hiddenFromReaders(make('span', 'dock__logo')), hiddenFromReaders(make('span', 'dock__dots')));

        const picker = make('select', 'dock__target');
        picker.setAttribute('aria-label', TEXTS.target);
        const separator = () => hiddenFromReaders(make('span', 'dock__sep'));

        const actionButton = (text, icon, key) => {
            const button = make('button', 'dock__action', { type: 'button' });
            // The name even on a narrow screen, where the label is not shown.
            button.setAttribute('aria-label', text);
            if (undefined !== icon) {
                button.append(hiddenFromReaders(make('span', `dock__icon dock__icon--${icon}`)));
            }
            button.append(make('span', 'dock__label', { textContent: text }));
            if (undefined !== key) {
                const shortcut = make('kbd', '', { textContent: key });
                shortcut.setAttribute('aria-hidden', 'true');
                button.append(shortcut);
                button.setAttribute('aria-keyshortcuts', key);
            }

            return button;
        };
        const pinButton = actionButton(TEXTS.pin, 'pin', 'P');
        pinButton.title = TEXTS.pinHint;
        pinButton.setAttribute('aria-pressed', 'false');
        const captureButton = actionButton(TEXTS.capture, 'capture', 'C');
        captureButton.title = TEXTS.captureHint;
        const clearPinsButton = make('button', 'dock__action dock__clear', { type: 'button', hidden: true, title: TEXTS.clearPinsHint });
        const playButton = actionButton(TEXTS.play, 'play');
        playButton.setAttribute('aria-pressed', 'false');
        const saveButton = actionButton(TEXTS.saveDraft);
        saveButton.classList.add('dock__send');
        saveButton.title = TEXTS.saveDraftHint;
        saveButton.setAttribute('aria-haspopup', 'dialog');
        saveButton.setAttribute('aria-expanded', 'false');
        const count = make('span', 'dock__count', { hidden: true });
        count.setAttribute('aria-hidden', 'true');
        saveButton.append(count);
        // Small and last but Shrink: it exists for WCAG 2.1.4, and most people leave it on.
        const letterKeysButton = make('button', 'dock__keys', { type: 'button', title: TEXTS.letterKeysHint });
        letterKeysButton.setAttribute('aria-label', TEXTS.letterKeys);
        // The state in words as well as in the fill (WCAG 1.4.1); aria-pressed already says it, so readers skip the word.
        const letterKeysState = hiddenFromReaders(make('span', 'dock__switch'));
        letterKeysButton.append(hiddenFromReaders(make('span', 'dock__label', { textContent: `${TEXTS.letterKeysShort} ` })), letterKeysState);
        const shrinkButton = make('button', 'dock__action dock__shrink', { type: 'button', title: TEXTS.shrinkHint });
        shrinkButton.setAttribute('aria-label', TEXTS.shrink);
        shrinkButton.setAttribute('aria-keyshortcuts', 'H');
        shrinkButton.append(hiddenFromReaders(make('span', 'dock__icon dock__icon--shrink')));
        bar.append(grip, picker, separator(), pinButton, captureButton, clearPinsButton, playButton, separator(), saveButton, letterKeysButton, shrinkButton);

        const savePanel = make('section', 'dock-pop dock-save', { hidden: true });
        savePanel.setAttribute('role', 'dialog');
        savePanel.setAttribute('aria-label', TEXTS.savePanel);
        const saveHeading = make('h3', 'dock-save__heading');
        const shotList = make('div', 'dock-save__shots');
        const titleField = make('input', 'dock-save__title', { type: 'text', maxLength: REPORT_TITLE_MAX_LENGTH, autocomplete: 'off' });
        const titleLabel = make('label', 'dock-save__field');
        titleLabel.append(make('span', '', { textContent: TEXTS.reportTitle }), titleField);
        const projectField = make('input', 'dock-save__project', { type: 'text', maxLength: PROJECT_KEY_MAX_LENGTH, autocomplete: 'off', placeholder: TEXTS.projectExample });
        const projectLabel = make('label', 'dock-save__field');
        projectLabel.append(make('span', '', { textContent: TEXTS.project }), projectField);
        const panelError = make('p', 'dock-save__error', { id: 'pinround-save-error' });
        panelError.setAttribute('role', 'alert');
        const goesTo = make('p', 'dock-save__goes', { textContent: TEXTS.goesTo });
        const panelSaveButton = make('button', 'dock-btn dock-btn--primary dock-save__send', { type: 'button', textContent: TEXTS.saveDraft });
        const keepTestingButton = make('button', 'dock-btn dock-save__keep', { type: 'button', textContent: TEXTS.keepTesting });
        const panelButtons = make('div', 'dock-save__buttons');
        panelButtons.append(panelSaveButton, keepTestingButton);
        savePanel.append(saveHeading, shotList, titleLabel, projectLabel, panelError, goesTo, panelButtons);
        const setTitleField = guardTyping(titleField);
        const setProjectField = guardTyping(projectField);

        const status = make('p', 'dock__status');
        status.setAttribute('role', 'status');
        const stepSlot = make('div', 'dock__step-slot');
        const pops = make('div', 'dock__pops');
        pops.append(savePanel, stepSlot, status);
        dock.append(bar, pops);

        const handle = make('button', 'dock-handle dock--right', { type: 'button', hidden: true });
        handle.setAttribute('aria-label', TEXTS.open);
        handle.setAttribute('aria-keyshortcuts', 'H');
        const handleCount = make('span', 'dock__count', { hidden: true });
        handleCount.setAttribute('aria-hidden', 'true');
        handle.append(hiddenFromReaders(make('span', 'dock__logo')), handleCount);
        shadow.append(dock, handle);

        let place = { ...DEFAULT_PLACE };
        let isBusy = false;
        let canSave = false;
        /** The new report as the extension last answered it, for the Save draft panel; null for any other target. */
        let waitingReport = null;
        /** The picker's value as the extension last answered it, put back when the picker is changed while busy. */
        let chosenTarget = '';
        // Off until the stored switch is read, so a person who turned the keys off never has a key act meanwhile.
        let areLetterKeysOn = false;
        /** @type {null|{element: HTMLElement, dx: number, dy: number, width: number, height: number, startX: number, startY: number, hasMoved: boolean}} */
        let drag = null;
        /** Set when a drag of the puck ends, so the click that follows it does not open the dock. */
        let isPuckDropped = false;

        function applyPlace(newPlace) {
            place = checkedPlace(newPlace);
            for (const element of [dock, handle]) {
                element.classList.toggle('dock--left', 'left' === place.side);
                element.classList.toggle('dock--right', 'right' === place.side);
                element.style.setProperty('--top-pct', String(place.topPct));
            }
            // Panels open towards the middle of the viewport, so they stay on screen.
            dock.classList.toggle('dock--low', place.topPct > 50);
        }

        function keepPlace(newPlace) {
            applyPlace(newPlace);
            // A place that cannot be kept (the extension was updated under the page) still holds until the page goes.
            savePlace(storage, siteHost, place).catch(() => undefined);
        }

        // aria-disabled, not disabled: a disabled control loses focus, so the keyboard user would be thrown out of the
        // dock each time a request starts. A press on one is ignored by whenEnabled instead.
        function enableControls() {
            for (const control of [picker, captureButton, panelSaveButton]) {
                control.setAttribute('aria-disabled', String(isBusy));
            }
            saveButton.setAttribute('aria-disabled', String(isBusy || !canSave));
        }

        function say(message, isError = false) {
            status.textContent = message;
            status.classList.toggle('dock__status--error', isError);
        }

        /** The handler for a person's press on the control, which does nothing while it is aria-disabled. */
        const whenEnabled = (control, handler) => onlyTrusted((event) => {
            if ('true' !== control.getAttribute('aria-disabled')) {
                handler(event);
            } else if (isBusy) {
                say(TEXTS.busy);
            }
        });

        function showLetterKeys(areOn) {
            areLetterKeysOn = areOn;
            letterKeysButton.setAttribute('aria-pressed', String(areOn));
            letterKeysState.textContent = areOn ? TEXTS.switchedOn : TEXTS.switchedOff;
        }

        function toggleLetterKeys() {
            showLetterKeys(!areLetterKeysOn);
            // A switch that cannot be kept (the extension was updated under the page) still holds until the page goes.
            storage.set({ [LETTER_KEYS_KEY]: areLetterKeysOn }).catch(() => undefined);
        }

        const isPanelOpen = () => !savePanel.hidden;
        const hasFocusIn = (element) => null !== shadow.activeElement && element.contains(shadow.activeElement);

        function showShots() {
            const shots = waitingReport?.shots ?? [];
            saveHeading.textContent = TEXTS.saveCount(shots.length);
            shotList.replaceChildren(...shots.map((shot, index) => {
                const thumb = hiddenFromReaders(make('div', 'dock-save__thumb'));
                const { thumbnail } = shot;
                if (null !== thumbnail) {
                    const picture = make('canvas', 'dock-save__picture', { width: THUMBNAIL_BOX_PX.width, height: THUMBNAIL_BOX_PX.height });
                    thumb.append(picture);
                    // A thumbnail that does not decode leaves the plain box, the pins still on it.
                    drawCover(picture, thumbnail).catch(() => undefined);
                }
                for (const spot of shot.pinSpots) {
                    const shown = null === thumbnail ? spot : coverSpotOf(spot, thumbnail.width, thumbnail.height, THUMBNAIL_BOX_PX.width, THUMBNAIL_BOX_PX.height);
                    const dot = make('span', 'dock-save__dot');
                    dot.style.left = `${shown.x_pct}%`;
                    dot.style.top = `${shown.y_pct}%`;
                    thumb.append(dot);
                }
                const figure = make('figure', 'dock-save__shot');
                figure.append(thumb, make('figcaption', '', { textContent: TEXTS.shotCaption(index + 1, shot.pinSpots.length) }));

                return figure;
            }));
            titleLabel.hidden = !(waitingReport?.needsTitle ?? false);
            projectLabel.hidden = !(waitingReport?.needsProject ?? false);
        }

        function showPanelError(message, field = null) {
            panelError.textContent = message;
            for (const input of [titleField, projectField]) {
                if (input === field) {
                    input.setAttribute('aria-invalid', 'true');
                    input.setAttribute('aria-describedby', panelError.id);
                } else {
                    input.removeAttribute('aria-invalid');
                    input.removeAttribute('aria-describedby');
                }
            }
        }

        function openSavePanel() {
            showShots();
            showPanelError('');
            savePanel.hidden = false;
            saveButton.setAttribute('aria-expanded', 'true');
            [titleField, projectField, panelSaveButton].find((control) => !control.parentNode.hidden)?.focus();
        }

        /** Closes the Save draft panel; focus inside it goes back to the bar's Save draft. @returns {boolean} whether it was open */
        function closeSavePanel() {
            if (!isPanelOpen()) {
                return false;
            }
            const hadFocus = hasFocusIn(savePanel);
            savePanel.hidden = true;
            saveButton.setAttribute('aria-expanded', 'false');
            if (hadFocus) {
                saveButton.focus();
            }

            return true;
        }

        function sendTyped() {
            const typed = { reportTitle: titleField.value, project: projectField.value };
            const asked = { needsTitle: waitingReport?.needsTitle ?? false, needsProject: waitingReport?.needsProject ?? false };
            const refusal = refusalOfTyped(typed, asked);
            if (null !== refusal) {
                const field = TEXTS.titleRefused === refusal ? titleField : projectField;
                showPanelError(refusal, field);
                field.focus();

                return;
            }
            showPanelError('');
            onAction('save-draft', { reportTitle: asked.needsTitle ? typed.reportTitle : '', project: asked.needsProject ? typed.project : '' });
        }

        function showCount(shownCount) {
            for (const badge of [count, handleCount]) {
                badge.hidden = null === shownCount || (badge === handleCount && 0 === shownCount);
                badge.textContent = null === shownCount ? '' : String(shownCount);
            }
            saveButton.setAttribute('aria-label', null === shownCount ? TEXTS.saveDraft : TEXTS.saveDraftCount(shownCount));
            handle.setAttribute('aria-label', null === shownCount || 0 === shownCount ? TEXTS.open : TEXTS.openWithCount(shownCount));
        }

        function showState(state) {
            const options = [make('option', '', { value: '', textContent: TEXTS.noTarget })];
            for (const choice of state.choices) {
                options.push(make('option', '', { value: choice.value, textContent: choice.label }));
            }
            picker.replaceChildren(...options);
            const chosen = options.find((option) => option.value === state.chosen) ?? options[0];
            chosen.selected = true;
            chosenTarget = chosen.value;
            showCount(state.count);
            canSave = state.canSave;
            waitingReport = state.newReport;
            saveButton.title = null === state.newReport && null !== state.count ? TEXTS.saveDraftCannot : TEXTS.saveDraftHint;
            if (!canSave) {
                closeSavePanel();
            } else if (isPanelOpen()) {
                showShots();
            }
            enableControls();
        }

        function toggleShrunk() {
            const isShrinking = !dock.hidden;
            const hadFocus = null !== shadow.activeElement && (dock.contains(shadow.activeElement) || handle === shadow.activeElement);
            dock.hidden = isShrinking;
            handle.hidden = !isShrinking;
            if (hadFocus) {
                (isShrinking ? handle : shrinkButton).focus();
            }
        }

        picker.addEventListener('change', onlyTrusted(() => {
            if ('true' === picker.getAttribute('aria-disabled')) {
                picker.value = chosenTarget;
                say(TEXTS.busy);
            } else {
                onAction('choose', picker.value);
            }
        }));
        pinButton.addEventListener('click', onlyTrusted(() => onAction('pin')));
        clearPinsButton.addEventListener('click', onlyTrusted(() => onAction('clear-pins')));
        captureButton.addEventListener('click', whenEnabled(captureButton, () => onAction('capture')));
        playButton.addEventListener('click', onlyTrusted(() => onAction('play')));
        saveButton.addEventListener('click', whenEnabled(saveButton, () => {
            if (!closeSavePanel()) {
                openSavePanel();
            }
        }));
        panelSaveButton.addEventListener('click', whenEnabled(panelSaveButton, sendTyped));
        keepTestingButton.addEventListener('click', onlyTrusted(closeSavePanel));
        for (const field of [titleField, projectField]) {
            field.addEventListener('keydown', onlyTrusted((event) => {
                if ('Enter' === event.key && !event.isComposing) {
                    event.preventDefault();
                    if ('true' === panelSaveButton.getAttribute('aria-disabled')) {
                        say(TEXTS.busy);
                    } else {
                        sendTyped();
                    }
                }
            }));
        }
        shrinkButton.addEventListener('click', onlyTrusted(toggleShrunk));
        letterKeysButton.addEventListener('click', onlyTrusted(toggleLetterKeys));
        handle.addEventListener('click', onlyTrusted(() => {
            if (isPuckDropped) {
                isPuckDropped = false;

                return;
            }
            toggleShrunk();
        }));

        // The grip moves the bar; the puck moves itself, and only once it has moved past a click's slop.
        const startDrag = (source, moved) => onlyTrusted((event) => {
            if (0 !== event.button) {
                return;
            }
            event.preventDefault();
            source.setPointerCapture(event.pointerId);
            const box = moved.getBoundingClientRect();
            drag = { element: moved, dx: event.clientX - box.left, dy: event.clientY - box.top, width: box.width, height: box.height, startX: event.clientX, startY: event.clientY, hasMoved: moved === dock };
            if (drag.hasMoved) {
                moved.style.left = `${box.left}px`;
                moved.style.top = `${box.top}px`;
                moved.classList.add('dock--dragging');
            }
        });
        const moveDrag = onlyTrusted((event) => {
            if (null === drag) {
                return;
            }
            if (!drag.hasMoved && Math.hypot(event.clientX - drag.startX, event.clientY - drag.startY) <= PUCK_CLICK_SLOP_PX) {
                return;
            }
            drag.hasMoved = true;
            drag.element.classList.add('dock--dragging');
            drag.element.style.left = `${event.clientX - drag.dx}px`;
            drag.element.style.top = `${event.clientY - drag.dy}px`;
        });
        const endDrag = (event, isDropped) => {
            if (null === drag) {
                return;
            }
            const dropped = drag;
            drag = null;
            dropped.element.classList.remove('dock--dragging');
            dropped.element.style.left = '';
            dropped.element.style.top = '';
            if (!dropped.hasMoved) {
                return;
            }
            if (dropped.element === handle) {
                isPuckDropped = isDropped;
            }
            if (!isDropped) {
                applyPlace(place);

                return;
            }
            const left = event.clientX - dropped.dx;
            const top = event.clientY - dropped.dy;
            keepPlace({ side: nearerSide(left + (dropped.width / 2), viewport.width()), topPct: topPercentOf(top, viewport.height(), dropped.height) });
        };
        for (const [source, moved] of [[grip, dock], [handle, handle]]) {
            source.addEventListener('pointerdown', startDrag(source, moved));
            source.addEventListener('pointermove', moveDrag);
            source.addEventListener('pointerup', onlyTrusted((event) => endDrag(event, true)));
            source.addEventListener('pointercancel', onlyTrusted((event) => endDrag(event, false)));
        }
        grip.addEventListener('keydown', onlyTrusted((event) => {
            const moves = {
                ArrowLeft: { side: 'left' },
                ArrowRight: { side: 'right' },
                ArrowUp: { topPct: place.topPct - GRIP_STEP_PCT },
                ArrowDown: { topPct: place.topPct + GRIP_STEP_PCT },
            };
            if (Object.hasOwn(moves, event.key)) {
                event.preventDefault();
                keepPlace({ ...place, ...moves[event.key] });
            }
        }));

        applyPlace(DEFAULT_PLACE);
        enableControls();
        showLetterKeys(false);
        // Another tab's dock turning the keys on or off turns them here too.
        storage.onChanged.addListener((changes) => {
            if (Object.hasOwn(changes, LETTER_KEYS_KEY)) {
                showLetterKeys(false !== changes[LETTER_KEYS_KEY].newValue);
            }
        });
        const ready = Promise.all([
            loadPlace(storage, siteHost).then(applyPlace, () => undefined),
            // A setting that cannot be read may be the person's "off", so the keys stay off for this page.
            loadLetterKeys(storage).then(showLetterKeys, () => showLetterKeys(false)),
        ]).then(() => undefined);

        return {
            /** Resolves once the site's stored place and the Letter keys switch are applied; when storage refused, the default place and the keys off. */
            ready,
            /** Where the scenario's step card goes (dock-run.js): under the bar, beside the Save draft panel. */
            stepSlot,
            /** The dock's focusable controls in Tab order, the open Save draft panel's among them, for the overlay's focus trap while pinning. */
            focusables: () => (dock.hidden ? [handle] : [
                ...[grip, picker, pinButton, captureButton, clearPinsButton, playButton, saveButton, letterKeysButton, shrinkButton].filter((control) => !control.hidden),
                ...(isPanelOpen() ? [titleField, projectField, panelSaveButton, keepTestingButton].filter((control) => !control.parentNode.hidden) : []),
            ]),
            /** Whether P, C and H typed anywhere on the page are the dock's; inside the dock they always are. */
            areLetterKeysOn: () => areLetterKeysOn,
            setPinning(isPinning) {
                pinButton.setAttribute('aria-pressed', String(isPinning));
            },
            /** Marks Play pressed while a scenario's step card shows. */
            setPlaying(isPlaying) {
                playButton.setAttribute('aria-pressed', String(isPlaying));
            },
            /** Shows "Clear N pins" for the pins on the page, and hides it while there are none. */
            setPinCount(pinCount) {
                clearPinsButton.hidden = 0 === pinCount;
                clearPinsButton.textContent = TEXTS.clearPins(pinCount);
            },
            /** Marks the request controls busy; once not busy, a wait message still showing goes. */
            setBusy(busy) {
                isBusy = busy;
                enableControls();
                if (!busy && TEXTS.busy === status.textContent) {
                    say('');
                }
            },
            /**
             * Shows the extension's answer to `action`: its state, its message; anything unreadable is said as no
             * answer. A refused save-draft is said in the open panel, by its fields; a saved one closes the panel.
             */
            showAnswer(answer, action) {
                if (null === answer || 'object' !== typeof answer) {
                    say(TEXTS.notAnswered, true);

                    return;
                }
                const state = undefined === answer.state ? undefined : checkedState(answer.state);
                if (null === state || ('message' in answer && 'string' !== typeof answer.message)) {
                    say(TEXTS.notAnswered, true);

                    return;
                }
                const isPanelAnswer = 'save-draft' === action && isPanelOpen();
                if (undefined !== state) {
                    showState(state);
                }
                if ('string' !== typeof answer.message) {
                    return;
                }
                if (isPanelAnswer && true === answer.isError && isPanelOpen()) {
                    showPanelError(answer.message);

                    return;
                }
                if (isPanelAnswer && true !== answer.isError) {
                    setTitleField('');
                    setProjectField('');
                    closeSavePanel();
                }
                say(answer.message, true === answer.isError);
            },
            say,
            /** Closes the Save draft panel, as Escape does. @returns {boolean} whether one was open */
            closePanels: closeSavePanel,
            toggleShrunk,
            isShrunk: () => dock.hidden,
            focusPinButton: () => (dock.hidden ? handle : pinButton).focus(),
        };
    }

    return Object.freeze({
        PLACE_KEY_PREFIX, LETTER_KEYS_KEY, DEFAULT_PLACE, EDGE_GAP_PX, GRIP_STEP_PCT, MAX_SHOTS, REPORT_TITLE_MAX_LENGTH, PROJECT_KEY_MAX_LENGTH, TEXTS,
        THUMBNAIL_DATA_URL_PREFIX, MAX_THUMBNAIL_DATA_URL_LENGTH, MAX_THUMBNAIL_SIDE_PX, THUMBNAIL_BOX_PX,
        nearerSide, topPercentOf, checkedPlace, placeKeyOf, loadPlace, savePlace, loadLetterKeys, isTypingTarget, actionOfKey, checkedState, refusalOfTyped,
        coverCropOf, coverSpotOf, mountDock,
    });
})();
