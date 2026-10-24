import { describe, it, expect } from 'vitest';
import { describeSourceError } from '../src/utils/source-error.js';

describe('diagnóstico seguro de SQL Server', () => {
  it('identifica la causa TLS anidada y omite mensajes confidenciales', () => {
    const e = { code: 'ESOCKET', message: 'secret-user fixture-password', originalError: { code: 'ESOCKET', cause: { code: 'DEPTH_ZERO_SELF_SIGNED_CERT' } } };
    const result = describeSourceError(e)!;
    expect(result).toContain('certificado autofirmado');
    expect(result).toContain('SQL_CA_FILE');
    expect(result).not.toContain('secret-user');
    expect(result).not.toContain('fixture-password');
  });
  it('distingue autenticación, nombre TLS, caducidad y red', () => {
    for (const [code, text] of [['ELOGIN','autenticación'], ['ERR_TLS_CERT_ALTNAME_INVALID','nombre del certificado'], ['CERT_HAS_EXPIRED','vencido'], ['ETIMEOUT','tiempo de espera'], ['ECONNREFUSED','rechazó la conexión'], ['ENOTFOUND','resolver el nombre']]) {
      expect(describeSourceError({code})?.toLowerCase()).toContain(text!.toLowerCase());
    }
  });
  it('tolera ciclos y errores desconocidos sin exponer valores', () => {
    const e: Record<string,unknown> = {code:'ESOCKET'}; e.cause=e;
    expect(describeSourceError(e)).toContain('ESOCKET');
    expect(describeSourceError(new Error('secret fixture'))).toBeUndefined();
  });
});
