import { describe, it, expect } from 'vitest';
import { isFloatCost, validateFloatCost } from '../src/utils/float-cost.js';

describe('costos FLOAT reales: validación sin redondeos ni cálculos', () => {
  it('admite valores canónicos en DECIMAL(65,30), incluidos negativos y exponentes', () => {
    for (const value of [0, -0, 0.30000000000000004, -123.45678901234567, 1e-30, 1234567.89]) expect(() => validateFloatCost(value)).not.toThrow();
    expect(isFloatCost('CostoMPUS$')).toBe(true);
    expect(isFloatCost('Cantidad')).toBe(false);
  });
  it('rechaza valores que requerirían redondeo o excederían el entero', () => {
    for (const value of [1e-31, 1e35]) expect(() => validateFloatCost(value)).toThrow('sin redondeo');
  });
});
