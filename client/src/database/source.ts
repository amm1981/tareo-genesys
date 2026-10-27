import { installExactDecoder } from './exact-tds.js';
import sql from 'mssql';
import { sqlConfig, type Environment } from '../config/env.js';
import type { Period } from '../config/args.js';
import { validateManifest, type Manifest } from '../utils/protocol.js';
import { CURRENT_PROCEDURE, assertExtractionColumns } from '../config/source.js';

export class Source {
  private pool: sql.ConnectionPool;
  constructor(env: Environment) { installExactDecoder(); this.pool = new sql.ConnectionPool(sqlConfig(env)); this.pool.on('error', () => {}); }
  async connect() {
    await this.pool.connect();
    const result = await this.pool.request().query('SELECT DB_NAME() AS database_name');
    if (result.recordset[0]?.database_name !== 'CASABLANCA') { await this.close(); throw new Error('Base de origen no permitida.'); }
  }
  async close() { await this.pool.close(); }
  async extract(period: Period, schema: (manifest: Manifest) => void, consume: (row: unknown[], manifest: Manifest) => Promise<void>) {
    const request = this.pool.request();
    request.stream = true;
    request.arrayRowMode = true;
    request.input('Año', sql.Int, period.anio).input('Mes', sql.Int, period.mes ?? null);
    let manifest: Manifest | undefined;
    let sets = 0;
    let failure: unknown;
    let pending = Promise.resolve();
    const fail = (error: unknown) => { failure ??= error; request.cancel(); };
    request.on('recordset', (columns: Array<sql.IColumnMetadata[string]>) => {
      try {
        if (++sets !== 1) throw new Error('El SP devolvió múltiples conjuntos; se requiere revisar el mapeo.');
        manifest = validateManifest({ version: 1, database: 'CASABLANCA', procedure: CURRENT_PROCEDURE, columns: columns.map(c => {
          const type = Object.entries(sql.TYPES).find(([, t]) => t === c.type)?.[0];
          if (!type) throw new Error('Tipo SQL no reconocido.');
          return { name: c.name, type, nullable: c.nullable, precision: ['Money','SmallMoney'].includes(type) ? (type === 'Money' ? 19 : 10) : c.precision ?? null, scale: ['Money','SmallMoney'].includes(type) ? 4 : c.scale ?? null, length: c.length ?? null };
        }) });
        assertExtractionColumns(manifest);
        schema(manifest);
      } catch (e) { fail(e); }
    });
    request.on('row', (row: unknown[]) => {
      request.pause();
      pending = pending.then(async () => {
        if (failure) return;
        if (!manifest) throw new Error('Fila sin metadatos.');
        await consume(row, manifest);
      }).catch(fail).finally(() => request.resume());
    });
    try { await request.execute(CURRENT_PROCEDURE); } catch (e) { failure ??= e; }
    await pending;
    if (failure) throw failure;
    if (!manifest) throw new Error('No se recibió el esquema del SP.');
    return manifest;
  }
}
