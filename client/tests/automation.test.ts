import { afterEach, describe, expect, it, vi } from 'vitest';
import { mkdtemp, readFile, writeFile, rm, readdir } from 'node:fs/promises';
import { resolve, join, sep } from 'node:path';
import { randomUUID } from 'node:crypto';
import { createServer } from 'node:http';
import { environmentSchema } from '../src/config/env.js';
import { Api, HttpError } from '../src/services/api.js';
import { acquireLock } from '../src/services/lock.js';
import { schedule, limaDay } from '../src/automation/calendar.js';
import { runAutomatic } from '../src/automation/runner.js';
import { diskGuard } from '../src/automation/disk.js';
import { Store, type State } from '../src/automation/store.js';
import { retainLocal } from '../src/automation/retention.js';
import { type SyncApi, type Verification } from '../src/automation/transfer.js';
import { validateManifest, sha256 } from '../src/utils/protocol.js';
import type { RunPlan } from '../src/services/sync.js';

const roots: string[] = [];
afterEach(async () => {
  for (const root of roots.splice(0)) {
    if (!resolve(root).startsWith(resolve('tests') + sep)) throw new Error('Unsafe test cleanup');
    await rm(root, { recursive: true, force: true });
  }
});
class Backend implements SyncApi {
  runs = new Map<string, { plan: RunPlan; received: number; batches: number; complete: boolean }>();
  active = new Map<string, string>();
  calls: string[] = [];
  sent: number[] = [];
  loseBatch = false;
  loseComplete = false;
  invalidActive = false;
  untracked = false;
  receipt(uuid: string): Verification {
    const run = this.runs.get(uuid);
    if (!run) throw new HttpError(404);
    return { ...run.plan, estado: run.complete ? 'completada' : 'pendiente', total_recibido: run.received,
      lotes_recibidos: run.batches, filas_publicadas: run.complete ? run.received : 0, verificado: run.complete };
  }
  async call<T>(path: string, body?: unknown): Promise<T> {
    this.calls.push(path);
    const url = new URL(path, 'https://fixture.invalid');
    let result: unknown;
    if (url.pathname.endsWith('/publication')) {
      const anio = Number(url.searchParams.get('anio')), mes = Number(url.searchParams.get('mes'));
      const id = this.active.get(`${anio}-${mes}`);
      result = { anio, mes, active: id ? { ...this.receipt(id), verificado: !this.invalidActive } : null,
        pending: this.untracked ? { uuid: randomUUID() } : null };
    } else if (path === '/api/v1/sync/runs') {
      const plan = body as RunPlan;
      if (!this.runs.has(plan.uuid)) this.runs.set(plan.uuid, { plan, received: 0, batches: 0, complete: false });
      result = this.receipt(plan.uuid);
    } else {
      const parts = url.pathname.split('/');
      const uuid = parts[5]!;
      const run = this.runs.get(uuid);
      if (!run) throw new HttpError(404);
      if (path.endsWith('/batches')) {
        const batch = body as { numero_lote: number; payload: string };
        this.sent.push(batch.numero_lote);
        if (batch.numero_lote !== run.batches + 1) throw new HttpError(409);
        run.batches++; run.received += JSON.parse(batch.payload).length;
        if (this.loseBatch) { this.loseBatch = false; throw new HttpError(503); }
      } else if (path.endsWith('/complete')) {
        run.complete = true; this.active.set(`${run.plan.anio}-${run.plan.mes}`, uuid);
        if (this.loseComplete) { this.loseComplete = false; throw new HttpError(503); }
      }
      result = this.receipt(uuid);
    }
    return structuredClone(result) as T;
  }
}
async function harness() {
  const root = await mkdtemp(resolve('tests/.auto-')); roots.push(root);
  const text = await readFile('../docs/source-schema.json', 'utf8');
  const schema = validateManifest(JSON.parse(text));
  const schemaFile = join(root, 'schema.json'); await writeFile(schemaFile, text);
  const env = environmentSchema.parse({ SQL_SCHEMA_FILE: schemaFile, BATCH_SIZE: 1, API_TOKEN: 'fixture', HTTP_RETRIES: 0 });
  const backend = new Backend();
  let empty = false;
  let failConnect = false;
  const connections = vi.fn();
  const source = vi.fn(() => ({
    connect: async () => { connections(); if (failConnect) throw new Error('SENSITIVE_FIXTURE_PASSWORD_TOKEN'); },
    close: vi.fn(async () => {}),
    extract: async (period: { anio: number; mes?: number }, onSchema: (m: typeof schema) => void, onRow: (r: unknown[], m: typeof schema) => Promise<void>) => {
      onSchema(schema);
      if (empty) return schema;
      const row = schema.columns.map(c => c.name === 'Fecha' ? `${period.anio}-${String(period.mes).padStart(2,'0')}-01T12:00:00.1234567`
        : c.type === 'Money' ? '123456789012345.6789' : c.type === 'Numeric' ? '999999999999999999'
        : c.type === 'Int' ? 1 : c.type === 'Float' ? 0.30000000000000004 : c.nullable ? null : 'Fixture');
      await onRow(row, schema); await onRow(row, schema);
      const outside = [...row]; outside[schema.columns.findIndex(c => c.name === 'Fecha')] = `${period.anio + 1}-01-01T00:00:00`;
      await onRow(outside, schema);
      return schema;
    },
  }));
  const events: object[] = [];
  const base = { root, env, api: backend, source, mode: 'backfill' as const, now: () => new Date('2023-01-15T07:00:00Z'), space: vi.fn(async (_n?: number) => {}), output: (e: object) => events.push(e) };
  const state = async () => JSON.parse(await readFile(join(root, '.automation/state.json'), 'utf8')) as State;
  return { ...base, base, backend, state, connections, events, setEmpty: () => { empty = true; }, failConnect: (value: boolean) => { failConnect = value; } };
}

