import { type Page, type Route } from '@playwright/test';
import { deflateSync } from 'node:zlib';

// The browser side of an upload answered in the browser: the bucket, the begin and complete calls and the status poll.
// A browser PUT to the bucket needs its CORS to allow this origin, which the dev stack does for the app's own origin only.

// The dev stack's OBJECT_STORAGE_PUBLIC_URL (.env.dev), the only storage origin the page's CSP lets it connect to.
export const STORAGE_URL = 'https://localhost:9443/pinround-dev/uploads/slot';
export const MEDIA_ID = '0192f3a0-7b2c-7d3e-8a4f-5b6c7d8e9f02';
const APP_ORIGIN = new URL(process.env.E2E_BASE_URL ?? 'https://localhost').origin;
// What the bucket's CORS rule answers for the app's origin; a PUT with a Content-Type is preflighted.
const STORAGE_CORS = {
  'Access-Control-Allow-Origin': APP_ORIGIN,
  'Access-Control-Allow-Methods': 'PUT, GET, HEAD',
  'Access-Control-Allow-Headers': 'content-type',
  Vary: 'Origin',
};

// Only the PNG signature and the start of a header chunk: enough for the type check, too short for a browser to draw.
export const PNG = Buffer.from('89504e470d0a1a0a0000000d49484452', 'hex');

function crc32(bytes: Buffer): number {
  let crc = 0xffffffff;
  for (const byte of bytes) {
    crc ^= byte;
    for (let bit = 0; bit < 8; bit += 1) {
      crc = (crc >>> 1) ^ (0xedb88320 & -(crc & 1));
    }
  }
  return (crc ^ 0xffffffff) >>> 0;
}

function pngChunk(type: string, data: Buffer): Buffer {
  const length = Buffer.alloc(4);
  length.writeUInt32BE(data.length);
  const body = Buffer.concat([Buffer.from(type, 'ascii'), data]);
  const crc = Buffer.alloc(4);
  crc.writeUInt32BE(crc32(body));
  return Buffer.concat([length, body, crc]);
}

/** A solid grey PNG a browser can draw, so the pin layer has a picture of a known size to click on. */
export function drawablePng(width: number, height: number): Buffer {
  const header = Buffer.alloc(13);
  header.writeUInt32BE(width, 0);
  header.writeUInt32BE(height, 4);
  header[8] = 8; // bit depth
  header[9] = 2; // RGB
  const row = Buffer.concat([Buffer.from([0]), Buffer.alloc(width * 3, 0xc8)]);
  const pixels = Buffer.concat(Array.from({ length: height }, () => row));
  return Buffer.concat([
    PNG.subarray(0, 8),
    pngChunk('IHDR', header),
    pngChunk('IDAT', deflateSync(pixels)),
    pngChunk('IEND', Buffer.alloc(0)),
  ]);
}

type PutAnswer = { status: number; body?: string };

/** Answers the browser's preflight like the bucket, and each PUT with what onPut returns. */
export async function routeStorage(page: Page, onPut: (route: Route) => Promise<PutAnswer> | PutAnswer = () => ({ status: 200 })) {
  await page.route(STORAGE_URL, async (route) => {
    if ('OPTIONS' === route.request().method()) {
      await route.fulfill({ status: 200, headers: STORAGE_CORS, body: '' });
      return;
    }
    const { status, body = '' } = await onPut(route);
    await route.fulfill({ status, headers: STORAGE_CORS, body }).catch(() => {});
  });
}

/** What PresignedUploads::forPut answers: the signed Content-Type and Content-Length headers. */
export function beginAnswer(intentId: string, byteLength: number = PNG.length) {
  return {
    status: 201,
    json: {
      intentId,
      url: STORAGE_URL,
      method: 'PUT',
      headers: { 'Content-Type': 'image/png', 'Content-Length': String(byteLength) },
      expiresAt: '2026-11-06T12:05:00+00:00',
    },
  };
}

/** The status the page polls after a complete call; by default the picture is ready. Returns the poll counter. */
export async function routeStatus(page: Page, answer: (poll: number) => { status: number; json: unknown } = () => ({ status: 200, json: { status: 'ready' } })) {
  let polls = 0;
  await page.route('**/media/*/status', (route) => {
    polls += 1;
    return route.fulfill(answer(polls));
  });
  return () => polls;
}

/** The three calls of one upload answered in the browser: begin, the bucket PUT and complete (a pending picture). */
export async function routeCompletedUpload(page: Page, intentId: string, byteLength: number = PNG.length) {
  await page.route('**/media/intents', (route) => route.fulfill(beginAnswer(intentId, byteLength)));
  await routeStorage(page);
  await page.route(`**/media/intents/${intentId}/complete`, (route) => route.fulfill({ status: 202, json: { mediaId: MEDIA_ID, status: 'pending' } }));
}
