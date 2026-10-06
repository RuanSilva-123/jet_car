/** Data local (YYYY-MM-DD) — toISOString usaria UTC e viraria o dia à noite. */
export function localDate(date: Date): string {
  return new Date(date.getTime() - date.getTimezoneOffset() * 60000).toISOString().slice(0, 10);
}

export function startOfMonth(date = new Date()): string {
  return localDate(new Date(date.getFullYear(), date.getMonth(), 1));
}

export function endOfMonth(date = new Date()): string {
  return localDate(new Date(date.getFullYear(), date.getMonth() + 1, 0));
}

/** Períodos rápidos dos filtros de data. */
export function presetRange(preset: 'month' | 'last-month' | 'last-30' | 'year'): { from: string; to: string } {
  const today = new Date();
  switch (preset) {
    case 'month':
      return { from: startOfMonth(today), to: localDate(today) };
    case 'last-month': {
      const last = new Date(today.getFullYear(), today.getMonth() - 1, 1);
      return { from: startOfMonth(last), to: endOfMonth(last) };
    }
    case 'last-30':
      return { from: localDate(new Date(today.getTime() - 29 * 86400000)), to: localDate(today) };
    case 'year':
      return { from: localDate(new Date(today.getFullYear(), 0, 1)), to: localDate(today) };
  }
}
