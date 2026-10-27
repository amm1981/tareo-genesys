import { lstat, readdir, readFile, unlink, rmdir } from 'node:fs/promises';
import { join } from 'node:path';
import { CURRENT_SOURCE_ID } from '../config/source.js';
import type { State } from './store.js';
import { Store } from './store.js';
import { folderBytes } from './disk.js';
import { inspectLocal, matches, type SyncApi, type Verification } from './transfer.js';

export async function retainLocal(root: string, state: State, store: Store, now: Date, days: number, apply: boolean, api?: SyncApi) {
  if (!Number.isInteger(days) || days < 7 || days > 3650) throw new Error('INVALID_RETENTION_DAYS');
  const report: Array<{ uuid: string; anio: number; mes: number; bytes: number }> = [];
  for (const record of Object.values(state.records)) {
    if (record.status !== 'verified' || record.uuid === state.pending || record.spool_removed_at || Date.parse(record.updated_at) > now.getTime() - days * 86400000) continue;
    const folder = join(root, '.runs', CURRENT_SOURCE_ID, record.uuid);
    let stat;
    try { stat = await lstat(folder); } catch (e) { if ((e as NodeJS.ErrnoException).code === 'ENOENT') continue; throw e; }
    if (stat.isSymbolicLink() || !stat.isDirectory()) throw new Error('RETENTION_PATH_INVALID');
    const { plan } = await inspectLocal(folder, record.uuid, record, record.schema_checksum ?? '');
    if (plan.checksum_total !== record.checksum_total || plan.total_esperado !== record.total_esperado || plan.total_lotes !== record.total_lotes) throw new Error('RETENTION_RECEIPT_INVALID');
    const names = ['plan.json', ...Array.from({ length: plan.total_lotes }, (_, i) => `${i + 1}.json`)];
    const actual = await readdir(folder);
    if (actual.length !== names.length || actual.some(name => !names.includes(name))) throw new Error('RETENTION_UNKNOWN_FILES');
    for (const name of names) {
      const item = await lstat(join(folder, name));
      if (item.isSymbolicLink() || !item.isFile()) throw new Error('RETENTION_PATH_INVALID');
    }
    report.push({ uuid: record.uuid, anio: record.anio, mes: record.mes, bytes: await folderBytes(folder) });
    if (!apply) continue;
    if (!api) throw new Error('RETENTION_REQUIRES_REMOTE_VERIFICATION');
    const remote = await api.call<Verification>(`/api/v1/sync/runs/${record.uuid}/verification`);
    if (!matches(remote, plan) || remote.verificado !== true || remote.filas_publicadas !== plan.total_esperado) throw new Error('RETENTION_REMOTE_NOT_VERIFIED');
    for (const name of names.filter(name => name !== 'plan.json')) await unlink(join(folder, name));
    await unlink(join(folder, 'plan.json'));
    await rmdir(folder);
    record.spool_removed_at = now.toISOString();
    await store.save(state);
    await store.event({ event: 'local_spool_removed', uuid: record.uuid, anio: record.anio, mes: record.mes }, now);
  }
  return report;
}
