// Única fuente permitida para nuevas extracciones. Sin selección ni fallback.
export const CURRENT_PROCEDURE = 'dbo.SPWConsumoMes' as const;
export const CURRENT_SOURCE_ID = 'spwconsumomes-v1' as const;
export const CURRENT_COLUMN_COUNT = 38;
export const HAS_COST_COLUMNS = ['CostoHASSoles', 'CostoHASUS$', 'CostoHAS1Soles', 'CostoHAS1US$'] as const;
export const LEGACY_BLOCKED_UUID = '237818a9-4870-4cfa-92e5-f5a2a448dbb3';
export const CURRENT_SCHEMA_FILE = 'schema.spwconsumomes.json';
export function assertExtractionColumns(manifest: { columns: Array<{ name: string }> }) {
  if (manifest.columns.length !== CURRENT_COLUMN_COUNT || HAS_COST_COLUMNS.some(name => !manifest.columns.some(c => c.name === name))) throw new Error('CURRENT_SCHEMA_COLUMN_MISMATCH');
}
