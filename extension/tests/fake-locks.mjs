// A stand-in for navigator.locks (the Web Locks API) in node --test, with the rules the extension relies on: an
// exclusive lock per name, held by one callback at a time, granted in the order requested, and released when the
// callback's promise settles, a rejection included, which request() then passes on.

const NOT_MODELLED = 'The fake locks model request(name, callback) only: an exclusive lock that waits its turn.';

export function fakeLocks() {
    const tails = new Map();
    const granted = [];
    const waitingForRequests = [];
    let requestCount = 0;

    return {
        request(name, ...rest) {
            const callback = rest.at(-1);
            if ('string' !== typeof name || 'function' !== typeof callback || rest.length > 1) {
                throw new TypeError(NOT_MODELLED);
            }
            requestCount += 1;
            waitingForRequests.filter((waiting) => waiting.count <= requestCount).forEach((waiting) => waiting.resolve());
            const before = tails.get(name) ?? Promise.resolve();
            const held = before.then(() => {
                granted.push(name);

                return callback({ name, mode: 'exclusive' });
            });
            tails.set(name, held.then(() => undefined, () => undefined));

            return held;
        },
        /** The names of the locks granted so far, in the order granted. */
        granted,
        /** Settles once `count` requests were made in all, so a test knows a second holder is waiting. */
        requested(count) {
            return new Promise((resolve) => {
                if (requestCount >= count) {
                    resolve();
                } else {
                    waitingForRequests.push({ count, resolve });
                }
            });
        },
    };
}
