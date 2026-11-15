import { expect, test } from '@playwright/test';
// @ts-expect-error The browser modules are plain JavaScript with no type declarations.
import { MAX_POLLS, POLL_INTERVAL_MS, statusUrlFor, waitForProcessing } from '../../assets/review/upload_status.js';

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
