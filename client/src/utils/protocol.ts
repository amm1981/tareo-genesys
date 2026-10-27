import { createHash } from 'node:crypto';
import { z } from 'zod';
import { Decimal } from 'decimal.js';
import type { Period } from '../config/args.js';
import { isFloatCost, validateFloatCost } from './float-cost.js';
import { CURRENT_PROCEDURE } from '../config/source.js';

export const columnSchema = z.object({
  name: z.string().min(1).max(128),
  type: z.enum(['TinyInt','SmallInt','Int','BigInt','Bit','Decimal','Numeric','Money','SmallMoney','Float','Real','Char','NChar','VarChar','NVarChar','Text','NText','Date','Time','DateTime','SmallDateTime','DateTime2','DateTimeOffset','UniqueIdentifier','Binary','VarBinary','Image','Xml']),
  nullable: z.boolean(), precision: z.number().int().min(1).max(38).nullable(),
  scale: z.number().int().min(0).max(38).nullable(), length: z.number().int().nullable(),
});
export const manifestSchema = z.object({ version: z.literal(1), database: z.literal('CASABLANCA'), procedure: z.literal(CURRENT_PROCEDURE), columns: z.array(columnSchema).min(1).max(500) });
export type Manifest = z.infer<typeof manifestSchema>;
export type Column = Manifest['columns'][number];
export type DataRow = Record<string, string | number | boolean | null>;
export const sha256 = (value: string) => createHash('sha256').update(value, 'utf8').digest('hex');
export function validateManifest(input: unknown): Manifest {
  const manifest = manifestSchema.parse(input);
  const names = manifest.columns.map(c => c.name.toLowerCase());
  if (new Set(names).size !== names.length || manifest.columns.some(c => ['__proto__','constructor','prototype'].includes(c.name))) throw new Error('Columnas duplicadas o no soportadas.');
  const date = manifest.columns.find(c => c.name === 'Fecha');
  if (!date || !['Date','DateTime','SmallDateTime','DateTime2','DateTimeOffset','Char','VarChar','NVarChar','NChar'].includes(date.type)) throw new Error('Se requiere la columna real Fecha con un tipo de fecha o texto ISO.');
  for (const c of manifest.columns) if (['Decimal','Numeric','Money','SmallMoney'].includes(c.type) && (c.precision === null || c.scale === null || c.scale > c.precision)) throw new Error('Precisión y escala decimal no verificadas.');
  return manifest;
}
export function inPeriod(fecha: unknown, period: Period): boolean {
  if (typeof fecha !== 'string' || !/^\d{4}-\d{2}-\d{2}(?:T(?:[01]\d|2[0-3]):[0-5]\d:[0-5]\d(?:\.\d{1,7})?(?:Z|[+-](?:0\d|1[0-4]):[0-5]\d)?)?$/.test(fecha)) throw new Error('Fecha ausente, inválida o ambigua; no se publica.');
  const date = fecha.slice(0,10);
  if (!Number.isFinite(Date.parse(date)) || new Date(date).toISOString().slice(0,10) !== date) throw new Error('Fecha fuera del calendario.');
  return Number(fecha.slice(0,4)) === period.anio && (period.mes === undefined || Number(fecha.slice(5,7)) === period.mes);
}
export function serializeRow(row: unknown[], manifest: Manifest): string {
  if (row.length !== manifest.columns.length) throw new Error('Número de columnas inesperado.');
  const output: DataRow = Object.create(null) as DataRow;
  manifest.columns.forEach((c, i) => {
    let value: unknown = row[i];
    if (value === null) { if (!c.nullable) throw new Error('NULL en una columna obligatoria.'); }
    else if (['Decimal','Numeric','Money','SmallMoney'].includes(c.type)) {
      if (typeof value !== 'string' || !/^-?\d+(?:\.\d+)?$/.test(value)) throw new Error('Decimal recibido como Number: extracción bloqueada.');
      const digits = value.replace(/^-/, '').split('.');
      if ((digits[1]?.length ?? 0) > c.scale! || (digits[0]!.replace(/^0+/, '').length > c.precision! - c.scale!)) throw new Error('Decimal fuera de precisión.');
      if (!new Decimal(value).isFinite()) throw new Error('Decimal inválido.');
    } else if (['Binary','VarBinary','Image'].includes(c.type)) {
      if (!Buffer.isBuffer(value)) throw new Error('Dato binario de origen inválido.');
      value = `base64:${value.toString('base64')}`;
    } else if (['TinyInt','SmallInt','Int'].includes(c.type)) {
      const limits: Record<string,[number,number]> = { TinyInt:[0,255], SmallInt:[-32768,32767], Int:[-2147483648,2147483647] };
      const [min,max] = limits[c.type]!;
      if (typeof value !== 'number' || !Number.isInteger(value) || value < min || value > max) throw new Error('Entero de origen inválido.');
    } else if (c.type === 'BigInt') {
      if (typeof value !== 'string' || !/^-?\d{1,19}$/.test(value) || BigInt(value) < -9223372036854775808n || BigInt(value) > 9223372036854775807n) throw new Error('BigInt de origen inválido.');
    } else if (c.type === 'Bit') {
      if (typeof value !== 'boolean') throw new Error('Bit de origen inválido.');
    } else if (['Float','Real'].includes(c.type)) {
      if (typeof value !== 'number' || !Number.isFinite(value) || (Number.isInteger(value) && !Number.isSafeInteger(value))) throw new Error('Número no serializable sin pérdida.');
      if (isFloatCost(c.name)) validateFloatCost(value);
    } else if (['Date','DateTime','SmallDateTime','DateTime2','DateTimeOffset'].includes(c.type)) {
      if (typeof value !== 'string') throw new Error('Fecha de origen sin representación exacta.');
      inPeriod(value,{anio:1});
    } else if (c.type === 'Time') {
      if (typeof value !== 'string' || !/^(?:[01]\d|2[0-3]):[0-5]\d:[0-5]\d(?:\.\d{1,7})?$/.test(value)) throw new Error('Hora de origen inválida.');
    } else if (typeof value !== 'string') throw new Error('Texto de origen inválido.');
    output[c.name] = value as DataRow[string];
  });
  return JSON.stringify(output);
}
export interface WireRow { numero_fila: number; datos_json: string }
export interface Batch { numero_lote: number; payload: string; checksum: string }
