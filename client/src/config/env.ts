import { z } from 'zod';
import { readFileSync } from 'node:fs';
import type { config as SqlConfig } from 'mssql';

const bool = z.enum(['true', 'false']).transform(v => v === 'true');
export const environmentSchema = z.object({
  SQL_SERVER: z.string().min(1).default('192.168.136.3'),
  SQL_PORT: z.coerce.number().int().min(1).max(65535).default(1433),
  SQL_DATABASE: z.literal('CASABLANCA').default('CASABLANCA'),
  SQL_AUTH_MODE: z.enum(['sql', 'windows']).default('sql'),
  SQL_USERNAME: z.string().default(''), SQL_PASSWORD: z.string().default(''),
  SQL_ENCRYPT: bool.default(true), SQL_TRUST_SERVER_CERTIFICATE: bool.default(false),
  SQL_CA_FILE: z.string().default(''), SQL_TLS_SERVER_NAME: z.string().default(''),
  SQL_CONNECTION_TIMEOUT_MS: z.coerce.number().int().min(1000).max(120000).default(15000),
  SQL_REQUEST_TIMEOUT_MS: z.coerce.number().int().min(1000).max(3600000).default(600000),
  API_BASE_URL: z.url().default('http://127.0.0.1:8000').refine(value => {
    const url = new URL(value);
    return !url.username && !url.password && !url.search && !url.hash && url.pathname === '/' &&
      (url.protocol === 'https:' || (url.protocol === 'http:' && ['localhost', '127.0.0.1', '[::1]'].includes(url.hostname)));
  }, 'API_BASE_URL requiere HTTPS o HTTP de localhost, sin credenciales ni ruta.'),
  API_TOKEN: z.string().default(''),
  BATCH_SIZE: z.coerce.number().int().min(1).max(1000).default(1000),
  MAX_BATCH_BYTES: z.coerce.number().int().min(1024).max(4194304).default(4194304),
  REQUEST_TIMEOUT_MS: z.coerce.number().int().min(1000).max(600000).default(60000),
  HTTP_RETRIES: z.coerce.number().int().min(0).max(5).default(3),
  SQL_SCHEMA_FILE: z.string().default('schema.actual.json'),
});
export type Environment = z.infer<typeof environmentSchema>;
export function sqlConfig(env: Environment): SqlConfig {
  if (env.SQL_AUTH_MODE !== 'sql') throw new Error('Windows integrada pendiente: use una cuenta SQL de lectura o configure el adaptador ODBC documentado.');
  if (!env.SQL_USERNAME || !env.SQL_PASSWORD) throw new Error('Faltan las credenciales SQL: complete SQL_USERNAME y SQL_PASSWORD en client/.env.');
  if (!env.SQL_ENCRYPT) throw new Error('SQL_ENCRYPT debe ser true. Configure la CA y el nombre TLS del servidor.');
  return {
    server: env.SQL_SERVER, port: env.SQL_PORT, database: 'CASABLANCA',
    user: env.SQL_USERNAME, password: env.SQL_PASSWORD,
    connectionTimeout: env.SQL_CONNECTION_TIMEOUT_MS, requestTimeout: env.SQL_REQUEST_TIMEOUT_MS,
    pool: { min: 0, max: 1, idleTimeoutMillis: 10000 },
    options: {
      encrypt: true, trustServerCertificate: env.SQL_TRUST_SERVER_CERTIFICATE,
      useUTC: true, appName: 'GET_Genesys', readOnlyIntent: true,
      ...(env.SQL_TLS_SERVER_NAME ? { serverName: env.SQL_TLS_SERVER_NAME } : {}),
      ...(env.SQL_CA_FILE ? { cryptoCredentialsDetails: { ca: readFileSync(env.SQL_CA_FILE) } } : {}),
    },
  };
}
