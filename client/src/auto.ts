import dotenv from 'dotenv';
import { parseArgs } from 'node:util';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { z } from 'zod';
import { environmentSchema } from './config/env.js';
import { Api } from './services/api.js';
import { Source } from './database/source.js';
import { acquireLock } from './services/lock.js';
import { schedule } from './automation/calendar.js';
import { diskGuard } from './automation/disk.js';
import { failureCode, runAutomatic } from './automation/runner.js';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
process.chdir(root);
dotenv.config({ quiet: true });
async function main() {
  const { positionals, values } = parseArgs({ allowPositionals: true, options: { plan: { type: 'boolean', default: false }, 'max-periods': { type: 'string' } }, strict: true });
  if (positionals.length !== 1) throw new Error('INVALID_MODE');
  const mode = z.enum(['backfill', 'daily']).parse(positionals[0]);
  const maxPeriods = values['max-periods'] ? z.coerce.number().int().min(1).max(1200).parse(values['max-periods']) : undefined;
  const env = environmentSchema.parse({ ...process.env, API_BASE_URL: process.env.AUTO_API_BASE_URL ?? 'https://api.labour.agrocalera.app', API_TOKEN: '', API_TOKEN_FILE: resolve(process.env.AUTO_TOKEN_FILE ?? '.secrets/vps-sync-token') });
  if (env.API_BASE_URL !== 'https://api.labour.agrocalera.app') throw new Error('AUTOMATION_DESTINATION_NOT_ALLOWED');
  if (values.plan) { console.log(JSON.stringify({ mode, timezone: 'America/Lima', ...schedule(mode), max_periods: maxPeriods ?? null, destination: env.API_BASE_URL, execution: false })); return; }
  const min = z.coerce.number().int().min(64).max(1048576).parse(process.env.AUTO_MIN_FREE_MB ?? 1024);
  const max = z.coerce.number().int().min(64).max(1048576).parse(process.env.AUTO_MAX_SPOOL_MB ?? 10240);
  const retentionDays = z.coerce.number().int().min(7).max(3650).parse(process.env.AUTO_LOCAL_RETENTION_DAYS ?? 14);
  const lock = await acquireLock(env.SYNC_LOCK_PORT);
  try {
    const space = await diskGuard(root, min * 1048576, max * 1048576);
    await runAutomatic({ root, mode, env, api: new Api(env), source: () => new Source(env), space, maxPeriods, retentionDays, output: item => console.log(JSON.stringify(item)) });
  } finally { await lock.release(); }
}
main().catch(error => { console.error(JSON.stringify({ event: 'automation_stopped', code: failureCode(error) })); process.exitCode = 1; });
