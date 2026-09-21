import { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { api } from '../lib/api';
import { date, money, number, titleCase } from '../lib/format';
import { useSession } from '../lib/session';
import { Badge, Button, Card, Empty, Input, Select, Stat, Table, Toasts, useToasts } from '../components/ui';

const FILTERS = ['all', 'paid', 'open', 'past_due', 'void'];

export default function Invoices() {
  const { can } = useSession();
  const { toasts, push, dismiss } = useToasts();
  const [rows, setRows] = useState([]);
  const [meta, setMeta] = useState({});
  const [status, setStatus] = useState('all');
  const [search, setSearch] = useState('');
  const [page, setPage] = useState(1);
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(null);

  const load = useCallback(() => {
    setLoading(true);
    api
      .get('/invoices', { status, search, page, per_page: 25 })
      .then((response) => {
        setRows(response.data || []);
        setMeta(response.meta || {});
      })
      .catch((failure) => push('Could not load invoices', failure.message, 'error'))
      .finally(() => setLoading(false));
  }, [status, search, page, push]);

  useEffect(() => {
    load();
  }, [load]);

  const summary = meta.summary || {};

  const act = async (invoice, action) => {
    setBusy(invoice.id);
    try {
      await api.post(`/invoices/${invoice.id}/${action}`, action === 'void' ? { reason: 'Voided in the console' } : {});
      push('Done', `Invoice ${invoice.number} ${action === 'pay' ? 'marked paid' : 'voided'}`, 'success');
      load();
    } catch (failure) {
      push('That did not work', failure.message, 'error');
    } finally {
      setBusy(null);
    }
  };

  return (
    <div className="col gap-18">
      <Toasts toasts={toasts} dismiss={dismiss} />

      <header className="page-head">
        <div>
          <h1>Invoices</h1>
          <p className="muted">Every subscription charge across the platform</p>
        </div>
        {can('billing.manage') && (
          <Button variant="secondary" onClick={() => api.post('/billing/run-dunning', {}).then((response) => { push('Dunning complete', `${response.data.reminded || 0} reminder(s), ${response.data.suspended || 0} suspension(s)`, 'success'); setTimeout(load, 400); }).catch((failure) => push('Dunning failed', failure.message, 'error'))}>
            Run dunning cycle
          </Button>
        )}
      </header>

      <div className="stat-grid">
        <Stat label="Collected" value={money(summary.paid)} tone="green" />
        <Stat label="Open" value={money(summary.open)} tone="indigo" />
        <Stat label="Past due" value={money(summary.past_due)} tone="amber" hint={`${number(summary.counts?.past_due || 0)} invoice(s)`} />
        <Stat label="Due soon" value={money(summary.due_soon)} tone="violet" />
      </div>

      <div className="filter-row">
        <div className="segmented">
          {FILTERS.map((value) => (
            <button key={value} className={status === value ? 'active' : ''} onClick={() => { setStatus(value); setPage(1); }}>
              {titleCase(value)}
            </button>
          ))}
        </div>
        <div className="grow" />
        <form className="search inline" onSubmit={(event) => { event.preventDefault(); setPage(1); }}>
          <span>⌕</span>
          <input value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Invoice number or restaurant" />
        </form>
      </div>

      <Card padded={false}>
        <Table
          loading={loading && !rows.length}
          rows={rows}
          empty="No invoices match those filters"
          columns={[
            { key: 'number', label: 'Invoice', render: (row) => (
              <div className="cell-stack">
                <code>{row.number}</code>
                {row.tenant_name && <Link className="muted tiny link" to={`/tenants/${row.tenant_id}`}>{row.tenant_name}</Link>}
              </div>
            ) },
            { key: 'status', label: 'Status', render: (row) => (
              <Badge tone={row.status === 'paid' ? 'green' : row.status === 'past_due' ? 'amber' : row.status === 'void' ? 'slate' : 'indigo'}>
                {titleCase(row.status)}
              </Badge>
            ) },
            { key: 'issued_at', label: 'Issued', render: (row) => <span className="muted">{date(row.issued_at)}</span> },
            { key: 'due_at', label: 'Due', render: (row) => <span className={row.status !== 'paid' && new Date(String(row.due_at).replace(' ', 'T')) < new Date() ? 'text-amber' : 'muted'}>{date(row.due_at)}</span> },
            { key: 'total', label: 'Total', align: 'right', render: (row) => money(row.total, row.currency) },
            { key: 'amount_paid', label: 'Paid', align: 'right', render: (row) => money(row.amount_paid, row.currency) },
            {
              key: 'actions',
              label: '',
              align: 'right',
              render: (row) =>
                can('billing.manage') && !['paid', 'void'].includes(row.status) ? (
                  <div className="row gap-6 end">
                    <Button size="xs" variant="secondary" loading={busy === row.id} onClick={() => act(row, 'pay')}>Pay</Button>
                    <Button size="xs" variant="ghost" onClick={() => act(row, 'void')}>Void</Button>
                  </div>
                ) : null,
            },
          ]}
        />
      </Card>

      {meta.last_page > 1 && (
        <div className="pager">
          <Button size="sm" disabled={page <= 1} onClick={() => setPage(page - 1)}>← Previous</Button>
          <span className="muted small">Page {meta.page} of {meta.last_page} · {number(meta.total)} invoices</span>
          <Button size="sm" disabled={page >= meta.last_page} onClick={() => setPage(page + 1)}>Next →</Button>
        </div>
      )}
    </div>
  );
}
