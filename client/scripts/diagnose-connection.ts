import dotenv from 'dotenv';
import { environmentSchema } from '../src/config/env.js';
import { Source } from '../src/database/source.js';

dotenv.config({ quiet: true });
const env = environmentSchema.parse(process.env);
console.log(JSON.stringify({ database: env.SQL_DATABASE, port: env.SQL_PORT, auth: env.SQL_AUTH_MODE, encrypt: env.SQL_ENCRYPT, trustCertificate: env.SQL_TRUST_SERVER_CERTIFICATE, caConfigured: Boolean(env.SQL_CA_FILE), tlsNameConfigured: Boolean(env.SQL_TLS_SERVER_NAME) }));
const source = new Source(env);
try {
  await source.connect();
  console.log('Conexión verificada con CASABLANCA.');
} catch (error) {
  const visited = new Set<unknown>();
  const inspect = (value: unknown, depth = 0): unknown => {
    if (!value || typeof value !== 'object' || visited.has(value) || depth > 6) return undefined;
    visited.add(value);
    const e = value as Record<string, unknown>;
    const message = typeof e.message === 'string' ? e.message : '';
    return {
      code: typeof e.code === 'string' && /^[A-Z0-9_]+$/.test(e.code) ? e.code : undefined,
      number: typeof e.number === 'number' ? e.number : undefined,
      certificateUntrusted: /self.signed|unable to verify|certificate chain|issuer certificate|untrusted/i.test(message),
      certificateExpired: /expired|not yet valid/i.test(message),
      hostnameMismatch: /hostname|altnames|altname|does not match/i.test(message),
      tlsVersion: /unsupported protocol|protocol version|legacy sigalg|no protocols|wrong version/i.test(message),
      closed: /socket hang up|closed|reset|end of file/i.test(message),
      timeout: /timeout|timed out/i.test(message),
      authentication: /login failed|authentication failed/i.test(message),
      originalError: inspect(e.originalError, depth + 1), cause: inspect(e.cause, depth + 1),
      errors: Array.isArray(e.errors) ? e.errors.map(x => inspect(x, depth + 1)) : undefined,
    };
  };
  console.error(JSON.stringify(inspect(error), null, 2));
  process.exitCode = 1;
} finally { await source.close(); }
