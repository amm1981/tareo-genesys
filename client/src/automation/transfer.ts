import { readFile } from 'node:fs/promises';
import { join } from 'node:path';
import { createHash } from 'node:crypto';
import { z } from 'zod';
import { HttpError } from '../services/api.js';
import type { RunPlan } from '../services/sync.js';
import { inPeriod, sha256, validateManifest, type Batch } from '../utils/protocol.js';
import type { Month } from './calendar.js';

export interface SyncApi { call<T>(path: string, body?: unknown): Promise<T> }
export interface Verification {
  uuid: string; anio: number; mes: number | null; estado: string; total_esperado: number; total_recibido: number;
  total_lotes: number; lotes_recibidos: number; checksum_total: string; schema_checksum: string; filas_publicadas: number; verificado: boolean;
}
export interface Publication { anio: number; mes: number; active: Verification | null; pending: Verification | null }
const planSchema = z.object({ uuid: z.uuid(), anio: z.number().int().min(1900).max(9999), mes: z.number().int().min(1).max(12),
  total_esperado: z.number().int().min(0).max(10000000), total_lotes: z.number().int().min(0).max(10000000),
  checksum_total: z.string().regex(/^[a-f0-9]{64}$/), schema_json: z.string(), schema_checksum: z.string().regex(/^[a-f0-9]{64}$/),
  origen_total: z.number().int().nonnegative(), descartados: z.number().int().nonnegative() });
export async function inspectLocal(folder: string, uuid: string, period: Month, schemaHash: string) {
  const plan = planSchema.parse(JSON.parse(await readFile(join(folder, 'plan.json'), 'utf8')));
  if (plan.uuid !== uuid || plan.anio !== period.anio || plan.mes !== period.mes || plan.schema_checksum !== schemaHash || sha256(plan.schema_json) !== schemaHash || validateManifest(JSON.parse(plan.schema_json)).columns.length !== 34 || plan.origen_total - plan.descartados !== plan.total_esperado || (plan.total_esperado === 0) !== (plan.total_lotes === 0) || plan.total_lotes > plan.total_esperado) throw new Error('LOCAL_PLAN_INVALID');
  const ledger = createHash('sha256');
  const prefix = [0];
  let count = 0;
  for (let i = 1; i <= plan.total_lotes; i++) {
    const batch = await localBatch(folder, i);
    const rows = z.array(z.object({ numero_fila: z.number().int(), datos_json: z.string() })).min(1).max(1000).parse(JSON.parse(batch.payload));
    for (const row of rows) {
      if (row.numero_fila !== ++count || !inPeriod(JSON.parse(row.datos_json).Fecha, period)) throw new Error('LOCAL_ROW_INVALID');
    }
    prefix.push(count);
    ledger.update(`${i}:${batch.checksum}\n`);
  }
  if (count !== plan.total_esperado || ledger.digest('hex') !== plan.checksum_total) throw new Error('LOCAL_LEDGER_INVALID');
  return { plan: plan as RunPlan, prefix };
}
async function localBatch(folder: string, number: number): Promise<Batch> {
  const batch = z.object({ numero_lote: z.number().int(), payload: z.string(), checksum: z.string() }).parse(JSON.parse(await readFile(join(folder, `${number}.json`), 'utf8')));
  if (batch.numero_lote !== number || Buffer.byteLength(batch.payload) > 4194304 || sha256(batch.payload) !== batch.checksum) throw new Error('LOCAL_BATCH_INVALID');
  return batch;
}
export function matches(remote: Verification, plan: RunPlan): boolean {
  return remote.uuid === plan.uuid && remote.anio === plan.anio && remote.mes === plan.mes && remote.total_esperado === plan.total_esperado && remote.total_lotes === plan.total_lotes && remote.schema_checksum === plan.schema_checksum && remote.checksum_total === plan.checksum_total;
}
export async function resumeTransfer(api: SyncApi, plan: RunPlan, folder: string, prefix: number[], progress: (received: number, batches: number) => Promise<void>) {
  const path = `/api/v1/sync/runs/${plan.uuid}`;
  let remote: Verification;
  try { remote = await api.call<Verification>(`${path}/verification`); }
  catch (e) {
    if (!(e instanceof HttpError) || e.status !== 404) throw e;
    await api.call('/api/v1/sync/runs', plan);
    remote = await api.call<Verification>(`${path}/verification`);
  }
  if (!matches(remote, plan)) throw new Error('REMOTE_PLAN_MISMATCH');
  if (remote.estado !== 'completada') {
    if (remote.estado !== 'pendiente' || !Number.isInteger(remote.lotes_recibidos) || remote.lotes_recibidos < 0 || remote.lotes_recibidos > plan.total_lotes || prefix[remote.lotes_recibidos] !== remote.total_recibido) throw new Error('REMOTE_PROGRESS_INVALID');
    for (let i = remote.lotes_recibidos + 1; i <= plan.total_lotes; i++) {
      await api.call(`${path}/batches`, await localBatch(folder, i));
      await progress(prefix[i]!, i);
    }
    await api.call(`${path}/complete`, { checksum_total: plan.checksum_total });
    remote = await api.call<Verification>(`${path}/verification`);
  }
  if (!matches(remote, plan) || remote.verificado !== true || remote.estado !== 'completada' || remote.filas_publicadas !== plan.total_esperado || remote.total_recibido !== plan.total_esperado || remote.lotes_recibidos !== plan.total_lotes) throw new Error('REMOTE_VERIFICATION_FAILED');
  return remote;
}
