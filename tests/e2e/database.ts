import { execFileSync } from 'node:child_process';
import { existsSync } from 'node:fs';
import path from 'node:path';

const REPO_ROOT = path.join(__dirname, '..', '..');

/**
 * Runs SQL as the database's superuser, which row-level security does not limit, and returns the rows it printed, one
 * per line with '|' between columns; statements split by ';' run in one transaction. For what no app path does, such
 * as removing a team member, or reading a count the app shows only capped.
 * .env.local exists only on a dev machine; COMPOSE_FILE picks CI's compose files.
 */
export function runAsDatabaseOwner(sql: string): string[] {
  const envFileOptions = ['.env', '.env.dev', '.env.local'].filter((file) => existsSync(path.join(REPO_ROOT, file))).flatMap((file) => ['--env-file', file]);
  const printed = execFileSync(
    'docker',
    ['compose', ...envFileOptions, 'exec', '-T', 'postgres', 'psql', '-U', 'pinround', '-d', 'pinround', '-X', '-q', '-A', '-t', '-v', 'ON_ERROR_STOP=1', '-c', sql],
    { cwd: REPO_ROOT, encoding: 'utf8' },
  );
  return printed.split('\n').filter((line) => line !== '');
}
