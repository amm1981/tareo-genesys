import { readFile, mkdir, open, rename } from 'node:fs/promises';
import { randomUUID } from 'node:crypto';
import { join, dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { sha256, validateManifest } from '../utils/protocol.js';
import { CURRENT_PROCEDURE, CURRENT_SOURCE_ID, CURRENT_COLUMN_COUNT } from '../config/source.js';
import type { Environment } from '../config/env.js';
import { stateSchema } from './store.js';
import { z } from 'zod';

async function optional(path: string): Promise<string | null> {
  try { return await readFile(path, 'utf8'); } catch (e) { if ((e as NodeJS.ErrnoException).code === 'ENOENT') return null; throw e; }
}
export async function requireTransition(root: string, binding: string) {
  const legacy = await optional(join(root, '.automation', 'state.json'));
  if (legacy === null) return;
  const folder = join(root, '.automation', CURRENT_SOURCE_ID);
  const receipt = await optional(join(folder, 'transition.json'));
  if (!receipt) throw new Error('SOURCE_TRANSITION_REQUIRED');
  const data = z.object({ version: z.literal(1), legacy_state_sha256: z.string(), target_binding: z.string(), source_id: z.literal(CURRENT_SOURCE_ID) }).parse(JSON.parse(receipt));
  const snapshot = await readFile(join(folder, 'legacy-state.snapshot.json'), 'utf8');
  if (data.target_binding !== binding || data.legacy_state_sha256 !== sha256(legacy) || snapshot !== legacy) throw new Error('SOURCE_TRANSITION_MISMATCH');
}
export async function transition(root: string, env: Environment, apply = false) {
  const legacy = await readFile(join(root, '.automation', 'state.json'), 'utf8');
  const state = stateSchema.parse(JSON.parse(legacy));
  const known = await readFile(resolve(dirname(fileURLToPath(import.meta.url)), '../../../docs/schemas/spwconsumomesvf.v1.json'), 'utf8');
  const legacyHash = sha256(known);
  const expected = sha256(JSON.stringify([env.API_BASE_URL, env.SQL_SERVER, env.SQL_PORT, 'CASABLANCA', 'dbo.SPWConsumoMesVF', legacyHash]));
  if (state.binding !== expected) throw new Error('LEGACY_BINDING_NOT_RECOGNISED');
  if (state.pending) {
    const record = state.records[state.pending];
    if (!record || record.uuid !== state.pending || record.status !== 'empty_blocked' || record.total_esperado !== 0 || record.total_lotes !== 0) throw new Error('LEGACY_PENDING_REQUIRES_REVIEW');
    const plan = JSON.parse(await readFile(join(root, '.runs', record.uuid, 'plan.json'), 'utf8'));
    if (plan.uuid !== record.uuid || plan.anio !== record.anio || plan.mes !== record.mes || plan.total_esperado !== 0 || plan.total_lotes !== 0 || plan.checksum_total !== sha256('') || plan.schema_json !== known || plan.schema_checksum !== legacyHash) throw new Error('LEGACY_EMPTY_PLAN_INVALID');
    if ((record.checksum_total && record.checksum_total !== plan.checksum_total) || (record.schema_checksum && record.schema_checksum !== legacyHash)) throw new Error('LEGACY_EMPTY_PLAN_INVALID');
  }
  const target = await readFile(env.SQL_SCHEMA_FILE, 'utf8');
  const manifest = validateManifest(JSON.parse(target));
  if (manifest.columns.length !== CURRENT_COLUMN_COUNT || target !== JSON.stringify(manifest)) throw new Error('APPROVED_SCHEMA_INVALID');
  const binding = sha256(JSON.stringify([env.API_BASE_URL, env.SQL_SERVER, env.SQL_PORT, 'CASABLANCA', CURRENT_PROCEDURE, sha256(target)]));
  const receipt = { version: 1, source_id: CURRENT_SOURCE_ID, legacy_state_sha256: sha256(legacy), legacy_binding: state.binding,
    target_binding: binding, target_schema_checksum: sha256(target), legacy_pending: state.pending, validated_at: new Date().toISOString() };
  if (apply) {
    const folder = join(root, '.automation', CURRENT_SOURCE_ID);
    await mkdir(folder, { recursive: true, mode: 0o700 });
    const existing = await optional(join(folder, 'transition.json'));
    if (existing !== null) { await requireTransition(root, binding); return { ...receipt, apply, legacy_preserved: true }; }
    for (const [name, content] of [['legacy-state.snapshot.json', legacy], ['transition.json', JSON.stringify(receipt)]] as const) {
      const previous = await optional(join(folder, name));
      if (previous !== null) { if (previous !== content) throw new Error('SOURCE_TRANSITION_MISMATCH'); continue; }
      const temp = join(folder, `.transition-${randomUUID()}.tmp`);
      const handle = await open(temp, 'wx', 0o600);
      try { await handle.writeFile(content); await handle.sync(); } finally { await handle.close(); }
      await rename(temp, join(folder, name));
    }
    await requireTransition(root, binding);
  }
  return { ...receipt, apply, legacy_preserved: true };
}
