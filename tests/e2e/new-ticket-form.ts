import { expect, type Page } from '@playwright/test';

/** The description fillTicket types: two lines, with accents, an emoji and CJK text. */
export const TICKET_DESCRIPTION = 'The banner covers the total.\nSecond line, with café ☕ and 画面.';

/** Opens /tickets/new as the page's person and returns its fields and the picture section's parts. */
export async function openNewTicketForm(page: Page) {
  // Stimulus controllers connect after the importmap modules load, and nothing in the page marks that moment.
  await page.goto('/tickets/new', { waitUntil: 'networkidle' });
  const form = page.locator('form.login__form');
  return {
    form,
    title: form.getByLabel('Title', { exact: true }),
    description: form.getByLabel('Description', { exact: true }),
    section: form.locator('section.screenshot-report'),
    fileInput: form.getByLabel('Upload a file', { exact: true }),
    uploadStatus: form.locator('[data-presigned-upload-target="status"]'),
    holdStatus: form.locator('[data-screenshot-report-target="status"]'),
    mediaId: form.locator('input[name="screenshot_media_id"]'),
    frame: form.locator('.screenshot__frame'),
    pins: form.locator('.screenshot__frame .pin'),
    pinCount: form.locator('.screenshot-report__count'),
    submit: form.getByRole('button', { name: 'Make ticket' }),
  };
}

export type TicketForm = Awaited<ReturnType<typeof openNewTicketForm>>;

/** Fills the title, TICKET_DESCRIPTION and the first type and priority, leaving the default project. */
export async function fillTicket(form: TicketForm, title: string) {
  await form.title.fill(title);
  await form.description.fill(TICKET_DESCRIPTION);
  await form.form.locator('input[name="type"]').first().check();
  await form.form.locator('input[name="priority"]').first().check();
}

/** Clicks the picture where it is 25% and 70% across, and 40% and 75% down: two pins in different places. */
export async function placeTwoPins(form: TicketForm) {
  const box = (await form.frame.boundingBox())!;
  expect(box.width).toBeGreaterThan(100);
  await form.frame.click({ position: { x: box.width * 0.25, y: box.height * 0.4 } });
  await expect(form.pins).toHaveCount(1);
  await form.frame.click({ position: { x: box.width * 0.7, y: box.height * 0.75 } });
  await expect(form.pins).toHaveCount(2);
}
