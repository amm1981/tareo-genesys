import { mkdir, open, readFile, rename, appendFile } from 'node:fs/promises';
import { join } from 'node:path';
import { randomUUID } from 'node:crypto';
import { z } from 'zod';

const recordSchema = z.object({
  uuid: z.uuid(), anio: z.number().int().min(1900).max(9999), mes: z.number().int().min(1).max(12),
  mode: z.enum(['backfill', 'daily']), cycle: z.string().regex(/^\d{4}-\d{2}-\d{2}$/),
  status: z.enum(['extracting', 'prepared', 'uploading', 'empty_blocked', 'verified', 'abandoned']),
  updated_at: z.iso.datetime(), total_esperado: z.number().int().nonnegative().optional(), total_lotes: z.number().int().nonnegative().optional(),
  checksum_total: z.string().regex(/^[a-f0-9]{64}$/).optional(), schema_checksum: z.string().regex(/^[a-f0-9]{64}$/).optional(),
  spool_removed_at: z.iso.datetime().optional(),
});
const stateSchema = z.object({ version: z.literal(1), binding: z.string().regex(/^[a-f0-9]{64}$/), pending: z.uuid().nullable(),
  records: z.record(z.uuid(), recordSchema), daily: z.record(z.string().regex(/^\d{4}-(0[1-9]|1[0-2])$/), z.object({ day: z.string().regex(/^\d{4}-\d{2}-\d{2}$/), uuid: z.uuid() })) });
export type RunRecord = z.infer<typeof recordSchema>;
export type State = z.infer<typeof stateSchema>;

export class Store {
  readonly folder: string;
  constructor(root: string, private binding: string) { this.folder = join(root, '.automation'); }
  async load(): Promise<State> {
    await mkdir(this.folder, { recursive: true, mode: 0o700 });
    let text: string;
    try { text = await readFile(join(this.folder, 'state.json'), 'utf8'); }
    catch (e) { if ((e as NodeJS.ErrnoException).code === 'ENOENT') return { version: 1, binding: this.binding, pending: null, records: {}, daily: {} }; throw e; }
    const state = stateSchema.parse(JSON.parse(text));
    if (state.binding !== this.binding || (state.pending && !state.records[state.pending])) throw new Error('STATE_DESTINATION_OR_SCHEMA_MISMATCH');
    for (const [uuid, record] of Object.entries(state.records)) if (record.uuid !== uuid) throw new Error('INVALID_STATE');
    return state;
  }
  async save(state: State) {
    stateSchema.parse(state);
    const temp = join(this.folder, `.state-${randomUUID()}.tmp`);
    const handle = await open(temp, 'wx', 0o600);
    try { await handle.writeFile(JSON.stringify(state)); await handle.sync(); } finally { await handle.close(); }
    await rename(temp, join(this.folder, 'state.json'));
  }
  async event(record: { event: string; uuid?: string; anio?: number; mes?: number; total_esperado?: number; total_lotes?: number; total_recibido?: number; lotes_recibidos?: number; count?: number; status?: string; code?: string }, now = new Date()) {
    const entry = { at: now.toISOString(), ...record };
    await appendFile(join(this.folder, `events-${entry.at.slice(0, 7)}.jsonl`), JSON.stringify(entry) + '\n', { mode: 0o600 });
    return entry;
  }
  async emptyApproved(uuid: string): Promise<boolean> {
    try {
      const input = z.object({ uuids: z.array(z.uuid()) }).parse(JSON.parse(await readFile(join(this.folder, 'empty-approvals.json'), 'utf8')));
      return input.uuids.includes(uuid);
    } catch (e) { if ((e as NodeJS.ErrnoException).code === 'ENOENT') return false; throw new Error('INVALID_EMPTY_APPROVAL_FILE'); }
  }
}
