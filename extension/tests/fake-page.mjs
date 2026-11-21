// A tested page for the overlay and dock scripts in node --test: the scripts run as Chrome injects them, as classic
// scripts sharing one global (the isolated world), over a small DOM. Modelled as Chrome does it: a closed shadow root
// is not reachable from its host (shadowRoot is null), and document and element queries never look into a shadow
// root; an event's path crosses a shadow root only when it is composed, and listeners outside a root see its host as
// the target and as composedPath()[0]; capture-phase listeners run first, from the window down; stopPropagation ends
// the path after the current node; activeElement is the focused element seen from that document or root. Not
// modelled: layout (getBoundingClientRect answers what a test sets as `box`), CSS, default actions of events, and the
// page's own open shadow roots. The test reaches into a closed root only through closedRootOf, as the e2e inspector
// does through the DevTools protocol.
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const EXTENSION_ROOT = new URL('../', import.meta.url);

class FakeNode {
    #listeners = [];
    parentNode = null;
    children = [];

    constructor(page) {
        this.page = page;
    }

    addEventListener(type, listener, options = {}) {
        const capture = true === options || true === options?.capture;
        this.#listeners.push({ type, listener, capture });
    }

    removeEventListener(type, listener, options = {}) {
        const capture = true === options || true === options?.capture;
        this.#listeners = this.#listeners.filter((entry) => !(entry.type === type && entry.listener === listener && entry.capture === capture));
    }

    listenersFor(type, capture) {
        return this.#listeners.filter((entry) => entry.type === type && entry.capture === capture).map((entry) => entry.listener);
    }

    append(...nodes) {
        for (const node of nodes) {
            node.remove();
            node.parentNode = this;
            this.children.push(node);
        }
    }

    replaceChildren(...nodes) {
        for (const child of [...this.children]) {
            child.remove();
        }
        this.append(...nodes);
    }

    remove() {
        if (null !== this.parentNode) {
            this.parentNode.children.splice(this.parentNode.children.indexOf(this), 1);
            this.parentNode = null;
        }
    }

    contains(node) {
        for (let at = node; null !== at && undefined !== at; at = at.parentNode) {
            if (at === this) {
                return true;
            }
        }

        return false;
    }

    /** Every element under this node in tree order, never inside a shadow root. */
    *descendants() {
        for (const child of this.children) {
            yield child;
            yield* child.descendants();
        }
    }

    querySelectorAll(selector) {
        return [...this.descendants()].filter((element) => element.matches(selector));
    }

    querySelector(selector) {
        return this.querySelectorAll(selector)[0] ?? null;
    }

    get isConnected() {
        let at = this;
        while (null !== at.parentNode) {
            at = at.parentNode;
        }
        if (at instanceof FakeShadowRoot) {
            return at.host.isConnected;
        }

        return at === this.page.document;
    }

    get textContent() {
        return (this.text ?? '') + this.children.map((child) => child.textContent).join('');
    }

    set textContent(text) {
        this.replaceChildren();
        this.text = String(text);
    }
}

class FakeStyle {
    #custom = new Map();

    setProperty(name, value) {
        this.#custom.set(name, value);
    }

    getPropertyValue(name) {
        return this.#custom.get(name) ?? '';
    }
}

class FakeElement extends FakeNode {
    #classes = new Set();
    #attributes = new Map();
    hidden = false;
    disabled = false;
    isContentEditable = false;
    /** @type {{left: number, top: number, width: number, height: number}} where layout would put it; set by a test */
    box = { left: 0, top: 0, width: 0, height: 0 };

    constructor(page, tagName) {
        super(page);
        this.tagName = tagName.toUpperCase();
        this.style = new FakeStyle();
        const classes = this.#classes;
        this.classList = {
            add: (...names) => names.forEach((name) => classes.add(name)),
            remove: (...names) => names.forEach((name) => classes.delete(name)),
            contains: (name) => classes.has(name),
            toggle: (name, force = !classes.has(name)) => {
                if (force) {
                    classes.add(name);
                } else {
                    classes.delete(name);
                }

                return force;
            },
        };
    }

