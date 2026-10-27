import type { Manifest } from '../src/utils/protocol.js';
export const manifest: Manifest = { version:1, database:'CASABLANCA', procedure:'dbo.SPWConsumoMes', columns:[
  { name:'Fecha', type:'DateTime2', nullable:false, precision:null, scale:7, length:8 },
  { name:'ImporteFixture', type:'Decimal', nullable:true, precision:38, scale:8, length:17 },
  { name:'DetalleFixture', type:'NVarChar', nullable:true, precision:null, scale:null, length:100 },
] };
export const row = ['2026-09-01T12:30:00.1234567','123456789012345678901234567890.12345678',' Texto sintético á漢字 😀 '];
