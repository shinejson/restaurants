import { useCallback, useEffect, useRef, useState } from 'react';
import { initials, toneForStatus, titleCase } from '../lib/format';

export function Card({ title, subtitle, actions, children, padded = true, className = '' }) {
  return (
    <section className={`card ${className}`}>
      {(title || actions) && (
        <header className="card-head">
          <div>
            {title && <h2>{title}</h2>}
            {subtitle && <p className="muted small">{subtitle}</p>}
          </div>
          {actions && <div className="row gap-8">{actions}</div>}
        </header>
      )}
      <div className={padded ? 'card-body' : ''}>{children}</div>
    </section>
  );
}

export function Badge({ tone = 'slate', children, dot = false }) {
  return (
    <span className={`badge badge-${tone}`}>
      {dot && <i className="dot" />}
      {children}
    </span>
  );
}

export const StatusBadge = ({ status }) => (
  <Badge tone={toneForStatus(status)} dot>
    {titleCase(status)}
  </Badge>
);

export function Button({
  variant = 'secondary',
  size = 'md',
  loading = false,
  icon,
  children,
  className = '',
  ...rest
}) {
  return (
    <button className={`btn btn-${variant} btn-${size} ${className}`} disabled={loading || rest.disabled} {...rest}>
      {loading ? <span className="spinner" /> : icon ? <span className="btn-icon">{icon}</span> : null}
      {children}
    </button>
  );
}

export function Field({ label, hint, error, children, className = '' }) {
  return (
    <label className={`field ${className}`}>
      {label && <span className="field-label">{label}</span>}
      {children}
      {hint && !error && <span className="field-hint">{hint}</span>}
      {error && <span className="field-error">{Array.isArray(error) ? error[0] : error}</span>}
    </label>
  );
}

export const Input = (props) => <input className="input" {...props} />;
export const Select = ({ children, ...rest }) => (
  <select className="input" {...rest}>
    {children}
  </select>
);
export const Textarea = (props) => <textarea className="input" rows={3} {...props} />;

export function Avatar({ name, tone, size = 34 }) {
  return (
    <span
      className="avatar"
      style={{ width: size, height: size, background: tone || 'linear-gradient(135deg,#6366f1,#8b5cf6)', fontSize: size * 0.38 }}
    >
      {initials(name)}
    </span>
  );
}

export function Progress({ value, max, tone = 'indigo', label = true }) {
  const unbounded = max === null || max === undefined || max === -1;
  const ratio = unbounded ? 0 : max === 0 ? 0 : Math.min(1, Number(value || 0) / Number(max));
  const danger = !unbounded && ratio >= 0.9;
  const warn = !unbounded && ratio >= 0.7 && !danger;
  const color = danger ? 'var(--red)' : warn ? 'var(--amber)' : `var(--${tone})`;
  return (
    <div className="progress-wrap">
      <div className="progress">
        <span style={{ width: unbounded ? '100%' : `${Math.max(ratio * 100, value > 0 ? 4 : 0)}%`, background: unbounded ? 'var(--slate-600)' : color, opacity: unbounded ? 0.35 : 1 }} />
      </div>
      {label && (
        <span className="progress-label">
          {unbounded ? 'Unlimited' : `${Number(value || 0).toLocaleString()} / ${Number(max).toLocaleString()}`}
        </span>
      )}
    </div>
  );
}

