/** Formatting helpers shared across the console. */

export const money = (value, currency = 'USD', options = {}) => {
  const amount = Number(value || 0);
  try {
    return new Intl.NumberFormat('en-US', {
      style: 'currency',
      currency,
      maximumFractionDigits: options.cents || amount % 1 !== 0 ? 2 : 0,
    }).format(amount);
  } catch {
    return `${currency} ${amount.toFixed(2)}`;
  }
};

export const compact = (value) => {
  const amount = Number(value || 0);
  return new Intl.NumberFormat('en-US', { notation: 'compact', maximumFractionDigits: 1 }).format(amount);
};

export const number = (value) => new Intl.NumberFormat('en-US').format(Number(value || 0));

export const percent = (value, digits = 1) => `${Number(value || 0).toFixed(digits)}%`;

/** "2026-09-19 07:38:14" -> "19 Sep 2026" */
export const date = (value) => {
  if (!value) return '—';
  const parsed = new Date(String(value).replace(' ', 'T'));
  if (Number.isNaN(parsed.getTime())) return String(value);
  return parsed.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
};

export const dateTime = (value) => {
  if (!value) return '—';
  const parsed = new Date(String(value).replace(' ', 'T'));
  if (Number.isNaN(parsed.getTime())) return String(value);
  return parsed.toLocaleString('en-GB', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' });
};

export const relative = (value) => {
  if (!value) return '—';
  const parsed = new Date(String(value).replace(' ', 'T'));
  if (Number.isNaN(parsed.getTime())) return String(value);
  const seconds = Math.round((Date.now() - parsed.getTime()) / 1000);
  if (seconds < 60) return 'just now';
  const minutes = Math.round(seconds / 60);
  if (minutes < 60) return `${minutes}m ago`;
  const hours = Math.round(minutes / 60);
  if (hours < 24) return `${hours}h ago`;
  const days = Math.round(hours / 24);
  if (days < 31) return `${days}d ago`;
  const months = Math.round(days / 30);
  if (months < 12) return `${months}mo ago`;
  return `${Math.round(months / 12)}y ago`;
};

export const bytes = (value) => {
  const size = Number(value || 0);
  if (size < 1024) return `${size} B`;
  const units = ['KB', 'MB', 'GB', 'TB'];
  let index = -1;
  let current = size;
  do {
    current /= 1024;
    index += 1;
  } while (current >= 1024 && index < units.length - 1);
  return `${current.toFixed(current < 10 ? 1 : 0)} ${units[index]}`;
};

export const titleCase = (value) =>
  String(value || '')
    .replace(/[_-]+/g, ' ')
    .replace(/\b\w/g, (character) => character.toUpperCase());

export const initials = (name) =>
  String(name || '?')
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0].toUpperCase())
    .join('');

export const STATUS_TONES = {
  active: 'green',
  trial: 'indigo',
  trialing: 'indigo',
  past_due: 'amber',
  suspended: 'red',
  cancelled: 'slate',
  paused: 'slate',
  paid: 'green',
  open: 'indigo',
  void: 'slate',
  draft: 'slate',
};

export const toneForStatus = (status) => STATUS_TONES[String(status || '').toLowerCase()] || 'slate';

/** Plan limits reach us as numbers, or `null`/`0`/`-1` for "not metered". */
export const limitLabel = (value) => {
  if (value === null || value === undefined || value === -1) return 'Unlimited';
  if (value === 0) return 'Not metered';
  return number(value);
};
