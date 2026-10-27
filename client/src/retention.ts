import dotenv from 'dotenv';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { readFile } from 'node:fs/promises';
import { parseArgs } from 'node:util';
import { environmentSchema } from './config/env.js';
import { sha256 } from './utils/protocol.js';
import { Store } from './automation/store.js';
import { retainLocal } from './automation/retention.js';
import { failureCode } from './automation/runner.js';
import { CURRENT_PROCEDURE, CURRENT_SCHEMA_FILE } from './config/source.js';
import { Api } from './services/api.js';
import { acquireLock } from './services/lock.js';

process.chdir(resolve(dirname(fileURLToPath(import.meta.url)), '..'));
dotenv.config({ quiet: true });
async function main() {
  const { values } = parseArgs({ options: { apply: { type: 'boolean', default: false } }, strict: true });
  const env = environmentSchema.parse({ ...process.env, API_BASE_URL: process.env.AUTO_API_BASE_URL ?? 'https://api.labour.agrocalera.app', SQL_SCHEMA_FILE: CURRENT_SCHEMA_FILE, API_TOKEN: '', API_TOKEN_FILE: resolve(process.env.AUTO_TOKEN_FILE ?? '.secrets/vps-sync-token') });
  if (env.API_BASE_URL !== 'https://api.labour.agrocalera.app') throw new Error('AUTOMATION_DESTINATION_NOT_ALLOWED');
  const schemaHash = sha256(await readFile(env.SQL_SCHEMA_FILE, 'utf8'));
  const binding = sha256(JSON.stringify([env.API_BASE_URL, env.SQL_SERVER, env.SQL_PORT, 'CASABLANCA', CURRENT_PROCEDURE, schemaHash]));
  const store = new Store(process.cwd(), binding);
  const lock = await acquireLock(env.SYNC_LOCK_PORT);
  try {
    const state = await store.load();
    const report = await retainLocal(process.cwd(), state, store, new Date(), Number(process.env.AUTO_LOCAL_RETENTION_DAYS ?? 14), values.apply, new Api(env));
    console.log(JSON.stringify({ apply: values.apply, eligible: report }));
  } finally { await lock.release(); }
}
main().catch(error => { console.error(JSON.stringify({ event: 'retention_failed', code: failureCode(error) })); process.exitCode = 1; });
