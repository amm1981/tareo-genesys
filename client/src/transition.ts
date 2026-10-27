import dotenv from 'dotenv';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { parseArgs } from 'node:util';
import { environmentSchema } from './config/env.js';
import { acquireLock } from './services/lock.js';
import { transition } from './automation/transition.js';
import { failureCode } from './automation/runner.js';
import { CURRENT_SCHEMA_FILE } from './config/source.js';
process.chdir(resolve(dirname(fileURLToPath(import.meta.url)), '..'));
dotenv.config({ quiet: true });
async function main() {
  const { values } = parseArgs({ options: { apply: { type: 'boolean', default: false }, 'schema-file': { type: 'string' } }, strict: true });
  const env = environmentSchema.parse({ ...process.env, API_BASE_URL: process.env.AUTO_API_BASE_URL ?? 'https://api.labour.agrocalera.app', SQL_SCHEMA_FILE: values['schema-file'] ?? CURRENT_SCHEMA_FILE });
  if (env.API_BASE_URL !== 'https://api.labour.agrocalera.app') throw new Error('AUTOMATION_DESTINATION_NOT_ALLOWED');
  const lock = await acquireLock(env.SYNC_LOCK_PORT);
  try { console.log(JSON.stringify(await transition(process.cwd(), env, values.apply))); } finally { await lock.release(); }
}
main().catch(error => { console.error(JSON.stringify({ event: 'transition_failed', code: failureCode(error) })); process.exitCode = 1; });
