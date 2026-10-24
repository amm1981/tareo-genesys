import { createRequire } from 'node:module';

// API interna deliberadamente fijada a tedious 20.3.8. Nunca convierte dinero a Number.
const require = createRequire(import.meta.url);
const parser = require('tedious/lib/value-parser.js') as { readValue: Decoder };
const helpers = require('tedious/lib/token/helpers.js') as {
  Result: new (value: unknown, offset: number) => Decoded;
  NotEnoughDataError: new (required: number) => Error;
};
interface Meta { type: { name: string }; scale?: number }
interface Decoded { value: unknown; offset: number }
type Decoder = (buffer: Buffer, offset: number, meta: Meta, options: unknown) => Decoded;
const original = parser.readValue;
function available(b: Buffer, end: number) { if (b.length < end) throw new helpers.NotEnoughDataError(end); }
function unsigned(b: Buffer, start: number, count: number): bigint {
  available(b, start + count);
  let v = 0n;
  for (let i = count - 1; i >= 0; i--) v = v * 256n + BigInt(b[start + i]!);
  return v;
}
export function fixed(value: bigint, scale: number): string {
  const s = (value < 0n ? -value : value).toString().padStart(scale + 1, '0');
  return `${value < 0n ? '-' : ''}${scale ? `${s.slice(0, -scale)}.${s.slice(-scale)}` : s}`;
}
const dayMs = 86400000;
const epoch = Date.parse('0001-01-01T00:00:00Z');
function dateAt(days: number, ms = 0): string { return new Date(epoch + days * dayMs + ms).toISOString().slice(0, 10); }
function timeAt(ticks: bigint, scale: number): string {
  const seconds = ticks / 10000000n;
  const h = String(seconds / 3600n).padStart(2, '0');
  const m = String(seconds / 60n % 60n).padStart(2, '0');
  const s = String(seconds % 60n).padStart(2, '0');
  return `${h}:${m}:${s}${scale ? '.' + String(ticks % 10000000n).padStart(7, '0').slice(0, scale) : ''}`;
}
export const exactDecoder: Decoder = (b, start, meta, options) => {
  const type = meta.type.name;
  let offset = start;
  if (['DecimalN', 'NumericN'].includes(type)) {
    available(b, offset + 1);
    const length = b[offset++]!;
    if (!length) return new helpers.Result(null, offset);
    if (![5, 9, 13, 17].includes(length)) throw new Error('Longitud decimal TDS no soportada.');
    available(b, offset + length);
    const sign = b[offset++] === 0 ? -1n : 1n;
    const value = unsigned(b, offset, length - 1) * sign;
    return new helpers.Result(fixed(value, meta.scale ?? 0), offset + length - 1);
  }
  if (['Money', 'SmallMoney', 'MoneyN'].includes(type)) {
    let length = type === 'Money' ? 8 : 4;
    if (type === 'MoneyN') { available(b, offset + 1); length = b[offset++]!; }
    if (!length) return new helpers.Result(null, offset);
    if (![4, 8].includes(length)) throw new Error('Longitud money TDS no soportada.');
    available(b, offset + length);
    const value = length === 4 ? BigInt(b.readInt32LE(offset)) : BigInt(b.readInt32LE(offset)) * 4294967296n + BigInt(b.readUInt32LE(offset + 4));
    return new helpers.Result(fixed(value, 4), offset + length);
  }
  if (['Date', 'Time', 'DateTime2', 'DateTimeOffset', 'DateTimeN', 'DateTime', 'SmallDateTime'].includes(type)) {
    let length = type === 'DateTime' ? 8 : type === 'SmallDateTime' ? 4 : 0;
    if (!length) { available(b, offset + 1); length = b[offset++]!; }
    if (!length) return new helpers.Result(null, offset);
    available(b, offset + length);
    let value: string;
    if (['DateTime', 'SmallDateTime', 'DateTimeN'].includes(type)) {
      const days = length === 4 ? b.readUInt16LE(offset) : b.readInt32LE(offset);
      const ms = length === 4 ? b.readUInt16LE(offset + 2) * 60000 : Math.round(b.readUInt32LE(offset + 4) * 1000 / 300);
      value = new Date(Date.UTC(1900, 0, 1) + days * dayMs + ms).toISOString().slice(0, 23);
    } else if (type === 'Date') value = dateAt(Number(unsigned(b, offset, 3)));
    else {
      const scale = meta.scale ?? 7;
      const timeLength = length - (type === 'Time' ? 0 : type === 'DateTimeOffset' ? 5 : 3);
      let ticks = unsigned(b, offset, timeLength) * 10n ** BigInt(7 - scale);
      let days = type === 'Time' ? 0 : Number(unsigned(b, offset + timeLength, 3));
      let suffix = '';
      if (type === 'DateTimeOffset') {
        const minutes = b.readInt16LE(offset + length - 2);
        ticks += BigInt(minutes) * 600000000n;
        if (ticks < 0n) { days--; ticks += 864000000000n; }
        if (ticks >= 864000000000n) { days++; ticks -= 864000000000n; }
        suffix = `${minutes < 0 ? '-' : '+'}${String(Math.floor(Math.abs(minutes) / 60)).padStart(2, '0')}:${String(Math.abs(minutes) % 60).padStart(2, '0')}`;
      }
      value = `${type === 'Time' ? '' : dateAt(days) + 'T'}${timeAt(ticks, scale)}${suffix}`;
    }
    return new helpers.Result(value, offset + length);
  }
  return original(b, start, meta, options);
};
export function installExactDecoder() {
  if (require('tedious/package.json').version !== '20.3.8') throw new Error('Versión tedious sin auditar; se bloquea la extracción.');
  parser.readValue = exactDecoder;
}
