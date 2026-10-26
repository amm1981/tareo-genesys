import type { Environment } from '../config/env.js';
import { readFile } from 'node:fs/promises';
export class Api {
  constructor(private env: Environment, private transport: typeof fetch = fetch, private sleep = (ms: number) => new Promise(r => setTimeout(r, ms))) {}
  async call<T>(path: string, body?: unknown): Promise<T> {
    for (let attempt = 0; ; attempt++) {
      try {
        const token = this.env.API_TOKEN_FILE ? (await readFile(this.env.API_TOKEN_FILE, 'utf8')).trim() : this.env.API_TOKEN;
        if (!token || token.length > 8192 || /\s/.test(token)) throw new Error('SYNC_CREDENTIAL_UNAVAILABLE');
        const response = await this.transport(new URL(path, this.env.API_BASE_URL), {
          method: body === undefined ? 'GET' : 'POST', redirect: 'error',
          headers: { Accept: 'application/json', 'Content-Type': 'application/json', Authorization: `Bearer ${token}` },
          signal: AbortSignal.timeout(this.env.REQUEST_TIMEOUT_MS),
          ...(body === undefined ? {} : { body: JSON.stringify(body) }),
        });
        if (!response.ok) {
          if ([408,429,500,502,503,504].includes(response.status) && attempt < this.env.HTTP_RETRIES) {
            const retry = response.headers.get('Retry-After');
            const seconds = retry && /^\d+$/.test(retry) ? Number(retry) : retry ? Math.max(0, (Date.parse(retry) - Date.now()) / 1000) : 0;
            await response.body?.cancel(); await this.sleep(Math.max(500 * 2 ** attempt, Math.min(120000, Number.isFinite(seconds) ? seconds * 1000 : 0))); continue;
          }
          throw new HttpError(response.status);
        }
        const result = await response.json() as { data?: T };
        if (result.data === undefined) throw new Error('Respuesta API sin data.');
        return result.data;
      } catch (error) {
        if (error instanceof HttpError || attempt >= this.env.HTTP_RETRIES) throw error;
        await this.sleep(500 * 2 ** attempt);
      }
    }
  }
}
export class HttpError extends Error { constructor(readonly status: number) { super(`API HTTP ${status}; consulte el estado con el UUID de ejecución.`); } }
