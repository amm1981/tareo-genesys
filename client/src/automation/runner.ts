import { readFile } from 'node:fs/promises';
import { join } from 'node:path';
import { randomUUID } from 'node:crypto';
import { ZodError } from 'zod';
import type { Environment } from '../config/env.js';
import { extract, type Extractor } from '../services/sync.js';
import { sha256, validateManifest } from '../utils/protocol.js';
import { HttpError } from '../services/api.js';
import { CURRENT_PROCEDURE, CURRENT_SOURCE_ID, CURRENT_COLUMN_COUNT } from '../config/source.js';
import { schedule, key } from './calendar.js';
import { Store, type RunRecord } from './store.js';
import { inspectLocal, resumeTransfer, type SyncApi, type Publication } from './transfer.js';

export interface AutoSource extends Extractor { connect(): Promise<void>; close(): Promise<void> }
export interface AutoOptions {
  root: string; env: Environment; api: SyncApi; source: () => AutoSource; mode: 'backfill' | 'daily';
  space: (bytes?: number) => Promise<void>; now?: () => Date; maxPeriods?: number;
  retentionDays?: number;
  output?: (event: object) => void;
  rebuildPeriods?: string[];
}
export function failureCode(error: unknown): string {
  if (error instanceof ZodError) return 'CONFIG_OR_CHECKPOINT_INVALID';
  if (error instanceof SyntaxError) return 'CHECKPOINT_OR_PROTOCOL_JSON_INVALID';
  if (error instanceof HttpError) return error.status === 401 ? 'SYNC_TOKEN_RENEWAL_REQUIRED' : `HTTP_${error.status}`;
  const allowed = new Set([
    'ENOENT', 'ENOSPC', 'EACCES', 'EPERM', 'EIO', 'EBUSY', 'ECONNRESET', 'ETIMEDOUT', 'ECONNREFUSED', 'ENETUNREACH', 'EHOSTUNREACH', 'ENOTFOUND', 'ESOCKET', 'ETIMEOUT', 'ELOGIN',
    'APPROVED_SCHEMA_INVALID', 'SOURCE_NOT_ALLOWED', 'SYNC_CREDENTIAL_UNAVAILABLE', 'INVALID_MODE', 'AUTOMATION_DESTINATION_NOT_ALLOWED',
    'INSUFFICIENT_FREE_SPACE', 'SPOOL_LIMIT_REACHED', 'SPOOL_LINK_NOT_ALLOWED', 'SYNC_ALREADY_RUNNING_OR_LOCK_PORT_BUSY',
    'STATE_DESTINATION_OR_SCHEMA_MISMATCH', 'INVALID_STATE', 'INVALID_PENDING_STATE', 'INVALID_EMPTY_APPROVAL_FILE',
    'LOCAL_PLAN_INVALID', 'LOCAL_ROW_INVALID', 'LOCAL_LEDGER_INVALID', 'LOCAL_BATCH_INVALID',
    'REMOTE_PLAN_MISMATCH', 'REMOTE_PROGRESS_INVALID', 'REMOTE_VERIFICATION_FAILED', 'REMOTE_PERIOD_MISMATCH',
    'UNTRACKED_REMOTE_PENDING_RUN', 'ACTIVE_PUBLICATION_NOT_VERIFIED', 'EMPTY_RESULT_REQUIRES_UUID_APPROVAL',
    'INVALID_RETENTION_DAYS', 'RETENTION_PATH_INVALID', 'RETENTION_RECEIPT_INVALID', 'RETENTION_UNKNOWN_FILES', 'RETENTION_REQUIRES_REMOTE_VERIFICATION', 'RETENTION_REMOTE_NOT_VERIFIED',
    'SOURCE_TRANSITION_REQUIRED', 'SOURCE_TRANSITION_MISMATCH', 'LEGACY_BINDING_NOT_RECOGNISED', 'LEGACY_PENDING_REQUIRES_REVIEW', 'LEGACY_EMPTY_PLAN_INVALID', 'LEGACY_UUID_RESERVED', 'CURRENT_SCHEMA_COLUMN_MISMATCH', 'REBUILD_APPROVAL_INVALID',
  ]);
  const code = (error as NodeJS.ErrnoException)?.code;
  if (code && allowed.has(code)) return code;
  const message = error instanceof Error ? error.message : '';
  return allowed.has(message) ? message : 'AUTOMATION_FAILED';
}
export async function runAutomatic(options: AutoOptions) {
  const { root, env, api, space } = options;
  const now = options.now ?? (() => new Date());
  const schemaText = await readFile(env.SQL_SCHEMA_FILE, 'utf8');
  const schema = validateManifest(JSON.parse(schemaText));
  if (schema.columns.length !== CURRENT_COLUMN_COUNT || JSON.stringify(schema) !== schemaText) throw new Error('APPROVED_SCHEMA_INVALID');
  if (env.SQL_SERVER !== '192.168.136.3' || env.SQL_PORT !== 1433 || env.SQL_DATABASE !== 'CASABLANCA') throw new Error('SOURCE_NOT_ALLOWED');
  const schemaHash = sha256(schemaText);
  const binding = sha256(JSON.stringify([env.API_BASE_URL, env.SQL_SERVER, env.SQL_PORT, 'CASABLANCA', CURRENT_PROCEDURE, schemaHash]));
  const store = new Store(root, binding);
  const state = await store.load();
  const event = async (item: Parameters<Store['event']>[0]) => { options.output?.(await store.event(item, now())); };
  const persist = async (record: RunRecord) => { record.updated_at = now().toISOString(); state.records[record.uuid] = record; await store.save(state); };
  let finished = 0;
  async function finish(record: RunRecord) {
    const folder = join(root, '.runs', CURRENT_SOURCE_ID, record.uuid);
    const { plan, prefix } = await inspectLocal(folder, record.uuid, record, schemaHash);
    record.total_esperado = plan.total_esperado; record.total_lotes = plan.total_lotes;
    record.checksum_total = plan.checksum_total; record.schema_checksum = plan.schema_checksum;
    if (plan.total_esperado === 0 && !await store.emptyApproved(record.uuid)) {
      record.status = 'empty_blocked'; await persist(record);
      throw new Error('EMPTY_RESULT_REQUIRES_UUID_APPROVAL');
    }
    record.status = 'uploading'; await persist(record);
    await resumeTransfer(api, plan, folder, prefix, async (received, batches) => {
      await event({ event: 'batch_received', uuid: record.uuid, anio: record.anio, mes: record.mes, total_recibido: received, lotes_recibidos: batches });
    });
    record.status = 'verified'; state.pending = null;
    const previous = state.daily[key(record)];
    if (record.mode === 'daily' && (!previous || previous.day <= record.cycle)) state.daily[key(record)] = { day: record.cycle, uuid: record.uuid };
    await persist(record); finished++;
    await event({ event: 'run_verified', ...record });
  }
  try {
    await space();
    if (state.pending) {
      const record = state.records[state.pending]!;
      if (record.status === 'extracting') {
        let planExists = false;
        try { await readFile(join(root, '.runs', CURRENT_SOURCE_ID, record.uuid, 'plan.json'), 'utf8'); planExists = true; }
        catch (e) { if ((e as NodeJS.ErrnoException).code !== 'ENOENT') throw e; }
        if (!planExists) {
          record.status = 'abandoned'; state.pending = null; await persist(record);
          await event({ event: 'extraction_interrupted', uuid: record.uuid, anio: record.anio, mes: record.mes, status: record.status });
        } else await finish(record);
      } else if (record.status === 'verified' || record.status === 'abandoned') throw new Error('INVALID_PENDING_STATE');
      else await finish(record);
    }
    const calendar = schedule(options.mode, now());
    for (const period of calendar.periods) {
      if (options.rebuildPeriods && !options.rebuildPeriods.includes(key(period))) continue;
      if (finished >= (options.maxPeriods ?? Number.MAX_SAFE_INTEGER)) break;
      await space();
      const remote = await api.call<Publication>(`/api/v1/sync/publication?anio=${period.anio}&mes=${period.mes}`);
      if (remote.anio !== period.anio || remote.mes !== period.mes) throw new Error('REMOTE_PERIOD_MISMATCH');
      if (remote.pending) throw new Error('UNTRACKED_REMOTE_PENDING_RUN');
      const legacy = remote.active?.source?.classification === 'legacy';
      if (remote.active && remote.active.verificado !== true) throw new Error('ACTIVE_PUBLICATION_NOT_VERIFIED');
      if (legacy && !options.rebuildPeriods?.includes(key(period))) { await event({ event: 'legacy_period_preserved', uuid: remote.active!.uuid, ...period }); continue; }
      if (remote.active && !legacy && remote.active.schema_checksum !== schemaHash) throw new Error('ACTIVE_PUBLICATION_NOT_VERIFIED');
      if (remote.active && !legacy && (options.mode === 'backfill' || state.daily[key(period)]?.day === calendar.day)) {
        await event({ event: 'period_skipped_verified', uuid: remote.active.uuid, ...period }); continue;
      }
      const record: RunRecord = { uuid: randomUUID(), ...period, mode: options.mode, cycle: calendar.day, status: 'extracting', updated_at: now().toISOString() };
      state.pending = record.uuid; await persist(record);
      await event({ event: 'extraction_started', uuid: record.uuid, ...period, status: record.status });
      const source = options.source();
      try {
        await source.connect();
        await extract(source, period, env, false, join(root, '.runs', CURRENT_SOURCE_ID), { uuid: record.uuid, beforeWrite: space });
      } finally { await source.close(); }
      record.status = 'prepared'; await persist(record);
      await finish(record);
    }
    await event({ event: 'cycle_completed', status: options.mode, count: finished });
    await event({ event: 'retention_review', count: Object.values(state.records).filter(r => r.status === 'verified' && !r.spool_removed_at && Date.parse(r.updated_at) < now().getTime() - (options.retentionDays ?? 14) * 86400000).length });
    return state;
  } catch (error) {
    const pending = state.pending ? state.records[state.pending] : undefined;
    await event({ event: 'cycle_failed', ...(pending ? { uuid: pending.uuid, anio: pending.anio, mes: pending.mes, status: pending.status } : {}), code: failureCode(error) });
    throw error;
  }
}
