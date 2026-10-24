import { EventEmitter } from 'node:events';
import { describe,it,expect,vi,beforeEach } from 'vitest';
const state=vi.hoisted(()=>({database:'CASABLANCA',credentials:[] as unknown[],request:undefined as any,pool:undefined as any}));
vi.mock('mssql',async()=>{
  const {EventEmitter}=await import('node:events');
  const int=()=>({}); const date=()=>({}); const decimal=()=>({});
  class Request extends EventEmitter {
    stream=false; arrayRowMode=false; params:unknown[]=[]; paused=false; canceled=false;
    input(...args:unknown[]) { this.params.push(args); return this; }
    async query(text:string) { expect(text).toBe('SELECT DB_NAME() AS database_name'); return {recordset:[{database_name:state.database}]}; }
    pause() { this.paused=true; } resume() { this.paused=false; } cancel() { this.canceled=true; }
    async execute(name:string) {
      expect(name).toBe('dbo.SPWConsumoMesVF');
      expect(this.stream).toBe(true); expect(this.arrayRowMode).toBe(true);
      this.emit('recordset',[{name:'Fecha',type:date,nullable:false,length:8,scale:7},{name:'ImporteFixture',type:decimal,precision:38,scale:8,nullable:true,length:17}]);
      this.emit('row',['2026-09-01T00:00:00.0000000','0.12345678']);
      return {};
    }
  }
  class ConnectionPool extends EventEmitter {
    closed=false; constructor(config:unknown) {super(); state.credentials.push(config); state.pool=this;}
    async connect() {return this;} async close() {this.closed=true;}
    request() { const r=new Request(); state.request=r; return r; }
  }
  return {default:{ConnectionPool,Int:int,TYPES:{Int:int,DateTime2:date,Decimal:decimal}}};
});
import {Source} from '../src/database/source.js';
import {environmentSchema} from '../src/config/env.js';
beforeEach(()=>{state.database='CASABLANCA';state.credentials.length=0;});
describe('conexión y ejecución de origen con mssql simulado',()=>{
  it('fija CASABLANCA, verifica DB_NAME y parametriza Año/Mes',async()=>{
    const source=new Source(environmentSchema.parse({SQL_USERNAME:'fixture',SQL_PASSWORD:'fixture'}));
    await source.connect(); const schemas:unknown[]=[]; const rows:unknown[]=[];
    await source.extract({anio:2026,mes:9},m=>schemas.push(m),async r=>{rows.push(r);});
    expect(state.credentials[0]).toMatchObject({database:'CASABLANCA',options:{encrypt:true,trustServerCertificate:false}});
    expect(state.request.params).toEqual([['Año',expect.any(Function),2026],['Mes',expect.any(Function),9]]);
    expect(schemas).toHaveLength(1); expect(rows).toHaveLength(1);
    await source.close(); expect(state.pool.closed).toBe(true);
  });
  it('cierra y rechaza si el servidor responde con otra base',async()=>{
    state.database='Genesys'; const source=new Source(environmentSchema.parse({SQL_USERNAME:'fixture',SQL_PASSWORD:'fixture'}));
    await expect(source.connect()).rejects.toThrow('no permitida'); expect(state.pool.closed).toBe(true);
  });
  it('propaga errores de validación de filas y cancela la solicitud',async()=>{
    const source=new Source(environmentSchema.parse({SQL_USERNAME:'fixture',SQL_PASSWORD:'fixture'})); await source.connect();
    await expect(source.extract({anio:2026},()=>{},async()=>{throw new Error('fixture validation');})).rejects.toThrow('fixture validation');
    expect(state.request.canceled).toBe(true); await source.close();
  });
});
