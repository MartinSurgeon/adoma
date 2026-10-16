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

/** A moment in the past, the way people say it: "Today, 9:14 am", "Yesterday", "3 days ago", "12 Sept 2026". */
export function sinceWhen(iso, never = 'Never') {
    if (!iso) return never;
    const at = new Date(iso);
    if (Number.isNaN(at.getTime())) return never;

    const startOf = (d) => new Date(d.getFullYear(), d.getMonth(), d.getDate()).getTime();
    const days = Math.round((startOf(new Date()) - startOf(at)) / 86400000);

    if (days <= 0) return `Today, ${at.toLocaleTimeString('en-GB', { hour: 'numeric', minute: '2-digit', hour12: true })}`;
    if (days === 1) return 'Yesterday';
    if (days < 7) return `${days} days ago`;
    return at.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' });
}

export function longDay(iso) {
    if (!iso) return '—';
    const date = new Date(`${iso}T00:00:00`);
    return Number.isNaN(date.getTime()) ? iso : date.toLocaleDateString('en-GB', { weekday: 'short', day: 'numeric', month: 'long', year: 'numeric' });
}