    get className() {
        return [...this.#classes].join(' ');
    }

    set className(value) {
        this.#classes.clear();
        String(value).split(/\s+/).filter((name) => '' !== name).forEach((name) => this.#classes.add(name));
    }

    setAttribute(name, value) {
        this.#attributes.set(name, String(value));
    }

    getAttribute(name) {
        return this.#attributes.get(name) ?? null;
    }

    hasAttribute(name) {
        return this.#attributes.has(name);
    }

    /** Tag names, `.class`, `[attribute]` and `*` only. */
    matches(selector) {
        if ('*' === selector) {
            return true;
        }
        if (selector.startsWith('.')) {
            return this.#classes.has(selector.slice(1));
        }
        if (selector.startsWith('[') && selector.endsWith(']')) {
            return this.#attributes.has(selector.slice(1, -1));
        }

        return !selector.startsWith(':') && this.tagName === selector.toUpperCase();
    }

    attachShadow({ mode }) {
        if (undefined !== this.page.shadowRoots.get(this)) {
            throw new Error('Failed to execute \'attachShadow\' on \'Element\': Shadow root cannot be created on a host which already hosts a shadow tree.');
        }
        const root = new FakeShadowRoot(this.page, this, mode);
        this.page.shadowRoots.set(this, root);

        return root;
    }

    get shadowRoot() {
        const root = this.page.shadowRoots.get(this);

        return 'open' === root?.mode ? root : null;
    }

    focus() {
        if (this.isConnected) {
            this.page.focused = this;
        }
    }

    getBoundingClientRect() {
        return { ...this.box, right: this.box.left + this.box.width, bottom: this.box.top + this.box.height };
    }

    setPointerCapture() {}

    get value() {
        if ('SELECT' !== this.tagName) {
            return this.ownValue ?? '';
        }
        const options = this.children.filter((child) => 'OPTION' === child.tagName);

        return (options.findLast((option) => true === option.selected) ?? options[0])?.value ?? '';
    }

    /** On a select, picks the option with that value and no other, as a person's choice does. */
    set value(value) {
        if ('SELECT' !== this.tagName) {
            this.ownValue = value;

            return;
        }
        for (const option of this.children) {
            option.selected = option.value === value;
        }
    }
}

class FakeShadowRoot extends FakeNode {
    adoptedStyleSheets = [];

    constructor(page, host, mode) {
        super(page);
        this.host = host;
        this.mode = mode;
    }

    get activeElement() {
        return this.contains(this.page.focused) ? this.page.focused : null;
    }
}

class FakeDocument extends FakeNode {
    constructor(page) {
        super(page);
        this.documentElement = new FakeElement(page, 'html');
        this.body = new FakeElement(page, 'body');
        this.append(this.documentElement);
        this.documentElement.append(this.body);
    }

    createElement(tagName) {
        return new FakeElement(this.page, tagName);
    }

    /** The focused element, or the host of the closed root it is in, as the page sees it. */
    get activeElement() {
        let seen = this.page.focused;
        while (null !== seen && !this.contains(seen)) {
            let at = seen;
            while (null !== at.parentNode) {
                at = at.parentNode;
            }
            seen = at instanceof FakeShadowRoot ? at.host : null;
        }

        return seen ?? this.body;
    }
}

class FakeStyleSheet {
    replaceSync(text) {
        this.text = text;
    }
}

/**
 * @param {{siteHost?: string, href?: string, viewport?: {width: number, height: number}, storage: object,
 *   sendMessage?: (message: object) => Promise<unknown>}} options storage stands in for chrome.storage.local; href is the
 *   page's address, https://<siteHost>/ by default. The isolated world has URL and crypto, as Chrome's has.
 */
export function fakePage({ siteHost = 'shop.acme-foods.example', href = `https://${siteHost}/`, viewport = { width: 1280, height: 800 }, storage, sendMessage = async () => undefined }) {
    const page = { shadowRoots: new Map(), focused: null };
    const document = new FakeDocument(page);
    page.document = document;
    const window = new FakeNode(page);
    const sentMessages = [];
    const context = vm.createContext({
        document,
        location: { host: siteHost, href },
        URL,
        crypto,
        innerWidth: viewport.width,
        innerHeight: viewport.height,
        CSSStyleSheet: FakeStyleSheet,
        requestAnimationFrame: (callback) => setTimeout(callback, 0),
        setTimeout,
        clearTimeout,
        chrome: {
            storage: { local: storage },
            runtime: {
                sendMessage: async (message) => {
                    sentMessages.push(structuredClone(message));

                    return sendMessage(message);
                },
            },
        },
    });
    vm.runInContext('globalThis.window = globalThis;', context);

    /** The path the event takes from its target, each node with the target its listeners see. */
    function pathOf(target, composed) {
        const path = [];
        let seen = target;
        for (let node = target; null !== node;) {
            path.push({ node, seen });
            if (node instanceof FakeShadowRoot) {
                if (!composed) {
                    break;
                }
                node = node.host;
                seen = node;
            } else if (node === document) {
                path.push({ node: window, seen });
                break;
            } else {
                node = node.parentNode;
            }
        }

        return path;
    }

    /**
     * Sends the event along its path and answers it, its defaultPrevented included.
     *
     * @param {FakeNode} target
     * @param {string} type
     * @param {object} init isTrusted (default true, as for the person's own input), bubbles and composed (default
     *   true), and the event's own fields such as key or clientX
     */
    function dispatch(target, type, { isTrusted = true, bubbles = true, composed = true, ...fields } = {}) {
        const path = pathOf(target, composed);
        let isStopped = false;
        const event = {
            type,
            isTrusted,
            bubbles,
            composed,
            defaultPrevented: false,
            ...fields,
            preventDefault() {
                this.defaultPrevented = true;
            },
            stopPropagation() {
                isStopped = true;
            },
        };
        const run = ({ node, seen }, capture) => {
            event.target = seen;
            event.currentTarget = node;
            event.composedPath = () => path.slice(path.findIndex((entry) => entry.seen === seen)).map((entry) => entry.node);
            for (const listener of node.listenersFor(type, capture)) {
                listener.call(node, event);
            }
        };
        for (const entry of [...path].reverse()) {
            run(entry, true);
            if (isStopped) {
                return event;
            }
        }
        for (const [index, entry] of path.entries()) {
            if (0 !== index && !bubbles) {
                break;
            }
            run(entry, false);
            if (isStopped) {
                break;
            }
        }

        return event;
    }

    return {
        document,
        window,
        sentMessages,
        /** Runs an extension file in the page's isolated world, as executeScript with `files` does. */
        inject(...files) {
            for (const file of files) {
                vm.runInContext(readFileSync(new URL(file, EXTENSION_ROOT), 'utf8'), context, { filename: file });
            }
        },
        /** Runs `source` in the isolated world and answers its value, as executeScript with `func` does. */
        evaluate: (source) => vm.runInContext(source, context),
        closedRootOf: (host) => page.shadowRoots.get(host) ?? null,
        get shadowRootCount() {
            return page.shadowRoots.size;
        },
        dispatch,
        /** A person's key press where focus is: the page's own element, or inside a root. */
        keydown: (target, key, init = {}) => dispatch(target, 'keydown', { key, ...init }),
        click: (target, init = {}) => dispatch(target, 'click', init),
        element(tagName, properties = {}) {
            const element = Object.assign(document.createElement(tagName), properties);
            document.body.append(element);

            return element;
        },
    };
}
