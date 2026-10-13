import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const E2E_DIR = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');

/** Runs wp-cli on the tests site, for what no REST route reaches (user meta). Slow: a few seconds a call. */
export function wpCli(...args: string[]): string {
  return execFileSync(path.join(E2E_DIR, 'node_modules/.bin/wp-env'), ['run', 'tests-cli', 'wp', ...args], {
    cwd: E2E_DIR,
    encoding: 'utf8',
    stdio: ['ignore', 'pipe', 'pipe'],
  }).trim();
}
