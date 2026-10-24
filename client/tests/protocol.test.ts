import { describe, it, expect } from 'vitest';
import { periodSchema, argumentsFrom } from '../src/config/args.js';
import { environmentSchema, sqlConfig } from '../src/config/env.js';
import { inPeriod, serializeRow, validateManifest } from '../src/utils/protocol.js';
import { manifest, row } from './fixtures.js';

describe('parámetros y seguridad de origen', () => {
  it('valida año, mes opcional y rangos', () => {
    expect(periodSchema.parse({anio:'2026',mes:'9'})).toEqual({anio:2026,mes:9});
    expect(periodSchema.parse({anio:'2026'}).mes).toBeUndefined();
    for (const value of [{anio:0},{anio:2026,mes:13},{anio:2026,mes:0},{anio:2026.5},{anio:2026,mes:'abc'}]) expect(periodSchema.safeParse(value).success).toBe(false);
    expect(() => argumentsFrom(['--anio','2026','--oops'])).toThrow();
  });
  it('solo admite CASABLANCA y HTTPS remoto', () => {
    for (const name of ['Genesys','Casablanca_2025','DIAMOND_BRIDGE_SAC','master']) expect(environmentSchema.safeParse({SQL_DATABASE:name}).success).toBe(false);
    for (const url of ['http://example.com','ftp://localhost','http://localhost.evil','https://user:secret@example.com','https://example.com/path']) expect(environmentSchema.safeParse({API_BASE_URL:url}).success).toBe(false);
    expect(environmentSchema.parse({}).SQL_TRUST_SERVER_CERTIFICATE).toBe(false);
    expect(() => sqlConfig(environmentSchema.parse({SQL_USERNAME:'fixture',SQL_PASSWORD:'fixture',SQL_ENCRYPT:'false'}))).toThrow();
  });
});
describe('esquema, período y serialización exacta', () => {
  it('inspecciona metadatos sin inventar columnas', () => {
    expect(validateManifest(manifest)).toEqual(manifest);
    expect(() => validateManifest({...manifest,columns:[manifest.columns[1]]})).toThrow();
    expect(() => validateManifest({...manifest,columns:[...manifest.columns,manifest.columns[0]]})).toThrow();
  });
  it('filtra Fecha mostrada, incluyendo años posteriores y límites', () => {
    expect(inPeriod('2026-09-30T23:59:59.9999999',{anio:2026,mes:9})).toBe(true);
    for (const fecha of ['2027-09-01','2026-08-31','2026-10-01']) expect(inPeriod(fecha,{anio:2026,mes:9})).toBe(false);
    expect(inPeriod('2026-01-01',{anio:2026})).toBe(true);
    for (const fecha of [null,'09/01/2026','2026-02-30']) expect(() => inPeriod(fecha,{anio:2026})).toThrow();
  });
  it('mantiene precisión decimal, fechas de siete dígitos, Unicode y espacios', () => {
    const result = JSON.parse(serializeRow(row,manifest));
    expect(Object.values(result)).toEqual(row);
    expect(() => serializeRow([row[0],123.45,row[2]],manifest)).toThrow(/Number/);
    expect(() => serializeRow([row[0],'0.123456789',row[2]],manifest)).toThrow();
    expect(JSON.parse(serializeRow([row[0],null,null],manifest)).ImporteFixture).toBeNull();
  });
});
