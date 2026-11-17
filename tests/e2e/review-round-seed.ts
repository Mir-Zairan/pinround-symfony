import { runConsole } from './draft-picture';

/** SeedReviewRoundCommand::AREA_NAMES: round 2's screen areas, by position. */
export const REVIEW_AREAS = ['Cart summary', 'Checkout button'] as const;

/** SeedReviewRoundCommand::PIN_NOTES: the pins of round 1 on the first area, by number. */
export const EARLIER_PIN_NOTES = ['The total shows the old price.', 'The badge covers the basket icon.'] as const;

/**
 * Gives a ticket with no rounds, given to someone, a round 1 of theirs with the pins EARLIER_PIN_NOTES and an open
 * review round 2 with the areas REVIEW_AREAS, whose pictures are all ready, through the dev-only console command
 * app:e2e:seed-review-round (see runConsole for E2E_CONSOLE). Returns round 2's number.
 */
export function seedReviewRound(ticketKey: string): number {
  const said = runConsole('app:e2e:seed-review-round', 'acme-foods', ticketKey);
  const number = /Round (\d+) on /.exec(said)?.[1];
  if (undefined === number) {
    throw new Error(`app:e2e:seed-review-round did not name the review round: ${said}`);
  }
  return Number(number);
}
