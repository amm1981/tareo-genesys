import { createServer } from 'node:net';

// Exclusión local mantenida por el SO: se libera también tras apagado o terminación abrupta.
export async function acquireLock(port = 47831) {
  const server = createServer(socket => socket.destroy());
  await new Promise<void>((resolve, reject) => {
    server.once('error', () => reject(new Error('SYNC_ALREADY_RUNNING_OR_LOCK_PORT_BUSY')));
    server.listen({ host: '127.0.0.1', port, exclusive: true }, resolve);
  });
  server.unref();
  return { port: (server.address() as { port: number }).port, release: () => new Promise<void>((resolve, reject) => server.close(error => error ? reject(error) : resolve())) };
}
