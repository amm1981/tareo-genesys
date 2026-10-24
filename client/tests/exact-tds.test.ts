import { createRequire } from 'node:module';
import { describe,it,expect } from 'vitest';
import { installExactDecoder } from '../src/database/exact-tds.js';
const require = createRequire(import.meta.url);
installExactDecoder();
const parser = require('tedious/lib/value-parser.js');
function read(buffer: Buffer,name: string,scale = 0) { return parser.readValue(buffer,0,{type:{name},scale,precision:38},{useUTC:true}); }
function numeric(n: bigint, bytes: number, positive = true) {
  const b = Buffer.alloc(bytes+2); b[0]=bytes+1; b[1]=positive ? 1 : 0;
  for (let i=0;i<bytes;i++) { b[i+2]=Number(n & 255n); n >>= 8n; }
  return b;
}
describe('decodificador real tedious con paquetes TDS sintéticos', () => {
  it('decimal y numeric(38,8), negativos, ceros, NULL y todas las longitudes', () => {
    const b=numeric(12345678901234567890123456789012345678n,16);
    expect(read(b,'DecimalN',8)).toMatchObject({value:'123456789012345678901234567890.12345678',offset:18});
    expect(read(numeric(12345n,8,false),'NumericN',4).value).toBe('-1.2345');
    for (const bytes of [4,8,12,16]) expect(read(numeric(1n,bytes),'DecimalN',2).value).toBe('0.01');
    expect(read(numeric(0n,4,false),'DecimalN',4).value).toBe('0.0000');
    expect(read(Buffer.from([0]),'NumericN').value).toBeNull();
  });
  it('money conserva cuatro decimales incluso en límites de int64', () => {
    for (const n of [9223372036854775807n,-9223372036854775808n,123456n]) {
      const b=Buffer.alloc(8); b.writeInt32LE(Number(n >> 32n),0); b.writeUInt32LE(Number(n & 4294967295n),4);
      expect(read(b,'Money').value).toBe(n === 9223372036854775807n ? '922337203685477.5807' : n < 0n ? '-922337203685477.5808' : '12.3456');
      expect(read(Buffer.concat([Buffer.from([8]),b]),'MoneyN').value).toBe(read(b,'Money').value);
    }
    const small = Buffer.alloc(4); small.writeInt32LE(-2147483648);
    expect(read(small,'SmallMoney').value).toBe('-214748.3648');
  });
  it('respeta los buffers fragmentados del parser de streaming', () => {
    const full=numeric(123456n,16);
    for(let i=0;i<full.length;i++) expect(()=>read(full.subarray(0,i),'NumericN',2)).toThrow();
    expect(read(full,'NumericN',2).value).toBe('1234.56');
  });
  it('datetime2 conserva 100ns y datetimeoffset conserva fecha local y zona', () => {
    const days=Math.round((Date.parse('2026-09-01T00:00:00Z')-Date.parse('0001-01-01T00:00:00Z'))/86400000);
    const b=Buffer.alloc(9); b[0]=8;
    let ticks=452961234567n;
    for(let i=0;i<5;i++) { b[1+i]=Number(ticks & 255n); ticks >>= 8n; }
    b.writeUIntLE(days,6,3);
    expect(read(b,'DateTime2',7).value).toBe('2026-09-01T12:34:56.1234567');
    const zoned=Buffer.concat([Buffer.from([10]),b.subarray(1),Buffer.alloc(2)]); zoned.writeInt16LE(-300,9);
    expect(read(zoned,'DateTimeOffset',7).value).toBe('2026-09-01T07:34:56.1234567-05:00');
  });
});
