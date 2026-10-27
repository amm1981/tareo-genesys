import { parseArgs } from 'node:util';
import { z } from 'zod';

export const periodSchema = z.object({
  anio: z.coerce.number().int().min(1900).max(9999),
  mes: z.coerce.number().int().min(1).max(12).optional(),
});
export type Period = z.infer<typeof periodSchema>;
export function argumentsFrom(argv: string[]) {
  const { values } = parseArgs({ args: argv, options: {
    anio: { type: 'string' }, mes: { type: 'string' },
    'dry-run': { type: 'boolean', default: false },
    'check-connection': { type: 'boolean', default: false },
    'inspect-schema': { type: 'boolean', default: false },
    resume: { type: 'string' }, status: { type: 'string' },
    'schema-file': { type: 'string' },
  }, strict: true });
  if (values.resume && !z.uuid().safeParse(values.resume).success) throw new Error('UUID de reanudación inválido.');
  if (values.status && !z.uuid().safeParse(values.status).success) throw new Error('UUID de estado inválido.');
  if ((values.resume || values.status) && (values.anio || values.mes || values['dry-run'] || values['inspect-schema'])) throw new Error('No combine resume/status con extracción.');
  return { ...values, period: values['check-connection'] || values.resume || values.status ? undefined : periodSchema.parse({ anio: values.anio, mes: values.mes }) };
}
