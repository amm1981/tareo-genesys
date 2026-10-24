// Solo categorías conocidas. Nunca devolver mensajes originales, SQL ni valores del origen.
export function describeSourceError(error: unknown): string | undefined {
  const codes = new Set<string>();
  const hints = new Set<string>();
  const seen = new Set<unknown>();
  const visit = (value: unknown, depth = 0) => {
    if (!value || typeof value !== 'object' || seen.has(value) || depth > 8) return;
    seen.add(value);
    const item = value as Record<string, unknown>;
    if (typeof item.code === 'string') codes.add(item.code);
    const message = typeof item.message === 'string' ? item.message : '';
    if (/self.signed|unable to verify|certificate chain|issuer certificate/i.test(message)) hints.add('untrusted');
    if (/login failed|authentication failed/i.test(message)) hints.add('login');
    for (const key of ['cause', 'originalError']) visit(item[key], depth + 1);
    for (const key of ['errors', 'precedingErrors']) if (Array.isArray(item[key])) for (const nested of item[key]) visit(nested, depth + 1);
  };
  visit(error);
  if (['DEPTH_ZERO_SELF_SIGNED_CERT','SELF_SIGNED_CERT_IN_CHAIN','UNABLE_TO_VERIFY_LEAF_SIGNATURE','UNABLE_TO_GET_ISSUER_CERT_LOCALLY'].some(c => codes.has(c)) || hints.has('untrusted')) {
    return 'SQL Server presenta un certificado autofirmado o una CA no confiable. Configure SQL_CA_FILE y SQL_TLS_SERVER_NAME. La excepción temporal SQL_TRUST_SERVER_CERTIFICATE=true requiere autorización explícita y conserva el cifrado, pero omite verificar la identidad del servidor.';
  }
  if (codes.has('ERR_TLS_CERT_ALTNAME_INVALID')) return 'El nombre del certificado SQL Server no coincide. Configure SQL_TLS_SERVER_NAME con el nombre DNS válido del certificado.';
  if (codes.has('CERT_HAS_EXPIRED') || codes.has('CERT_NOT_YET_VALID')) return 'El certificado SQL Server está vencido o todavía no es válido. Revise su vigencia y la fecha del equipo.';
  if (codes.has('ELOGIN') || hints.has('login')) return 'SQL Server rechazó la autenticación. Revise SQL_AUTH_MODE y las credenciales de client/.env; no las comparta en logs ni chats.';
  if (codes.has('ETIMEOUT') || codes.has('ETIMEDOUT')) return 'SQL Server agotó el tiempo de espera. Revise LAN/VPN, host, puerto y los timeouts de conexión/ejecución.';
  if (codes.has('ECONNREFUSED')) return 'El host rechazó la conexión SQL Server. Confirme el puerto y que el servicio esté escuchando.';
  if (codes.has('ENOTFOUND') || codes.has('EAI_AGAIN')) return 'No se pudo resolver el nombre SQL_SERVER. Revise el DNS o la conexión VPN.';
  if (codes.has('ESOCKET') || codes.has('ECONNRESET')) return 'Falló el canal de conexión con SQL Server (ESOCKET). Revise red, TLS y el certificado; ejecute npm run diagnose:connection para obtener categorías técnicas sin credenciales.';
  return undefined;
}
