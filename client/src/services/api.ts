import type { Environment } from '../config/env.js';
export class Api {
  constructor(private env: Environment, private transport: typeof fetch = fetch, private sleep = (ms: number) => new Promise(r => setTimeout(r, ms))) {}
  async call<T>(path: string, body?: unknown): Promise<T> {
    if (!this.env.API_TOKEN) throw new Error('Complete API_TOKEN antes de transferir.');
    for (let attempt = 0; ; attempt++) {
      try {
        const response = await this.transport(new URL(path, this.env.API_BASE_URL), {
          method: body === undefined ? 'GET' : 'POST', redirect: 'error',
          headers: { Accept: 'application/json', 'Content-Type': 'application/json', Authorization: `Bearer ${this.env.API_TOKEN}` },
          signal: AbortSignal.timeout(this.env.REQUEST_TIMEOUT_MS),
          ...(body === undefined ? {} : { body: JSON.stringify(body) }),
        });
        if (!response.ok) {
          if ([408,429,500,502,503,504].includes(response.status) && attempt < this.env.HTTP_RETRIES) { await response.body?.cancel(); await this.sleep(500 * 2 ** attempt); continue; }
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
