import { expect, type Page } from '@playwright/test';
import { OMAR_EMAIL } from './auth';
import { runAsDatabaseOwner } from './database';

// VisibleBellTickets reads the open rows of only TicketQueries::MAX_CHECKED_TICKET_IDS tickets, and DbalBellQueries
// shows only MAX_BELL_GROUPS groups, the biggest first, so past either cap a new one-row group may never show.
const MAX_CHECKED_TICKET_IDS = 200;
const MAX_BELL_GROUPS = 50;
// Room for the groups the specs running beside one add to Omar's bell meanwhile.
const BELL_CAP_HEADROOM = 10;

/**
 * Fails at once when Omar's open bell is near a cap, as after e2e runs that never mark their notifications done; make
 * seed (SeedDemoBells) brings it back down. Run it first in a test that waits for a new group to show.
 */
export function expectOmarUnderBellCaps(): void {
  const [counts] = runAsDatabaseOwner(`
    SELECT count(DISTINCT n.ticket_id), count(DISTINCT (n.actor_id, n.ticket_id)) FROM ntf_notification n
    JOIN idn_user omar ON omar.id = n.user_id AND omar.email = '${OMAR_EMAIL}'
    WHERE n.done_at IS NULL AND n.actor_id IS NOT NULL AND n.ticket_id IS NOT NULL`);
  const [openTickets, openGroups] = counts.split('|').map(Number);
  expect(openTickets, `Omar has ${openTickets} open bell tickets: run make seed`).toBeLessThan(MAX_CHECKED_TICKET_IDS - BELL_CAP_HEADROOM);
  expect(openGroups, `Omar has ${openGroups} open bell groups: run make seed`).toBeLessThan(MAX_BELL_GROUPS - BELL_CAP_HEADROOM);
}

export function bellButton(page: Page) {
  return page.getByRole('banner').getByRole('button', { name: 'Pings' });
}

/** The open popover, named by its frame's title, so it matches only once the frame has loaded. */
export function bellPopover(page: Page) {
  return page.getByRole('dialog', { name: /^Pings · \d+ open$/ });
}

/** On a loaded page: opens the bell's popover and waits for its frame from GET /bell. */
export async function openBellPopover(page: Page): Promise<void> {
  const frameLoaded = page.waitForResponse((response) => new URL(response.url()).pathname === '/bell' && response.request().method() === 'GET');
  // Stimulus connects the bell after the importmap modules load, which networkidle does not always wait for under load,
  // and a click before that does nothing; a click after it opens the popover at once, so only an unanswered click is repeated.
  await expect(async () => {
    await bellButton(page).click();
    await expect(bellButton(page)).toHaveAttribute('aria-expanded', 'true', { timeout: 1_000 });
  }).toPass({ intervals: [250, 500, 1_000], timeout: 10_000 });
  expect((await frameLoaded).status()).toBe(200);
  await expect(bellPopover(page)).toBeVisible();
  // The frame's own text, so a check that a card is absent never runs before the frame has rendered.
  await expect(bellPopover(page).getByText('Grouped by person and ticket.')).toBeVisible();
}
