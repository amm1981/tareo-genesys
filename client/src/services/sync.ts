import { mkdir, readFile, writeFile } from 'node:fs/promises';
import { createHash, randomUUID } from 'node:crypto';
import { join } from 'node:path';
import type { Period } from '../config/args.js';
import type { Environment } from '../config/env.js';
import { inPeriod, serializeRow, sha256, validateManifest, type Manifest, type WireRow, type Batch } from '../utils/protocol.js';
import { Api } from './api.js';

export interface Extractor {
  extract(period: Period, onSchema: (manifest: Manifest) => void, onRow: (row: unknown[], manifest: Manifest) => Promise<void>): Promise<Manifest>;
}
export interface RunPlan { uuid: string; anio: number; mes: number | null; total_esperado: number; total_lotes: number; checksum_total: string; schema_json: string; schema_checksum: string; origen_total: number; descartados: number }
export async function extract(source: Extractor, period: Period, env: Environment, dry: boolean, directory = '.runs') {
  const uuid = randomUUID();
  const folder = join(directory, uuid);
  if (!dry) await mkdir(folder, { recursive: true, mode: 0o700 });
  let total = 0, discarded = 0, kept = 0, number = 0;
  let rows: WireRow[] = [];
  let rowBytes = 2;
  const digest = createHash('sha256');
  const flush = async () => {
    if (!rows.length) return;
    const payload = JSON.stringify(rows);
    const batch: Batch = { numero_lote: ++number, payload, checksum: sha256(payload) };
    digest.update(`${number}:${batch.checksum}\n`);
    await writeFile(join(folder, `${number}.json`), JSON.stringify(batch), { flag: 'wx', mode: 0o600 });
    rows = [];
    rowBytes = 2;
  };
  const manifest = await source.extract(period, () => {}, async (row, schema) => {
    total++;
    const json = serializeRow(row, schema);
    const fecha = row[schema.columns.findIndex(c => c.name === 'Fecha')];
    if (!inPeriod(fecha, period)) { discarded++; return; }
    kept++;
    if (!dry) {
      const item = { numero_fila: kept, datos_json: json };
      const itemBytes = Buffer.byteLength(JSON.stringify(item));
      if (itemBytes + 2 > env.MAX_BATCH_BYTES) throw new Error('Una fila excede el límite del lote.');
      if (rows.length && (rows.length >= env.BATCH_SIZE || rowBytes + itemBytes + 1 > env.MAX_BATCH_BYTES)) await flush();
      rowBytes += itemBytes + (rows.length ? 1 : 0);
      rows.push(item);
    }
  });
  if (!dry) await flush();
  const schema_json = JSON.stringify(manifest);
  const plan: RunPlan = { uuid, anio: period.anio, mes: period.mes ?? null, total_esperado: kept, total_lotes: number, checksum_total: digest.digest('hex'), schema_json, schema_checksum: sha256(schema_json), origen_total: total, descartados: discarded };
  if (!dry) {
    const approved = validateManifest(JSON.parse(await readFile(env.SQL_SCHEMA_FILE, 'utf8')));
    if (JSON.stringify(approved) !== schema_json) throw new Error('El esquema real no coincide con el esquema revisado. Ejecute inspect:schema y revise las migraciones.');
    await writeFile(join(folder, 'plan.json'), JSON.stringify(plan), { flag: 'wx', mode: 0o600 });
  }
  return { plan, manifest, folder };
}
export async function upload(api: Api, plan: RunPlan, folder: string) {
  await api.call('/api/v1/sync/runs', plan);
  for (let i = 1; i <= plan.total_lotes; i++) {
    const batch = JSON.parse(await readFile(join(folder, `${i}.json`), 'utf8')) as Batch;
    if (batch.numero_lote !== i || sha256(batch.payload) !== batch.checksum) throw new Error('Lote local alterado; se detiene el envío.');
    await api.call(`/api/v1/sync/runs/${plan.uuid}/batches`, batch);
  }
  const result = await api.call<{ estado: string; total_recibido: number }>(`/api/v1/sync/runs/${plan.uuid}/complete`, { checksum_total: plan.checksum_total });
  if (result.estado !== 'completada' || result.total_recibido !== plan.total_esperado) throw new Error('No se confirmó la recepción completa.');
  return result;
}
