// Base64 for pictures the extension keeps or passes between its own pages, which take only JSON.

// String.fromCharCode takes its bytes as arguments, and an engine caps how many one call may have.
const CHUNK_BYTES = 0x8000;

/** @param {Uint8Array} bytes @returns {string} standard base64, with padding */
export function base64Of(bytes) {
    let binary = '';
    for (let start = 0; start < bytes.length; start += CHUNK_BYTES) {
        binary += String.fromCharCode(...bytes.subarray(start, start + CHUNK_BYTES));
    }

    return btoa(binary);
}

/** @returns {Promise<string>} the blob's bytes in standard base64 */
export async function base64OfBlob(blob) {
    return base64Of(new Uint8Array(await blob.arrayBuffer()));
}

/** @param {string} base64 @returns {Uint8Array} @throws {DOMException} when it is not base64 */
export function bytesOfBase64(base64) {
    return Uint8Array.from(atob(base64), (character) => character.charCodeAt(0));
}