describe('calendario America/Lima', () => {
  it('backfill incluye enero 2023 y termina en el mes local actual', () => {
    const plan = schedule('backfill', new Date('2026-10-25T07:00:00Z'));
    expect(plan.periods).toHaveLength(46); expect(plan.periods[0]).toEqual({ anio: 2023, mes: 1 }); expect(plan.periods.at(-1)).toEqual({ anio: 2026, mes: 10 });
  });
  it('no toma el cambio de fecha UTC como cambio de mes en Lima', () => {
    expect(limaDay(new Date('2026-01-01T04:59:59Z'))).toBe('2025-12-31');
    expect(schedule('backfill', new Date('2026-01-01T04:59:59Z')).periods.at(-1)).toEqual({ anio: 2025, mes: 12 });
  });
  it.each([
    ['2026-01-01T07:00:00Z', '2025-12-02', [12, 1]],
    ['2024-03-01T07:00:00Z', '2024-01-31', [1, 2, 3]],
    ['2025-03-01T07:00:00Z', '2025-01-30', [1, 2, 3]],
    ['2026-05-31T07:00:00Z', '2026-05-01', [5]],
    ['2026-06-30T07:00:00Z', '2026-05-31', [5, 6]],
  ])('ventana movil de 30 dias: %s', (date, first, months) => {
    const plan = schedule('daily', new Date(date));
    expect(plan.window_start).toBe(first); expect(plan.periods.map(p => p.mes)).toEqual(months);
    expect(Date.parse(plan.window_end_at) - Date.parse(plan.window_start_at)).toBe(30 * 86400000);
  });
});
describe('automatizacion con spool y checkpoints reales', () => {
  it('filtra años posteriores, conserva precision y omite backfill ya verificado', async () => {
    const h = await harness();
    const state = await runAutomatic(h.base);
    expect(h.connections).toHaveBeenCalledTimes(1);
    const record = Object.values(state.records)[0]!;
    expect(record).toMatchObject({ status: 'verified', total_esperado: 2, total_lotes: 2 });
    const batch = JSON.parse(await readFile(join(h.root, '.runs', record.uuid, '1.json'), 'utf8'));
    expect(JSON.parse(JSON.parse(batch.payload)[0].datos_json).Cantidad).toBe('123456789012345.6789');
    await runAutomatic(h.base);
    expect(h.connections).toHaveBeenCalledTimes(1); expect(h.backend.runs.size).toBe(1);
  });
  it('reanuda desde el lote confirmado aunque su respuesta se haya perdido', async () => {
    const h = await harness(); h.backend.loseBatch = true;
    await expect(runAutomatic(h.base)).rejects.toThrow('503');
    const interrupted = await h.state(); const uuid = interrupted.pending!;
    expect(h.backend.receipt(uuid).lotes_recibidos).toBe(1);
    await runAutomatic(h.base);
    expect(h.backend.sent).toEqual([1, 2]); expect(h.connections).toHaveBeenCalledTimes(1);
    expect((await h.state()).records[uuid]!.status).toBe('verified');
  });
  it('reconoce una finalizacion remota antes del checkpoint sin duplicar publicacion', async () => {
    const h = await harness(); h.backend.loseComplete = true;
    await expect(runAutomatic(h.base)).rejects.toThrow('503');
    await runAutomatic(h.base);
    expect(h.backend.calls.filter(p => p.endsWith('/complete'))).toHaveLength(1);
    expect(h.backend.runs.size).toBe(1); expect(h.connections).toHaveBeenCalledTimes(1);
  });
  it('recupera el plan durable aunque el estado todavia diga extracting', async () => {
    const h = await harness(); const state = await runAutomatic(h.base);
    const record = Object.values(state.records)[0]!; record.status = 'extracting'; state.pending = record.uuid;
    await writeFile(join(h.root, '.automation/state.json'), JSON.stringify(state));
    await runAutomatic(h.base);
    expect(h.connections).toHaveBeenCalledTimes(1);
    expect(h.backend.calls.filter(path => path.endsWith('/complete'))).toHaveLength(1);
  });
  it('DAILY no duplica la version si se pierde la respuesta de complete', async () => {
    const h = await harness(); h.backend.loseComplete = true;
    const options = { ...h.base, mode: 'daily' as const, now: () => new Date('2026-05-31T07:00:00Z') };
    await expect(runAutomatic(options)).rejects.toThrow('503');
    await runAutomatic(options);
    expect(h.backend.calls.filter(path => path.endsWith('/complete'))).toHaveLength(1);
    expect(h.connections).toHaveBeenCalledTimes(1);
  });
  it('no publica vacios sin una aprobacion especifica de UUID', async () => {
    const h = await harness(); h.setEmpty();
    await expect(runAutomatic(h.base)).rejects.toThrow('EMPTY_RESULT');
    expect(h.backend.runs.size).toBe(0);
    const state = await h.state(); const uuid = state.pending!;
    await expect(runAutomatic(h.base)).rejects.toThrow('EMPTY_RESULT');
    await writeFile(join(h.root, '.automation/empty-approvals.json'), JSON.stringify({ uuids: [uuid] }));
    await runAutomatic(h.base);
    expect(h.backend.receipt(uuid)).toMatchObject({ total_esperado: 0, verificado: true });
  });
  it('un vacio inesperado conserva la publicacion anterior', async () => {
    const h = await harness(); await runAutomatic(h.base);
    const previous = h.backend.active.get('2023-1'); h.setEmpty();
    await expect(runAutomatic({ ...h.base, mode: 'daily', now: () => new Date('2023-01-31T07:00:00Z') })).rejects.toThrow('EMPTY_RESULT');
    expect(h.backend.active.get('2023-1')).toBe(previous);
    expect(h.backend.runs.size).toBe(1);
  });
  it('abandona solo la extraccion inconclusa y no registra errores sensibles', async () => {
    const h = await harness(); h.failConnect(true);
    await expect(runAutomatic(h.base)).rejects.toThrow('SENSITIVE_FIXTURE_PASSWORD_TOKEN');
    const uuid = (await h.state()).pending!;
    h.failConnect(false); await runAutomatic(h.base);
    expect((await h.state()).records[uuid]!.status).toBe('abandoned');
    const logs = (await readdir(join(h.root, '.automation'))).filter(x => x.endsWith('.jsonl'));
    for (const file of logs) expect(await readFile(join(h.root, '.automation', file), 'utf8')).not.toContain('SENSITIVE_FIXTURE_PASSWORD_TOKEN');
  });
  it('DAILY repite meses completos una vez por fecha local y conserva su historial', async () => {
    const h = await harness();
    const options = { ...h.base, mode: 'daily' as const, now: () => new Date('2026-10-25T07:00:00Z') };
    await runAutomatic(options); await runAutomatic(options);
    expect(h.connections).toHaveBeenCalledTimes(2);
    await runAutomatic({ ...options, now: () => new Date('2026-10-26T07:00:00Z') });
    expect(h.connections).toHaveBeenCalledTimes(4); expect(h.backend.runs.size).toBe(4);
  });
  it('no permite cambiar destino/esquema en un estado existente ni continuar un plan alterado', async () => {
    const h = await harness(); h.backend.loseBatch = true;
    await expect(runAutomatic(h.base)).rejects.toThrow();
    await expect(runAutomatic({ ...h.base, env: { ...h.env, API_BASE_URL: 'https://other.invalid' } })).rejects.toThrow('MISMATCH');
    const id = (await h.state()).pending!;
    await writeFile(join(h.root, '.runs', id, '2.json'), '{}');
    await expect(runAutomatic(h.base)).rejects.toThrow();
    expect(h.backend.receipt(id).lotes_recibidos).toBe(1);
  });
  it('detiene el origen ante falta de disco o una publicacion no verificada', async () => {
    const h = await harness();
    await expect(runAutomatic({ ...h.base, space: async () => { throw new Error('INSUFFICIENT_FREE_SPACE'); } })).rejects.toThrow('SPACE');
    expect(h.source).not.toHaveBeenCalled();
    await runAutomatic(h.base); h.backend.invalidActive = true;
    await expect(runAutomatic(h.base)).rejects.toThrow('NOT_VERIFIED');
    expect(h.connections).toHaveBeenCalledTimes(1);
  });
  it('detiene ejecuciones remotas pendientes desconocidas antes de extraer', async () => {
    const h = await harness(); h.backend.untracked = true;
    await expect(runAutomatic(h.base)).rejects.toThrow('UNTRACKED'); expect(h.source).not.toHaveBeenCalled();
  });
  it('retencion solo lista por defecto y elimina spools verificados con autorizacion explicita', async () => {
    const h = await harness(); const state = await runAutomatic(h.base);
    const record = Object.values(state.records)[0]!;
    const store = new Store(h.root, state.binding);
    const now = new Date('2023-02-15T07:00:00Z');
    expect(await retainLocal(h.root, state, store, now, 14, false)).toHaveLength(1);
    expect(await readFile(join(h.root, '.runs', record.uuid, 'plan.json'), 'utf8')).toContain(record.uuid);
    await retainLocal(h.root, state, store, now, 14, true, h.backend);
    await expect(readFile(join(h.root, '.runs', record.uuid, 'plan.json'))).rejects.toThrow();
    expect(h.backend.receipt(record.uuid).verificado).toBe(true);
  });
  it('retencion protege archivos desconocidos y respaldos locales', async () => {
    const h = await harness(); const state = await runAutomatic(h.base);
    const record = Object.values(state.records)[0]!;
    await writeFile(join(h.root, '.runs', record.uuid, 'backup.bak'), 'synthetic');
    await expect(retainLocal(h.root, state, new Store(h.root, state.binding), new Date('2023-03-01'), 14, true, h.backend)).rejects.toThrow('UNKNOWN_FILES');
    expect(await readFile(join(h.root, '.runs', record.uuid, 'backup.bak'), 'utf8')).toBe('synthetic');
  });
});
describe('infraestructura y transporte', () => {
  it('bloqueo exclusivo se libera al cerrar el propietario', async () => {
    const first = await acquireLock(0);
    try { await expect(acquireLock(first.port)).rejects.toThrow('LOCK_PORT_BUSY'); } finally { await first.release(); }
    const next = await acquireLock(first.port); await next.release();
  });
  it('comprueba espacio antes de reservar y limita la suma de spools', async () => {
    const h = await harness();
    const low = await diskGuard(h.root, 100, 1000, async () => 120);
    await expect(low(21)).rejects.toThrow('INSUFFICIENT');
    const cap = await diskGuard(h.root, 100, 20, async () => 1000);
    await cap(15); await expect(cap(6)).rejects.toThrow('SPOOL_LIMIT');
  });
  it('relee el token rotado y respeta Retry-After sin reintentar 401', async () => {
    const h = await harness(); const file = join(h.root, 'credential'); await writeFile(file, 'old-fixture');
    const transport = vi.fn().mockResolvedValueOnce(new Response('', { status: 401 })).mockResolvedValueOnce(new Response('', { status: 429, headers: { 'Retry-After': '60' } })).mockResolvedValueOnce(Response.json({ data: { ok: true } }));
    const sleep = vi.fn(async () => {});
    const api = new Api({ ...h.env, API_TOKEN_FILE: file, HTTP_RETRIES: 1 }, transport, sleep);
    await expect(api.call('/api/health')).rejects.toThrow('401'); expect(sleep).not.toHaveBeenCalled();
    await writeFile(file, 'new-fixture');
    expect(await api.call('/api/health')).toEqual({ ok: true }); expect(sleep).toHaveBeenCalledWith(60000);
    expect(transport.mock.calls[1]![1].headers.Authorization).toBe('Bearer new-fixture');
  });
  it('reintenta una perdida de conexion antes de obtener respuesta', async () => {
    const h = await harness();
    const transport = vi.fn().mockRejectedValueOnce(new TypeError('network fixture')).mockResolvedValueOnce(Response.json({ data: { ok: true } }));
    const sleep = vi.fn(async () => {});
    expect(await new Api({ ...h.env, HTTP_RETRIES: 1 }, transport, sleep).call('/api/health')).toEqual({ ok: true });
    expect(sleep).toHaveBeenCalledWith(500);
  });
  it('integracion runner → HTTP real local → recibos y checkpoints, sin SQL ni VPS', async () => {
    const h = await harness();
    const server = createServer(async (req, res) => {
      try {
        expect(req.headers.authorization).toBe('Bearer fixture');
        let text = ''; for await (const chunk of req) text += chunk;
        const data = await h.backend.call(req.url!, text ? JSON.parse(text) : undefined);
        res.writeHead(200, { 'Content-Type': 'application/json' }).end(JSON.stringify({ data }));
      } catch (e) { res.writeHead(e instanceof HttpError ? e.status : 500).end('{}'); }
    });
    await new Promise<void>(resolve => server.listen(0, '127.0.0.1', resolve));
    try {
      const port = (server.address() as { port: number }).port;
      const env = { ...h.env, API_BASE_URL: `http://127.0.0.1:${port}` };
      await runAutomatic({ ...h.base, env, api: new Api(env) });
      expect(Object.values((await h.state()).records)[0]!.status).toBe('verified');
    } finally { await new Promise<void>((resolve, reject) => server.close(e => e ? reject(e) : resolve())); }
  });
});
