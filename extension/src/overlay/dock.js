// The dock: pinround's toolbar on the tested page, built by overlay.js inside the overlay's own closed shadow root, so
// the page can neither find nor read it. Injected as a classic script before overlay.js, like pin-rules.js; node --test
// imports it for its rules. The whole file is one expression, so a second injection keeps the first.
// Every text from pinround or the page goes in through textContent or an option's text, never as markup.
globalThis.pinroundDock ??= (() => {
    /** The local storage key's prefix; the site's host follows, so each site keeps its own side and height. */
    const PLACE_KEY_PREFIX = 'pinroundDockPlace:';
    const SIDES = ['left', 'right'];
    const DEFAULT_PLACE = Object.freeze({ side: 'right', topPct: 50 });
    /** The gap between the dock and the viewport's edges, in CSS pixels. */
    const EDGE_GAP_PX = 8;
    /** How far one arrow key on the grip moves the dock up or down, in percent of the room it has. */
    const GRIP_STEP_PCT = 5;
    const KEY_ACTIONS = Object.freeze({ p: 'pin', c: 'capture', h: 'shrink' });
    const TYPING_TAGS = new Set(['INPUT', 'TEXTAREA', 'SELECT']);
    /** A cap on what the extension's answer may put in the picker, so a broken answer cannot flood the page. */
    const MAX_CHOICES = 200;

    const TEXTS = Object.freeze({
        toolbar: 'pinround dock',
        grip: 'Move the dock',
        gripHint: 'Drag it, or use the arrow keys.',
        target: 'Put it in',
        noTarget: 'Choose where to put it',
        pin: 'Pin',
        capture: 'Capture',
        play: 'Play',
        saveDraft: 'Save draft',
        shrink: 'Shrink',
        open: 'Open the dock',
        notAnswered: 'The extension did not answer. Open its popup and try again.',
    });

    const STYLES = `
        .dock, .dock-handle {
            --top-pct: 50;
            position: absolute;
            top: calc(${EDGE_GAP_PX}px + (100% - ${2 * EDGE_GAP_PX}px) * var(--top-pct) / 100);
            transform: translateY(calc(var(--top-pct) * -1%));
            pointer-events: auto;
            color: #161d1b;
            background: #ffffff;
            box-shadow: 0 0 0 1px #161d1b, 0 4px 12px rgb(0 0 0 / 30%);
        }
        .dock--left { left: ${EDGE_GAP_PX}px; }
        .dock--right { right: ${EDGE_GAP_PX}px; }
        .dock--dragging { transform: none; right: auto; }
        .dock { display: grid; gap: 6px; width: 172px; padding: 8px; border-radius: 10px; }
        .dock[hidden], .dock-handle[hidden] { display: none; }
        .dock__head { display: flex; align-items: center; gap: 6px; }
        .dock__logo { display: flex; align-items: center; gap: 6px; flex: 1; font-weight: 700; }
        .dock__logo::before { content: ''; width: 14px; height: 14px; border: 2px solid #ffffff; border-radius: 50%; box-shadow: 0 0 0 2px #161d1b, inset 0 0 0 2px #161d1b; }
        .dock__grip { padding: 2px 6px; border: none; border-radius: 6px; background: none; color: #4a5250; cursor: grab; letter-spacing: -2px; }
        .dock--dragging .dock__grip { cursor: grabbing; }
        .dock label { display: grid; gap: 2px; font-size: 12px; }
        .dock select { width: 100%; padding: 4px; border: 1px solid #8a8f98; border-radius: 6px; color: inherit; background: #ffffff; text-overflow: ellipsis; }
        .dock__action { display: flex; justify-content: space-between; align-items: center; gap: 6px; padding: 6px 10px; border: 1px solid #2f6fd6; border-radius: 6px; background: #ffffff; color: #1f4fa0; cursor: pointer; }
        .dock__action[aria-pressed="true"] { background: #2f6fd6; color: #ffffff; }
        .dock__action:disabled { border-color: #c3c7cc; color: #6b7280; cursor: default; }
        .dock kbd { font: 12px/1 system-ui, sans-serif; padding: 2px 4px; border: 1px solid currentColor; border-radius: 4px; opacity: 0.8; }
        .dock__count { min-width: 20px; padding: 0 6px; border-radius: 10px; background: #161d1b; color: #ffffff; font-size: 12px; line-height: 20px; text-align: center; }
        .dock__count[hidden] { display: none; }
        .dock__status { margin: 0; font-size: 12px; overflow-wrap: anywhere; max-height: 120px; overflow: auto; }
        .dock__status:empty { display: none; }
        .dock__status--error { color: #a3221a; }
        .dock-handle { width: 32px; height: 32px; padding: 0; border: none; border-radius: 50%; cursor: pointer; }
        .dock-handle::before { content: ''; display: block; width: 14px; height: 14px; margin: auto; border: 2px solid #ffffff; border-radius: 50%; box-shadow: 0 0 0 2px #161d1b, inset 0 0 0 2px #161d1b; }
        .dock :focus-visible, .dock-handle:focus-visible { outline: 2px solid #2f6fd6; outline-offset: 2px; }
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

    /**
     * @typedef {{choices: Array<{value: string, label: string}>, chosen: string, count: number|null, canSave: boolean}} DockState
     * @returns {DockState|null} the state as the extension answered it, or null when it is not one
     */
    function checkedState(state) {
        if (null === state || 'object' !== typeof state || !Array.isArray(state.choices) || 'string' !== typeof state.chosen
            || 'boolean' !== typeof state.canSave || !(null === state.count || (Number.isInteger(state.count) && state.count >= 0))) {
            return null;
        }
        const choices = state.choices.slice(0, MAX_CHOICES).filter((choice) => 'string' === typeof choice?.value && 'string' === typeof choice.label);

        return { choices: choices.map(({ value, label }) => ({ value, label })), chosen: state.chosen, count: state.count, canSave: state.canSave };
    }

    /**
     * Builds the dock into `shadow` and places it where this site last had it.
     *
     * @param {ShadowRoot} shadow the overlay's closed root
     * @param {{storage: {get: Function, set: Function}, siteHost: string, viewport: {width: () => number, height: () => number},
     *   onAction: (action: string, value?: string) => void}} options storage is the extension's local storage; onAction hears
     *   pin, capture, play, save-draft and choose (with the picked value); shrink stays in here. Play's step card goes in
     *   the answer's stepSlot.
     */
    function mountDock(shadow, { storage, siteHost, viewport, onAction }) {
        const make = (tag, className, properties = {}) => Object.assign(document.createElement(tag), { className, ...properties });
        const onlyTrusted = (handler) => (event) => {
            if (event.isTrusted) {
                handler(event);
            }
        };
        const sheet = new CSSStyleSheet();
        sheet.replaceSync(STYLES);
        shadow.adoptedStyleSheets = [...shadow.adoptedStyleSheets, sheet];

        const dock = make('div', 'dock dock--right');
        dock.setAttribute('role', 'toolbar');
        dock.setAttribute('aria-label', TEXTS.toolbar);
        dock.setAttribute('aria-orientation', 'vertical');
        const logo = make('span', 'dock__logo', { textContent: 'pinround' });
        logo.setAttribute('aria-hidden', 'true');
        const grip = make('button', 'dock__grip', { type: 'button', textContent: '⋮⋮', title: TEXTS.gripHint });
        grip.setAttribute('aria-label', TEXTS.grip);
        const head = make('div', 'dock__head');
        head.append(logo, grip);

        const picker = make('select', 'dock__target');
        const pickerLabel = make('label', '');
        pickerLabel.append(make('span', '', { textContent: TEXTS.target }), picker);

        const actionButton = (text, key) => {
            const button = make('button', 'dock__action', { type: 'button' });
            button.append(make('span', '', { textContent: text }));
            if (undefined !== key) {
                const shortcut = make('kbd', '', { textContent: key });
                shortcut.setAttribute('aria-hidden', 'true');
                button.append(shortcut);
                button.setAttribute('aria-keyshortcuts', key);
            }

            return button;
        };
        const pinButton = actionButton(TEXTS.pin, 'P');
        pinButton.setAttribute('aria-pressed', 'false');
        const captureButton = actionButton(TEXTS.capture, 'C');
        const playButton = actionButton(TEXTS.play);
        const saveButton = actionButton(TEXTS.saveDraft);
        const count = make('span', 'dock__count', { hidden: true });
        saveButton.append(count);
        const shrinkButton = actionButton(TEXTS.shrink, 'H');
        const status = make('p', 'dock__status');
        status.setAttribute('role', 'status');
        const stepSlot = make('div', 'dock__step-slot');
        dock.append(head, pickerLabel, pinButton, captureButton, playButton, saveButton, shrinkButton, stepSlot, status);

        const handle = make('button', 'dock-handle dock--right', { type: 'button', hidden: true });
        handle.setAttribute('aria-label', TEXTS.open);
        handle.setAttribute('aria-keyshortcuts', 'H');
        shadow.append(dock, handle);

        let place = { ...DEFAULT_PLACE };
        let isBusy = false;
        let canSave = false;
        /** @type {null|{dx: number, dy: number, width: number, height: number}} */
        let drag = null;

        function applyPlace(newPlace) {
            place = checkedPlace(newPlace);
            for (const element of [dock, handle]) {
                element.classList.toggle('dock--left', 'left' === place.side);
                element.classList.toggle('dock--right', 'right' === place.side);
                element.style.setProperty('--top-pct', String(place.topPct));
            }
        }

        function keepPlace(newPlace) {
            applyPlace(newPlace);
            // A place that cannot be kept (the extension was updated under the page) still holds until the page goes.
            savePlace(storage, siteHost, place).catch(() => undefined);
        }

        function enableControls() {
            for (const control of [picker, captureButton]) {
                control.disabled = isBusy;
            }
            saveButton.disabled = isBusy || !canSave;
        }

        function say(message, isError = false) {
            status.textContent = message;
            status.classList.toggle('dock__status--error', isError);
        }

        function showState(state) {
            const options = [make('option', '', { value: '', textContent: TEXTS.noTarget })];
            for (const choice of state.choices) {
                options.push(make('option', '', { value: choice.value, textContent: choice.label }));
            }
            picker.replaceChildren(...options);
            const chosen = options.find((option) => option.value === state.chosen) ?? options[0];
            chosen.selected = true;
            count.hidden = null === state.count;
            count.textContent = null === state.count ? '' : String(state.count);
            saveButton.setAttribute('aria-label', null === state.count ? TEXTS.saveDraft : `${TEXTS.saveDraft}, ${state.count} in it`);
            canSave = state.canSave;
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

        picker.addEventListener('change', onlyTrusted(() => onAction('choose', picker.value)));
        pinButton.addEventListener('click', onlyTrusted(() => onAction('pin')));
        captureButton.addEventListener('click', onlyTrusted(() => onAction('capture')));
        playButton.addEventListener('click', onlyTrusted(() => onAction('play')));
        saveButton.addEventListener('click', onlyTrusted(() => onAction('save-draft')));
        shrinkButton.addEventListener('click', onlyTrusted(toggleShrunk));
        handle.addEventListener('click', onlyTrusted(toggleShrunk));

        grip.addEventListener('pointerdown', onlyTrusted((event) => {
            if (0 !== event.button) {
                return;
            }
            event.preventDefault();
            grip.setPointerCapture(event.pointerId);
            const box = dock.getBoundingClientRect();
            drag = { dx: event.clientX - box.left, dy: event.clientY - box.top, width: box.width, height: box.height };
            dock.style.left = `${box.left}px`;
            dock.style.top = `${box.top}px`;
            dock.classList.add('dock--dragging');
        }));
        grip.addEventListener('pointermove', onlyTrusted((event) => {
            if (null !== drag) {
                dock.style.left = `${event.clientX - drag.dx}px`;
                dock.style.top = `${event.clientY - drag.dy}px`;
            }
        }));
        const endDrag = (event, isDropped) => {
            if (null === drag) {
                return;
            }
            const dropped = drag;
            drag = null;
            dock.classList.remove('dock--dragging');
            dock.style.left = '';
            dock.style.top = '';
            if (!isDropped) {
                applyPlace(place);

                return;
            }
            const left = event.clientX - dropped.dx;
            const top = event.clientY - dropped.dy;
            keepPlace({ side: nearerSide(left + (dropped.width / 2), viewport.width()), topPct: topPercentOf(top, viewport.height(), dropped.height) });
        };
        grip.addEventListener('pointerup', onlyTrusted((event) => endDrag(event, true)));
        grip.addEventListener('pointercancel', onlyTrusted((event) => endDrag(event, false)));
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
        const ready = loadPlace(storage, siteHost).then(applyPlace, () => undefined);

        return {
            /** Resolves once the site's stored place is applied, or the default kept when storage refused. */
            ready,
            /** Where the scenario's step card goes (dock-run.js): after Shrink, before the status line. */
            stepSlot,
            /** The dock's focusable controls in Tab order, for the overlay's focus trap while pinning. */
            focusables: () => (dock.hidden ? [handle] : [grip, picker, pinButton, captureButton, playButton, saveButton, shrinkButton].filter((control) => !control.disabled)),
            setPinning(isPinning) {
                pinButton.setAttribute('aria-pressed', String(isPinning));
            },
            setBusy(busy) {
                isBusy = busy;
                enableControls();
            },
            /** Shows the extension's answer: its state, its message; anything unreadable is said as no answer. */
            showAnswer(answer) {
                if (null === answer || 'object' !== typeof answer) {
                    say(TEXTS.notAnswered, true);

                    return;
                }
                const state = undefined === answer.state ? undefined : checkedState(answer.state);
                if (null === state || ('message' in answer && 'string' !== typeof answer.message)) {
                    say(TEXTS.notAnswered, true);

                    return;
                }
                if (undefined !== state) {
                    showState(state);
                }
                if ('string' === typeof answer.message) {
                    say(answer.message, true === answer.isError);
                }
            },
            say,
            toggleShrunk,
            isShrunk: () => dock.hidden,
            focusPinButton: () => (dock.hidden ? handle : pinButton).focus(),
        };
    }

    return Object.freeze({ PLACE_KEY_PREFIX, DEFAULT_PLACE, EDGE_GAP_PX, GRIP_STEP_PCT, TEXTS, nearerSide, topPercentOf, checkedPlace, placeKeyOf, loadPlace, savePlace, isTypingTarget, actionOfKey, checkedState, mountDock });
})();
