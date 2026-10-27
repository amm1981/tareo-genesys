import dotenv from 'dotenv';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import { readFile, writeFile } from 'node:fs/promises';
import { createInterface } from 'node:readline/promises';
import { ZodError } from 'zod';
import { argumentsFrom } from './config/args.js';
import { environmentSchema } from './config/env.js';
import { Source } from './database/source.js';
import { extract, upload, type RunPlan } from './services/sync.js';
import { Api, HttpError } from './services/api.js';
import { describeSourceError } from './utils/source-error.js';
import { acquireLock } from './services/lock.js';
import { CURRENT_SOURCE_ID, LEGACY_BLOCKED_UUID, CURRENT_SCHEMA_FILE } from './config/source.js';

process.chdir(join(dirname(fileURLToPath(import.meta.url)), '..'));
// dist/index.js y src/index.ts comparten el mismo directorio padre client/.
dotenv.config({ quiet: true });
async function confirmation(plan: RunPlan) {
  if (!process.stdin.isTTY) throw new Error('El envío requiere confirmación en una terminal interactiva.');
  const rl = createInterface({ input: process.stdin, output: process.stdout });
  const expected = `ENVIAR ${plan.anio}-${plan.mes === null ? 'TODO' : String(plan.mes).padStart(2,'0')}`;
  try { return (await rl.question(`Se enviarán ${plan.total_esperado} filas. Escriba ${expected}: `)) === expected; } finally { rl.close(); }
}
async function main() {
  const args = argumentsFrom(process.argv.slice(2));
  const env = environmentSchema.parse({ ...process.env, SQL_SCHEMA_FILE: args['schema-file'] ?? CURRENT_SCHEMA_FILE });
  const api = new Api(env);
  if (args.status) { console.log(JSON.stringify(await api.call(`/api/v1/sync/runs/${args.status}/status`))); return; }
  if (args.resume) {
    if (args.resume === LEGACY_BLOCKED_UUID) throw new Error('UUID anterior reservado para auditoría; no se puede reutilizar.');
    const folder = join('.runs', CURRENT_SOURCE_ID, args.resume);
    const plan = JSON.parse(await readFile(join(folder,'plan.json'), 'utf8')) as RunPlan;
    if (plan.uuid !== args.resume) throw new Error('UUID local no coincide.');
    (await import('./utils/protocol.js')).validateManifest(JSON.parse(plan.schema_json));
    if (!await confirmation(plan)) { console.log('Envío cancelado.'); return; }
    console.log(await upload(api, plan, folder)); return;
  }
  if (env.SQL_TRUST_SERVER_CERTIFICATE) console.warn('Advertencia TLS: SQL_TRUST_SERVER_CERTIFICATE=true. Conexión cifrada con verificación de identidad del servidor desactivada temporalmente.');
  const source = new Source(env);
  try {
    await source.connect();
    if (args['check-connection']) { console.log('Conexión verificada exclusivamente con CASABLANCA.'); return; }
    const dry = args['dry-run'] || args['inspect-schema'];
    const result = await extract(source, args.period!, env, dry);
    if (args['dry-run'] && !args['inspect-schema']) {
      const approved = (await import('./utils/protocol.js')).validateManifest(JSON.parse(await readFile(env.SQL_SCHEMA_FILE, 'utf8')));
      if (JSON.stringify(approved) !== JSON.stringify(result.manifest)) throw new Error('El esquema observado difiere del revisado; detener y volver a inspeccionar sin sobrescribir archivos.');
    }
    console.log(JSON.stringify({ uuid: result.plan.uuid, leidos: result.plan.origen_total, conservados: result.plan.total_esperado, descartados: result.plan.descartados, columnas: result.manifest.columns }));
    if (args['inspect-schema']) {
      const content = JSON.stringify(result.manifest);
      try { await writeFile(env.SQL_SCHEMA_FILE, content, { flag: 'wx', mode: 0o600 }); }
      catch (e) { if ((e as NodeJS.ErrnoException).code !== 'EEXIST' || await readFile(env.SQL_SCHEMA_FILE,'utf8') !== content) throw new Error('El archivo de esquema ya existe y difiere. Consérvelo y configure otra SQL_SCHEMA_FILE.'); }
      console.log('Esquema real guardado, sin valores de filas. Revíselo antes de preparar las columnas en Laravel.');
    }
    if (dry) return;
    await source.close();
    console.log(`Extracción preparada. UUID: ${result.plan.uuid}. Puede reanudar con --resume ${result.plan.uuid}.`);
    if (!await confirmation(result.plan)) { console.log('Envío cancelado; extracción conservada localmente.'); return; }
    console.log(await upload(api, result.plan, result.folder));
  } finally { await source.close(); }
}
async function lockedMain() {
  // El estado es solo lectura; las extracciones y envíos manuales comparten exclusión con automatización.
  if (process.argv.includes('--status')) return main();
  const lock = await acquireLock(environmentSchema.parse(process.env).SYNC_LOCK_PORT);
  try { return await main(); } finally { await lock.release(); }
}
lockedMain().catch((error: unknown) => {
  // No imprimir mensajes del driver ni cuerpos remotos: pueden contener valores o credenciales.
  const code = (error as { code?: string })?.code;
  const message = error instanceof Error && error.message.startsWith('Faltan las credenciales SQL:') ? error.message : error instanceof ZodError ? 'Parámetros o configuración inválidos; revise año, mes y .env.' :
    error instanceof HttpError ? error.message : describeSourceError(error) ?? (code ? `Fallo de origen/archivo (${/^[A-Z0-9_]+$/.test(code) ? code : 'ERROR'}). Revise configuración, red y TLS.` :
      error instanceof Error && !/password|token|login failed/i.test(error.message) ? error.message : 'Error de ejecución; revise configuración.');
  console.error(message); process.exitCode = 1;
});
