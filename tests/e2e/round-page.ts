import type { Page } from '@playwright/test';

/** The review round page's side panel: the selected area's earlier pins, its verdict form and the next-step offer. */
export function roundPanel(page: Page) {
  return page.locator('aside#round-panel');
}

/** The tab of the screen area with that name, in the round page's tab row. */
export function areaTab(page: Page, name: string) {
  return page.getByRole('tablist', { name: 'Screen areas' }).getByRole('tab', { name: new RegExp(`^${name}`) });
}

/** Clicks Save verdict in the panel and returns the HTTP status of the verdict POST. */
export async function saveVerdict(page: Page): Promise<number> {
  const answer = page.waitForResponse((response) => /\/areas\/[^/]+\/verdict$/.test(new URL(response.url()).pathname) && 'POST' === response.request().method());
  await roundPanel(page).getByRole('button', { name: 'Save verdict' }).click();
  return (await answer).status();
}
