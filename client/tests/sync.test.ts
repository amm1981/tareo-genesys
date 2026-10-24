import { mkdtemp, rm, readFile } from 'node:fs/promises';
import { join } from 'node:path';
import { afterEach,describe,it,expect,vi } from 'vitest';
import { extract, upload } from '../src/services/sync.js';
import { Api } from '../src/services/api.js';
import { environmentSchema } from '../src/config/env.js';
import { manifest,row } from './fixtures.js';
const dirs:string[]=[];
afterEach(async()=>{for(const d of dirs.splice(0)) await rm(d,{recursive:true,force:true});});
const source = { extract: async (_period: unknown, onSchema: (m:typeof manifest)=>void,onRow:(r:unknown[],m:typeof manifest)=>Promise<void>)=>{
  onSchema(manifest); for (const r of [row,row,['2027-09-01T00:00:00',row[1],row[2]]]) await onRow(r,manifest); return manifest;
}};
describe('extracción y transporte',()=>{
  it('dry-run valida sin escribir filas ni enviar HTTP y cuenta descartes',async()=>{
    const result=await extract(source,{anio:2026,mes:9},environmentSchema.parse({}),true);
    expect(result.plan).toMatchObject({origen_total:3,total_esperado:2,descartados:1,total_lotes:0});
  });
  it('lotes, filas idénticas válidas, reanudación y verificación completa',async()=>{
    const {writeFile}=await import('node:fs/promises');
    const dir=await mkdtemp(join(process.cwd(),'tests','.run-')); dirs.push(dir);
    const schema=join(dir,'schema.json'); await writeFile(schema,JSON.stringify(manifest));
    const env=environmentSchema.parse({SQL_SCHEMA_FILE:schema,BATCH_SIZE:1,API_TOKEN:'fixture'});
    const result=await extract(source,{anio:2026,mes:9},env,false,dir);
    expect(result.plan.total_lotes).toBe(2);
    const one=JSON.parse(await readFile(join(result.folder,'1.json'),'utf8'));
    const two=JSON.parse(await readFile(join(result.folder,'2.json'),'utf8'));
    expect(JSON.parse(one.payload)[0].numero_fila).toBe(1); expect(JSON.parse(two.payload)[0].numero_fila).toBe(2);
    const transport=vi.fn(async()=>Response.json({data:{estado:'completada',total_recibido:2}}));
    await upload(new Api(env,transport),result.plan,result.folder);
    expect(transport).toHaveBeenCalledTimes(4);
    expect(transport.mock.calls[0]?.[0]).toBeDefined();
  });
  it('reintenta fallos temporales con espera progresiva sin reintentar 401',async()=>{
    const env=environmentSchema.parse({API_TOKEN:'fixture',HTTP_RETRIES:2});
    const sleep=vi.fn(async()=>{});
    const transport=vi.fn().mockResolvedValueOnce(new Response('',{status:503})).mockResolvedValueOnce(Response.json({data:{ok:true}}));
    expect(await new Api(env,transport,sleep).call('/api/health')).toEqual({ok:true});
    expect(sleep).toHaveBeenCalledWith(500);
    const denied=vi.fn(async()=>new Response('',{status:401}));
    await expect(new Api(env,denied,sleep).call('/api/health')).rejects.toThrow('401'); expect(denied).toHaveBeenCalledTimes(1);
  });
});
