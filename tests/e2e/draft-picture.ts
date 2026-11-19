import { execFileSync } from 'node:child_process';
import path from 'node:path';

const REPO_ROOT = path.join(__dirname, '..', '..');

function consoleCommandWords(): string[] {
  return (process.env.E2E_CONSOLE ?? 'docker compose exec -T app php bin/console').split(' ');
}

/**
 * Runs one bin/console command in the app and returns what it printed. E2E_CONSOLE is the command that runs
 * bin/console in the app, as words separated by spaces; it defaults to the dev stack's
 * `docker compose exec -T app php bin/console`.
 */
export function runConsole(...args: string[]): string {
  const [program, ...consoleArgs] = consoleCommandWords();
  return execFileSync(program, [...consoleArgs, ...args], { cwd: REPO_ROOT, encoding: 'utf8' });
}

/**
 * Runs PHP code with `php -r` in the app, with stdin as given, and returns what it printed: the E2E_CONSOLE command
 * without its last word, bin/console. The code runs in the app's directory, so it can require vendor/autoload.php.
 */
export function runPhp(code: string, stdin = ''): string {
  const words = consoleCommandWords();
  if ('bin/console' !== words.at(-1)) {
    throw new Error(`E2E_CONSOLE must end with bin/console to run PHP in the app; it is "${words.join(' ')}".`);
  }
  const [program, ...phpArgs] = words.slice(0, -1);
  return execFileSync(program, [...phpArgs, '-r', code], { cwd: REPO_ROOT, encoding: 'utf8', input: stdin });
}

/**
 * Starts the draft round of the person the ticket is given to and puts one ready picture in it, through the dev-only
 * console command app:e2e:seed-draft-with-picture: no processing worker, bucket or vips is needed, and the picture has
 * no file behind it. Call it before that person starts a draft by hand: a person holds one open draft per ticket.
 */
export function seedDraftWithPicture(ticketKey: string): void {
  runConsole('app:e2e:seed-draft-with-picture', 'acme-foods', ticketKey);
}
