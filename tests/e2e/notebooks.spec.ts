import { randomUUID } from 'node:crypto';
import { expect, test } from '@playwright/test';
import { LENA_EMAIL, LENA_STORAGE_STATE } from './auth';
import { runAsDatabaseOwner } from './database';
import { openAs, TOAST_REGION } from './ticket';

// "My notebooks" (ADR-0046, P13.T6): Lena finds her notebooks from the report drafts list, opens one, renames it and
// deletes it. Who else may open, rename or delete one, and what stays unchanged when they try, is proven in
// tests/Functional/Review/NotebookControllerTest; here it is the journey in a real browser.
//
// The notebook is written straight into rvw_notebook, as Claude Code or the extension leaves one, with one scenario and
// no picture, so no worker or bucket is needed. It is used now, so it is first in Lena's list.

test.use({ viewport: { width: 1280, height: 900 } });

const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/;
const seededNotebookIds: string[] = [];

function seedLenasNotebook(title: string): string {
  const scenarios = JSON.stringify([
    {
      title: 'Warn on empty stock',
      why: 'OrderLine::warn() runs when stock is 0.',
      setup: 'One SHOP product with no stock.',
      start_url: 'http://localhost:8000/orders',
      steps: [{ type: 'go_to', say: 'Open the order.', url: 'http://localhost:8000/orders/7' }],
    },
  ]);
  const [id] = runAsDatabaseOwner(`
    INSERT INTO rvw_notebook (organization_id, id, owner_id, title, screenshots, scenarios, version, created_at, updated_at, last_used_at)
    SELECT o.organization_id, uuidv7(), u.id, '${title}', '[]'::jsonb, '${scenarios}'::jsonb, 1, now(), now(), now()
    FROM org_organization o, idn_user u WHERE o.slug = 'acme-foods' AND u.email = '${LENA_EMAIL}'
    RETURNING id`);
  expect(id, 'the seeded Acme Foods or Lena is missing: run make seed').toMatch(UUID);
  seededNotebookIds.push(id);
  return id;
}

test.afterEach(() => {
  for (const id of seededNotebookIds.splice(0)) {
    runAsDatabaseOwner(`DELETE FROM rvw_notebook WHERE id = '${id}'`);
  }
});

test('Lena opens My notebooks from the report drafts list, renames a notebook and deletes it', async ({ browser }) => {
  const suffix = randomUUID().slice(0, 8);
  const title = `Coupon field questions ${suffix}`;
  const newTitle = `Coupon field answers ${suffix}`;
  const notebookId = seedLenasNotebook(title);
  const { context, page } = await openAs(browser, LENA_STORAGE_STATE);

  await page.goto('/report-drafts');
  await page.getByRole('link', { name: 'Your notebooks' }).click();

  await expect(page).toHaveURL(/\/notebooks$/);
  await expect(page.getByRole('heading', { name: 'My notebooks', level: 1 })).toBeVisible();
  const card = page.locator('article.report-drafts__card').filter({ hasText: title });
  await expect(card).toContainText('0 screenshots · 1 scenario · Last used now');
  await card.getByRole('link', { name: title }).click();

  await expect(page).toHaveURL(new RegExp(`/notebooks/${notebookId}$`));
  await expect(page.getByRole('heading', { name: title, level: 1 })).toBeVisible();
  await expect(page.getByText('No screenshots yet.')).toBeVisible();
  await expect(page.getByRole('region', { name: 'Scenarios' }).getByRole('listitem')).toHaveText(['Warn on empty stock']);

  await page.getByLabel('Notebook title').fill(newTitle);
  await page.getByRole('button', { name: 'Rename' }).click();

  await expect(page.locator(TOAST_REGION)).toHaveText('Notebook renamed.');
  await expect(page.getByRole('heading', { name: newTitle, level: 1 })).toBeVisible();
  expect(runAsDatabaseOwner(`SELECT title, version FROM rvw_notebook WHERE id = '${notebookId}'`)).toEqual([`${newTitle}|2`]);

  page.once('dialog', async (dialog) => {
    expect(dialog.message()).toBe('Delete this notebook? This cannot be undone.');
    await dialog.accept();
  });
  await page.getByRole('button', { name: 'Delete notebook' }).click();

  await expect(page).toHaveURL(/\/notebooks$/);
  await expect(page.locator(TOAST_REGION)).toHaveText('Notebook deleted.');
  await expect(page.locator('article.report-drafts__card').filter({ hasText: suffix })).toHaveCount(0);
  expect(runAsDatabaseOwner(`SELECT count(*) FROM rvw_notebook WHERE id = '${notebookId}'`)).toEqual(['0']);
  await context.close();
});