export function Table({ columns, rows, empty = 'Nothing here yet', rowKey = (row) => row.id, onRowClick, loading }) {
  if (loading) return <div className="table-state"><span className="spinner" /> Loading…</div>;
  if (!rows?.length) return <div className="table-state">{empty}</div>;

  return (
    <div className="table-scroll">
      <table className="table">
        <thead>
          <tr>
            {columns.map((column) => (
              <th key={column.key} style={{ width: column.width, textAlign: column.align }}>
                {column.label}
              </th>
            ))}
          </tr>
        </thead>
        <tbody>
          {rows.map((row) => (
            <tr
              key={rowKey(row)}
              onClick={onRowClick ? () => onRowClick(row) : undefined}
              className={onRowClick ? 'clickable' : ''}
            >
              {columns.map((column) => (
                <td key={column.key} style={{ textAlign: column.align }}>
                  {column.render ? column.render(row) : row[column.key]}
                </td>
              ))}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

export function Modal({ open, title, subtitle, onClose, children, footer, width = 560 }) {
  const ref = useRef(null);

  useEffect(() => {
    if (!open) return undefined;
    const onKey = (event) => {
      if (event.key === 'Escape') onClose?.();
    };
    document.addEventListener('keydown', onKey);
    document.body.style.overflow = 'hidden';
    return () => {
      document.removeEventListener('keydown', onKey);
      document.body.style.overflow = '';
    };
  }, [open, onClose]);

  if (!open) return null;

  return (
    <div className="modal-backdrop" onMouseDown={(event) => event.target === event.currentTarget && onClose?.()}>
      <div className="modal" style={{ maxWidth: width }} ref={ref}>
        <header className="modal-head">
          <div>
            <h3>{title}</h3>
            {subtitle && <p className="muted small">{subtitle}</p>}
          </div>
          <button className="icon-btn" onClick={onClose} aria-label="Close">
            ✕
          </button>
        </header>
        <div className="modal-body">{children}</div>
        {footer && <footer className="modal-foot">{footer}</footer>}
      </div>
    </div>
  );
}

export function Toasts({ toasts, dismiss }) {
  return (
    <div className="toasts">
      {toasts.map((toast) => (
        <div key={toast.id} className={`toast toast-${toast.tone || 'info'}`} onClick={() => dismiss(toast.id)}>
          <strong>{toast.title}</strong>
          {toast.body && <span>{toast.body}</span>}
        </div>
      ))}
    </div>
  );
}

let toastId = 0;
export function useToasts() {
  const [toasts, setToasts] = useState([]);

  // Both callbacks keep a stable identity: pages include them in the deps of
  // their data loaders, so a new function per render would loop forever.
  const push = useCallback((title, body, tone = 'info') => {
    const id = ++toastId;
    setToasts((current) => [...current, { id, title, body, tone }]);
    setTimeout(() => setToasts((current) => current.filter((toast) => toast.id !== id)), 5200);
  }, []);

  const dismiss = useCallback((id) => setToasts((current) => current.filter((toast) => toast.id !== id)), []);

  return { toasts, push, dismiss };
}

export const Spinner = ({ label }) => (
  <div className="page-loading">
    <span className="spinner big" />
    {label && <p className="muted">{label}</p>}
  </div>
);

export const Empty = ({ title, body, action }) => (
  <div className="empty">
    <h3>{title}</h3>
    {body && <p className="muted">{body}</p>}
    {action}
  </div>
);

export function Stat({ label, value, hint, tone = 'indigo', trend }) {
  const trendUp = Number(trend) > 0;
  const trendDown = Number(trend) < 0;
  return (
    <div className="stat">
      <span className="stat-label">{label}</span>
      <span className="stat-value" style={{ color: `var(--${tone})` }}>
        {value}
      </span>
      <span className="stat-foot">
        {trend !== undefined && trend !== null && Number.isFinite(Number(trend)) && (
          <span className={`trend ${trendUp ? 'up' : trendDown ? 'down' : ''}`}>
            {trendUp ? '▲' : trendDown ? '▼' : '•'} {Math.abs(Number(trend)).toFixed(1)}%
          </span>
        )}
        {hint && <span className="muted small">{hint}</span>}
      </span>
    </div>
  );
}

/** Dependency-free sparkline / bar chart. */
export function BarChart({ data, xKey, series, height = 180 }) {
  const [hover, setHover] = useState(null);
  if (!data?.length) return <div className="table-state">No data yet</div>;

  const max = Math.max(
    1,
    ...data.flatMap((row) => series.map((entry) => Number(row[entry.key] || 0))),
  );

  return (
    <div className="chart">
      <div className="chart-plot" style={{ height }}>
        {data.map((row, index) => (
          <div
            key={row[xKey] ?? index}
            className="chart-col"
            onMouseEnter={() => setHover(index)}
            onMouseLeave={() => setHover(null)}
          >
            <div className="chart-bars">
              {series.map((entry) => (
                <span
                  key={entry.key}
                  className="chart-bar"
                  style={{
                    height: `${Math.max((Number(row[entry.key] || 0) / max) * 100, 2)}%`,
                    background: entry.color,
                    opacity: hover === null || hover === index ? 1 : 0.45,
                  }}
                />
              ))}
            </div>
            <span className="chart-label">{row.label || String(row[xKey]).slice(5)}</span>
            {hover === index && (
              <div className="chart-tip">
                <strong>{row.label}</strong>
                {series.map((entry) => (
                  <span key={entry.key}>
                    <i style={{ background: entry.color }} /> {entry.label}: <b>{Number(row[entry.key] || 0).toLocaleString()}</b>
                  </span>
                ))}
              </div>
            )}
          </div>
        ))}
      </div>
      <div className="chart-legend">
        {series.map((entry) => (
          <span key={entry.key}>
            <i style={{ background: entry.color }} /> {entry.label}
          </span>
        ))}
      </div>
    </div>
  );
}
