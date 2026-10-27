import { Decimal } from 'decimal.js';

// Nombres constatados en el resultset real. No se combinan ni se recalculan costos.
export const FLOAT_COST_COLUMNS = ['CostoMPSoles','CostoMPUS$','CostoMP1Soles','CostoMP1US$', 'CostoHASSoles','CostoHASUS$','CostoHAS1Soles','CostoHAS1US$'] as const;
export function isFloatCost(name: string): boolean { return (FLOAT_COST_COLUMNS as readonly string[]).includes(name); }
export function validateFloatCost(value: number): void {
  // Representación decimal canónica del mismo IEEE754; no inventar centavos ni redondear.
  const plain = new Decimal(value.toString()).toFixed();
  const [integer, fraction = ''] = plain.replace(/^-/, '').split('.');
  if (integer!.replace(/^0+/, '').length > 35 || fraction.length > 30) {
    throw new Error('Un costo FLOAT no cabe sin redondeo en DECIMAL(65,30); se bloquea el envío.');
  }
}
