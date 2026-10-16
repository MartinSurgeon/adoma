/**
 * Numbers and dates as people read them on the screens, for the checks before saving.
 * The server formats what is stored; these only show what has been typed.
 */
const asNumber = (value) => Number(String(value ?? '').replace(/,/g, ''));

export function twoDecimals(value) {
    const number = asNumber(value);
    if (value === '' || value === null || value === undefined || !Number.isFinite(number)) return '—';
    return number.toLocaleString('en-GB', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

export const grams = (value) => (twoDecimals(value) === '—' ? '—' : `${twoDecimals(value)} g`);

export const cedis = (value) => (twoDecimals(value) === '—' ? '—' : `GH₵ ${twoDecimals(value)}`);

export function longDay(iso) {
    if (!iso) return '—';
    const date = new Date(`${iso}T00:00:00`);
    return Number.isNaN(date.getTime()) ? iso : date.toLocaleDateString('en-GB', { weekday: 'short', day: 'numeric', month: 'long', year: 'numeric' });
}
