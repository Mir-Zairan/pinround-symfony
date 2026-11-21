// The only network code of the extension. It calls the configured pinround origin, and PUTs a file only to the
// put_url that origin answered for it (the presigned storage URL, ADR-0011). Every other URL is refused before a
// request is made.

export class ApiError extends Error {
    /**
     * @param {string} message the server's plain message, or ours
     * @param {number} status the HTTP status, 0 when the server was not reached
     * @param {string|null} outcome the refusal's `outcome` (docs/api/v1.yaml Refused), such as missing_scope; null when
     *   the answer named none
     */
    constructor(message, status, outcome = null) {
        super(message);
        this.status = status;
        this.outcome = outcome;
    }

    /** True when pinround may have done the request although no answer came: not reached, or a server failure. */
    get mayHaveBeenSaved() {
        return 0 === this.status || this.status >= 500;
    }
}

export const NOT_REACHED = 'Could not reach pinround. Check the address and your connection.';
export const OTHER_HOST = 'The extension only talks to the pinround address you set.';
export const MISSING_SCOPE = 'missing_scope';
export const NOT_FOUND = 'not_found';
export const PAIR_AGAIN = 'pinround does not accept this token any more. Pair the extension again in its options.';
export const CONNECT_AGAIN = 'Your pinround connection ended. Press Connect to pinround in the extension options.';

export class PinroundApi {
    /**
     * @param {string} origin the checked pinround origin (settings.js pinroundOriginOf)
     * @param {string} token a personal access token, or an access token a Connect issued
     * @param {typeof fetch} fetchFn
     * @param {((refusedToken: string) => Promise<string|null>)|null} renewToken for an issued access token: answers the
     *   token to retry with once after a 401, or null when the connection ended, or throws the ApiError to show; null
     *   for a personal access token
     */
    constructor(origin, token, fetchFn = (...args) => fetch(...args), renewToken = null) {
        this.origin = origin;
        this.token = token;
        this.fetchFn = fetchFn;
        this.renewToken = renewToken;
        /** @type {Set<string>} the put_urls this origin answered, the only other URLs a PUT may go to */
        this.issuedPutUrls = new Set();
    }

    /**
     * @param {'GET'|'POST'} method
     * @param {string} path starting with /api/v1/
     * @param {object|undefined} body
     * @param {string|undefined} idempotencyKey
     * @returns {Promise<any>} the JSON answer
     */
    async call(method, path, body = undefined, idempotencyKey = undefined) {
        const url = new URL(path, this.origin);
        if (url.origin !== this.origin || !url.pathname.startsWith('/api/v1/')) {
            throw new ApiError(OTHER_HOST, 0);
        }
        let response = await this.send(url, method, body, idempotencyKey);
        // A 401 means pinround did nothing, so the same request, with the same idempotency key, is safe to send again.
        if (401 === response.status && null !== this.renewToken) {
            const renewedToken = await this.renewToken(this.token);
            if (null === renewedToken) {
                throw new ApiError(CONNECT_AGAIN, 401);
            }
            this.token = renewedToken;
            response = await this.send(url, method, body, idempotencyKey);
        }
        const parsed = await response.json().catch(() => ({}));
        const answer = null !== parsed && 'object' === typeof parsed ? parsed : {};
        if (!response.ok) {
            throw new ApiError(
                401 === response.status ? (null === this.renewToken ? PAIR_AGAIN : CONNECT_AGAIN) : ('string' === typeof answer.error ? answer.error : `pinround answered ${response.status}.`),
                response.status,
                'string' === typeof answer.outcome ? answer.outcome : null,
            );
        }
        if ('string' === typeof answer.put_url) {
            this.issuedPutUrls.add(answer.put_url);
        }

        return answer;
    }

    async send(url, method, body, idempotencyKey) {
        const headers = { Authorization: `Bearer ${this.token}`, Accept: 'application/json' };
        if (undefined !== body) {
            headers['Content-Type'] = 'application/json';
        }
        if (undefined !== idempotencyKey) {
            headers['Idempotency-Key'] = idempotencyKey;
        }
        try {
            return await this.fetchFn(url.href, { method, headers, body: undefined === body ? undefined : JSON.stringify(body), credentials: 'omit', redirect: 'error' });
        } catch {
            throw new ApiError(NOT_REACHED, 0);
        }
    }

    /**
     * @param {{put_url: string, headers: Object<string, string>}} slot an answer of POST /api/v1/uploads
     * @param {Blob} file
     */
    async putFile(slot, file) {
        const putUrl = new URL(slot.put_url);
        if (!this.issuedPutUrls.has(slot.put_url) || ('https:' !== putUrl.protocol && 'localhost' !== putUrl.hostname)) {
            throw new ApiError(OTHER_HOST, 0);
        }
        // Content-Length is set by the browser from the body; fetch may not set it.
        const headers = Object.fromEntries(Object.entries(slot.headers ?? {}).filter(([name]) => 'content-length' !== name.toLowerCase()));
        let response;
        try {
            response = await this.fetchFn(slot.put_url, { method: 'PUT', headers, body: file, credentials: 'omit', redirect: 'error' });
        } catch {
            throw new ApiError(NOT_REACHED, 0);
        }
        this.issuedPutUrls.delete(slot.put_url);
        if (!response.ok) {
            throw new ApiError('The screenshot could not be stored. Try again.', response.status);
        }
    }
}
