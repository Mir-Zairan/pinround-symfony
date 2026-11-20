import { EXTENSION_ID, TEST_PAGE_ORIGIN, TEST_PAGE_URL, expect, openPopupFor, tabIdOf, test, type ExtensionPageGlobals } from './extension';

const OTHER_ORIGIN = 'http://news.acme-foods.test';

test('the extension loads and its popup opens at the test id', async ({ context }) => {
  const popup = await context.newPage();
  await popup.goto(`chrome-extension://${EXTENSION_ID}/popup.html`);

  await expect(popup.getByRole('heading', { name: 'Capture with pinround', level: 1 })).toBeVisible();
  await expect(popup.getByText('Connect the extension to your pinround first.')).toBeVisible();
});

test('the test page is on its own origin, not on pinround', async ({ testPage, baseURL }) => {
  await expect(testPage.getByRole('heading', { name: 'Acme Foods shop', level: 1 })).toBeVisible();
  const origin = new URL(testPage.url()).origin;

  expect(origin).toBe(TEST_PAGE_ORIGIN);
  expect(origin).not.toBe('https://localhost');
  expect(origin).not.toBe(new URL(baseURL ?? 'https://localhost').origin);
});

test('from the popup, a script runs in the test page tab and reads what the person typed there', async ({ context, testPage }) => {
  await testPage.getByLabel('Email for the receipt').fill('mia@acme-foods.example');
  const popup = await openPopupFor(context, testPage);
  const tabId = await tabIdOf(popup, TEST_PAGE_URL);

  const activeTabUrls = await popup.evaluate(async () => {
    const { chrome } = globalThis as unknown as ExtensionPageGlobals;
    return (await chrome.tabs.query({ active: true, currentWindow: true })).map((tab) => tab.url);
  });
  const typed = await popup.evaluate(async (target) => {
    const { chrome } = globalThis as unknown as ExtensionPageGlobals;
    const results = await chrome.scripting.executeScript({ target: { tabId: target }, func: () => (document.getElementById('email') as HTMLInputElement).value });
    return results.map((injection) => injection.result);
  }, tabId);

  expect(activeTabUrls).toEqual([TEST_PAGE_URL]);
  expect(typed).toEqual(['mia@acme-foods.example']);
});

test('a page on another origin stays out of reach, with the refusal the fake copies', async ({ context, testPage }) => {
  await context.route(`${OTHER_ORIGIN}/**`, (route) => route.fulfill({ contentType: 'text/html', body: '<!doctype html><title>Acme Foods news</title><h1>Acme Foods news</h1>' }));
  const popup = await openPopupFor(context, testPage);
  const tabId = await tabIdOf(popup, TEST_PAGE_URL);
  await testPage.goto(`${OTHER_ORIGIN}/`);
  await expect(testPage.getByRole('heading', { name: 'Acme Foods news' })).toBeVisible();

  const refusal = await popup.evaluate(async (target) => {
    const { chrome } = globalThis as unknown as ExtensionPageGlobals;
    try {
      await chrome.scripting.executeScript({ target: { tabId: target }, func: () => document.title });
      return 'ran';
    } catch (error) {
      return (error as Error).message;
    }
  }, tabId);

  expect(refusal).toBe('Cannot access contents of the page. Extension manifest must request permission to access the respective host.');
});
