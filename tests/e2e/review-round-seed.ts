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

export type ReviewRoundSeedOptions = {
  /** Give the first pin LONG_PIN_NOTE in place of EARLIER_PIN_NOTES[0]. */
  longNote?: boolean;
  /** The position of the area whose "after" picture stays pending, still processing. */
  pendingAfter?: number;
};

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
  const said = runConsole('app:e2e:seed-review-round', 'acme-foods', ticketKey, ...flags);
  const number = /Round (\d+) on /.exec(said)?.[1];
  if (undefined === number) {
    throw new Error(`app:e2e:seed-review-round did not name the review round: ${said}`);
  }
  return Number(number);
}
