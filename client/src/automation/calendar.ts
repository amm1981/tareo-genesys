export interface Month { anio: number; mes: number }
export function key(period: Month): string { return `${period.anio}-${String(period.mes).padStart(2, '0')}`; }
export function limaDay(now: Date): string {
  if (!Number.isFinite(now.getTime())) throw new Error('INVALID_CLOCK');
  const parts = new Intl.DateTimeFormat('en-CA', { timeZone: 'America/Lima', year: 'numeric', month: '2-digit', day: '2-digit' }).formatToParts(now);
  const value = (type: string) => parts.find(p => p.type === type)!.value;
  return `${value('year')}-${value('month')}-${value('day')}`;
}
export function monthsBetween(first: string, last: string): Month[] {
  const parse = (text: string) => {
    if (!/^\d{4}-(0[1-9]|1[0-2])$/.test(text)) throw new Error('INVALID_MONTH');
    const [year, month] = text.split('-').map(Number) as [number, number];
    return year * 12 + month - 1;
  };
  const start = parse(first), end = parse(last);
  if (start > end) return [];
  return Array.from({ length: end - start + 1 }, (_, i) => ({ anio: Math.floor((start + i) / 12), mes: (start + i) % 12 + 1 }));
}
export function schedule(mode: 'backfill' | 'daily', now = new Date()) {
  const day = limaDay(now);
  // Ventana movil de 30 x 24 horas; seleccionar los meses usando ambos extremos en Lima.
  const start = new Date(now.getTime() - 30 * 86400000);
  const firstDay = limaDay(start);
  return { day, window_start: firstDay, window_end: day, window_start_at: start.toISOString(), window_end_at: now.toISOString(),
    periods: monthsBetween(mode === 'backfill' ? '2023-01' : firstDay.slice(0, 7), day.slice(0, 7)) };
}
