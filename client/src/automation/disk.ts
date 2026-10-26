import { lstat, readdir, statfs } from 'node:fs/promises';
import { join } from 'node:path';

export async function folderBytes(path: string): Promise<number> {
  let stat;
  try { stat = await lstat(path); } catch (e) { if ((e as NodeJS.ErrnoException).code === 'ENOENT') return 0; throw e; }
  if (stat.isSymbolicLink()) throw new Error('SPOOL_LINK_NOT_ALLOWED');
  if (!stat.isDirectory()) return stat.size;
  let bytes = 0;
  for (const item of await readdir(path)) bytes += await folderBytes(join(path, item));
  return bytes;
}
export async function diskGuard(root: string, minimumFreeBytes: number, maximumSpoolBytes: number, free = async () => {
  const stat = await statfs(root, { bigint: true });
  return Number(stat.bavail * stat.bsize);
}) {
  let used = await folderBytes(join(root, '.runs'));
  return async (bytes = 0) => {
    if (used + bytes > maximumSpoolBytes) throw new Error('SPOOL_LIMIT_REACHED');
    if (await free() - bytes < minimumFreeBytes) throw new Error('INSUFFICIENT_FREE_SPACE');
    used += bytes;
  };
}
