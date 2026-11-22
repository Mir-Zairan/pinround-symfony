import { runConsole } from './draft-picture';

/** SeedReviewRoundCommand::AREA_NAMES: round 2's screen areas, by position. */
export const REVIEW_AREAS = ['Cart summary', 'Checkout button'] as const;

/** SeedReviewRoundCommand::PIN_NOTES: the pins of round 1 on the first area, by number. */
export const EARLIER_PIN_NOTES = ['The total shows the old price.', 'The badge covers the basket icon.'] as const;

/** SeedReviewRoundCommand::LONG_PIN_NOTE: the first pin's note when seeded with longNote. */
export const LONG_PIN_NOTE = 'The total shows the old price after a coupon is removed. The line under it still lists the discount, '
  + 'so the sum at the bottom does not match the items above it. On a narrow screen the badge also covers the basket icon, '
  + 'and the delivery fee moves below the button, where it is easy to miss before paying. Check the total, the discount line, '
  + 'the badge and the delivery fee together, as one change touches all four.';

/** SeedReviewRoundCommand::REPORT_PIN_NOTES: the pins of Round 0, by number, and so the checklist's items. */
export const REPORT_PIN_NOTES = ['The coupon field takes no input.', 'The delivery date shows yesterday.'] as const;

export type ReviewRoundSeedOptions = {
  /** Give the first pin LONG_PIN_NOTE in place of EARLIER_PIN_NOTES[0]. */
  longNote?: boolean;
  /** The position of the area whose "after" picture stays pending, still processing. */
  pendingAfter?: number;
};

function numberSaid(said: string, pattern: RegExp, what: string): number {
  const number = pattern.exec(said)?.[1];
  if (undefined === number) {
    throw new Error(`app:e2e:seed-review-round did not name the ${what}: ${said}`);
  }
  return Number(number);
}

/**
 * Gives a ticket with no rounds, given to someone, a round 1 of theirs with the pins EARLIER_PIN_NOTES and an open
 * review round 2 with the areas REVIEW_AREAS, whose pictures are all ready unless options say otherwise, through the
 * dev-only console command app:e2e:seed-review-round (see runConsole for E2E_CONSOLE). Returns round 2's number.
 */
export function seedReviewRound(ticketKey: string, options: ReviewRoundSeedOptions = {}): number {
  const flags = [
    ...(options.longNote ? ['--long-note'] : []),
    ...(undefined === options.pendingAfter ? [] : [`--pending-after=${options.pendingAfter}`]),
  ];
  return numberSaid(runConsole('app:e2e:seed-review-round', 'acme-foods', ticketKey, ...flags), /Round (\d+) on /, 'review round');
}

export type ChecklistRoundSeedOptions = {
  /** Close the shared round and the review round afterwards, so the assignee may share another round (ADR-0048). */
  closed?: boolean;
};

/**
 * As seedReviewRound, after first giving the ticket its reporter's Round 0 with the pins REPORT_PIN_NOTES and the
 * checklist made from it, both items open. The checklist has no round number (ADR-0048, note of 2026-11-22), so the
 * assignee's shared round is still round 1. Returns that shared round's number.
 */
export function seedChecklistRound(ticketKey: string, options: ChecklistRoundSeedOptions = {}): number {
  const flags = ['--checklist', ...(options.closed ? ['--closed'] : [])];
  const said = runConsole('app:e2e:seed-review-round', 'acme-foods', ticketKey, ...flags);
  if (!said.includes(`The checklist from the report on ${ticketKey} has `)) {
    throw new Error(`app:e2e:seed-review-round did not make the checklist: ${said}`);
  }
  return numberSaid(said, /Round (\d+) on /, 'review round') - 1;
}
