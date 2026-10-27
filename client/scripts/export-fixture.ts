import { mkdir,writeFile } from 'node:fs/promises';
import { randomUUID } from 'node:crypto';
import { serializeRow,sha256 } from '../src/utils/protocol.js';
import { manifest,row } from '../tests/fixtures.js';
import { FLOAT_COST_COLUMNS } from '../src/utils/float-cost.js';
import type { Manifest } from '../src/utils/protocol.js';
// Solo datos sintéticos. Este archivo no se ejecuta durante una sincronización.
const costManifest: Manifest = { ...manifest, columns:[...manifest.columns, ...FLOAT_COST_COLUMNS.map(name=>({name,type:'Float' as const,nullable:true,precision:null,scale:null,length:8}))] };
const schema_json=JSON.stringify(costManifest);
const costRow=[...row,0.30000000000000004,1e-25,-123.45678901234567,0,null,-0.00000001,2.5,0];
const payload=JSON.stringify([1,2].map(numero_fila=>({numero_fila,datos_json:serializeRow(costRow,costManifest)})));
const checksum=sha256(payload);
const batch={numero_lote:1,payload,checksum};
const plan={uuid:randomUUID(),anio:2026,mes:9,total_esperado:2,total_lotes:1,checksum_total:sha256(`1:${checksum}\n`),schema_json,schema_checksum:sha256(schema_json)};
await mkdir('.runs',{recursive:true});
await writeFile('.runs/protocol-fixture.json',JSON.stringify({plan,batch}));
console.log('Fixture sintético del protocolo generado.');
