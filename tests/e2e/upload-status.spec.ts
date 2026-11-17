import { expect, test } from '@playwright/test';
// @ts-expect-error The browser modules are plain JavaScript with no type declarations.
import { MAX_POLLS, POLL_INTERVAL_MS, readUploadStatus, sleep, statusUrlFor, waitForProcessing } from '../../assets/review/upload_status.js';

// Pure helpers, run in Node with no page and no app.

test.describe('statusUrlFor', () => {
  test('puts the media id into the template, escaped', () => {
    expect(statusUrlFor('/media/MEDIA_ID/status', '0192f3a0-7b2c')).toBe('/media/0192f3a0-7b2c/status');
    expect(statusUrlFor('/media/MEDIA_ID/status', 'a/b?c')).toBe('/media/a%2Fb%3Fc/status');
  });
});

test.describe('waitForProcessing', () => {
  test('polls once a second and stops at the first ready answer', async () => {
    const answers = ['pending', 'ready', 'never read'];
    const waits: number[] = [];

    const status = await waitForProcessing(
      async () => answers.shift(),
      async (milliseconds: number) => void waits.push(milliseconds),
    );

    expect(status).toBe('ready');
    expect(waits).toEqual([POLL_INTERVAL_MS, POLL_INTERVAL_MS]);
    expect(POLL_INTERVAL_MS).toBe(1000);
    expect(answers).toEqual(['never read']);
  });

  test('stops at a rejected answer', async () => {
    expect(await waitForProcessing(async () => 'rejected', async () => {})).toBe('rejected');
  });

  test('reads exactly 15 times while pending and then answers pending', async () => {
    let reads = 0;

    const status = await waitForProcessing(
      async () => {
        reads += 1;
        return 'pending';
      },
      async () => {},
    );

    expect(status).toBe('pending');
    expect(reads).toBe(MAX_POLLS);
    expect(MAX_POLLS).toBe(15);
  });

  test('a ready answer at the fifteenth poll is still taken', async () => {
    let reads = 0;

    const status = await waitForProcessing(
      async () => {
        reads += 1;
        return 15 === reads ? 'ready' : 'pending';
      },
      async () => {},
    );

    expect(status).toBe('ready');
    expect(reads).toBe(15);
  });

  test('a wait that rejects ends the polling with its rejection and reads nothing more', async () => {
    let reads = 0;

    await expect(
      waitForProcessing(
        async () => {
          reads += 1;
          return 'pending';
        },
        async () => {
          throw new Error('aborted');
        },
      ),
    ).rejects.toThrow('aborted');
    expect(reads).toBe(0);
  });
});

test.describe('readUploadStatus', () => {
  const realFetch = globalThis.fetch;
  test.afterEach(() => {
    globalThis.fetch = realFetch;
  });

  function answerWith(response: { ok: boolean; json: () => Promise<unknown> }) {
    const requests: { url: unknown; init: RequestInit | undefined }[] = [];
    globalThis.fetch = (async (url: unknown, init?: RequestInit) => {
      requests.push({ url, init });
      return response;
    }) as typeof fetch;
    return requests;
  }

  test('reads ready and rejected from an OK answer, asking for JSON with the signal', async () => {
    const controller = new AbortController();
    const requests = answerWith({ ok: true, json: async () => ({ status: 'ready' }) });
    expect(await readUploadStatus('/media/a/status', controller.signal)).toBe('ready');
    expect(requests).toHaveLength(1);
    expect(requests[0].url).toBe('/media/a/status');
    expect(requests[0].init?.signal).toBe(controller.signal);
    expect(requests[0].init?.headers).toEqual({ Accept: 'application/json' });

    answerWith({ ok: true, json: async () => ({ status: 'rejected' }) });
    expect(await readUploadStatus('/media/a/status')).toBe('rejected');
  });

  test('a non-OK answer, even one that says ready, counts as pending', async () => {
    answerWith({ ok: false, json: async () => ({ status: 'ready' }) });
    expect(await readUploadStatus('/media/a/status')).toBe('pending');
  });

  test('a status the server does not define counts as pending', async () => {
    answerWith({ ok: true, json: async () => ({ status: 'exploded' }) });
    expect(await readUploadStatus('/media/a/status')).toBe('pending');
  });

  test('a body that is not JSON counts as pending', async () => {
    answerWith({
      ok: true,
      json: async () => {
        throw new SyntaxError('Unexpected token');
      },
    });
    expect(await readUploadStatus('/media/a/status')).toBe('pending');
  });

  test('a lost connection counts as pending', async () => {
    globalThis.fetch = (async () => {
      throw new TypeError('Failed to fetch');
    }) as typeof fetch;
    expect(await readUploadStatus('/media/a/status', new AbortController().signal)).toBe('pending');
  });

  test('a failure after the signal aborted is rethrown', async () => {
    const controller = new AbortController();
    globalThis.fetch = (async () => {
      controller.abort();
      throw new DOMException('Aborted', 'AbortError');
    }) as typeof fetch;
    await expect(readUploadStatus('/media/a/status', controller.signal)).rejects.toMatchObject({ name: 'AbortError' });
  });
});

test.describe('sleep', () => {
  test('rejects at once when the signal is already aborted, without starting a timer', async () => {
    const controller = new AbortController();
    controller.abort();
    const realSetTimeout = globalThis.setTimeout;
    let timers = 0;
    globalThis.setTimeout = ((...args: Parameters<typeof setTimeout>) => {
      timers += 1;
      return realSetTimeout(...args);
    }) as typeof setTimeout;
    try {
      await expect(sleep(60_000, controller.signal)).rejects.toMatchObject({ name: 'AbortError' });
    } finally {
      globalThis.setTimeout = realSetTimeout;
    }
    expect(timers).toBe(0);
  });

  test('rejects when the signal aborts during the wait and clears its timer', async () => {
    const controller = new AbortController();
    const realSetTimeout = globalThis.setTimeout;
    const realClearTimeout = globalThis.clearTimeout;
    const cleared: unknown[] = [];
    let timer: unknown;
    globalThis.setTimeout = (() => (timer = 42)) as unknown as typeof setTimeout;
    globalThis.clearTimeout = ((handle: unknown) => void cleared.push(handle)) as typeof clearTimeout;
    try {
      const waiting = sleep(60_000, controller.signal);
      controller.abort();
      await expect(waiting).rejects.toMatchObject({ name: 'AbortError' });
    } finally {
      globalThis.setTimeout = realSetTimeout;
      globalThis.clearTimeout = realClearTimeout;
    }
    expect(cleared).toEqual([timer]);
  });

  test('resolves when the time is up and the signal never aborts', async () => {
    const realSetTimeout = globalThis.setTimeout;
    globalThis.setTimeout = ((callback: () => void) => {
      queueMicrotask(callback);
      return 1;
    }) as unknown as typeof setTimeout;
    try {
      await expect(sleep(1000, new AbortController().signal)).resolves.toBeUndefined();
    } finally {
      globalThis.setTimeout = realSetTimeout;
    }
  });
});
