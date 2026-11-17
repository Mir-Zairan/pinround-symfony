import { execFileSync } from 'node:child_process';
import path from 'node:path';

const REPO_ROOT = path.join(__dirname, '..', '..');

/**
 * Runs one bin/console command in the app and returns what it printed. E2E_CONSOLE is the command that runs
 * bin/console in the app, as words separated by spaces; it defaults to the dev stack's
 * `docker compose exec -T app php bin/console`.
 */
export function runConsole(...args: string[]): string {
  const [program, ...consoleArgs] = (process.env.E2E_CONSOLE ?? 'docker compose exec -T app php bin/console').split(' ');
  return execFileSync(program, [...consoleArgs, ...args], { cwd: REPO_ROOT, encoding: 'utf8' });
}

/**
 * Starts the draft round of the person the ticket is given to and puts one ready picture in it, through the dev-only
 * console command app:e2e:seed-draft-with-picture: no processing worker, bucket or vips is needed, and the picture has
 * no file behind it. Call it before that person starts a draft by hand: a person holds one open draft per ticket.
 */
export function seedDraftWithPicture(ticketKey: string): void {
  runConsole('app:e2e:seed-draft-with-picture', 'acme-foods', ticketKey);
}
