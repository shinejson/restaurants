import { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { api } from '../lib/api';
import { date, money, titleCase } from '../lib/format';
import { useSession } from '../lib/session';
import { Badge, Button, Card, Input, Select, Stat, Table, Toasts, useToasts } from '../components/ui';

const FILTERS = ['all', 'trialing', 'active', 'past_due', 'paused', 'cancelled'];

export default function Subscriptions() {
  const { can } = useSession();
  const { toasts, push, dismiss } = useToasts();
  const [rows, setRows] = useState([]);
  const [meta, setMeta] = useState({});
  const [status, setStatus] = useState('all');
  const [search, setSearch] = useState('');
  const [plans, setPlans] = useState([]);
  const [busy, setBusy] = useState(null);

  const load = useCallback(() => {
    api
      .get('/subscriptions', { status, search })
      .then((response) => {
        setRows(response.data || []);
        setMeta(response.meta || {});
      })
      .catch((failure) => push('Could not load subscriptions', failure.message, 'error'));
  }, [status, search, push]);

  useEffect(() => {
    load();
    api.get('/plans').then((response) => setPlans(response.data || [])).catch(() => {});
  }, [load]);

  const changePlan = async (row, planId) => {
    setBusy(row.id);
    try {
      await api.post(`/subscriptions/${row.tenant_id}/change-plan`, { plan_id: Number(planId) });
      push('Plan changed', `${row.tenant_name} moved to ${plans.find((plan) => plan.id === Number(planId))?.name}`, 'success');
      load();
    } catch (failure) {
      push('Could not change the plan', failure.message, 'error');
    } finally {
      setBusy(null);
    }
  };

  const lifecycle = async (row, action) => {
    setBusy(row.id);
    try {
      await api.post(`/subscriptions/${row.tenant_id}/${action}`, action === 'cancel' ? { immediately: false, reason: 'Cancelled in the console' } : {});
      push('Updated', `${row.tenant_name}: ${action}`, 'success');
      load();
    } catch (failure) {
      push('That did not work', failure.message, 'error');
    } finally {
      setBusy(null);
    }
  };

  const byStatus = meta.by_status || {};
  const mrr = rows.filter((row) => ['active', 'past_due'].includes(row.status)).reduce((total, row) => total + Number(row.amount || 0), 0);

  return (
    <div className="col gap-18">
      <Toasts toasts={toasts} dismiss={dismiss} />

      <header className="page-head">
        <div>
          <h1>Subscriptions</h1>
          <p className="muted">Recurring revenue, cycles and lifecycle actions</p>
        </div>
      </header>

      <div className="stat-grid">
        <Stat label="Active" value={byStatus.active || 0} tone="green" />
        <Stat label="Trialling" value={byStatus.trialing || 0} tone="indigo" />
        <Stat label="Past due" value={byStatus.past_due || 0} tone="amber" />
        <Stat label="MRR on this page" value={money(mrr)} tone="violet" />
      </div>

      <div className="filter-row">
        <div className="segmented">
          {FILTERS.map((value) => (
            <button key={value} className={status === value ? 'active' : ''} onClick={() => setStatus(value)}>
              {titleCase(value)}
            </button>
          ))}
        </div>
        <div className="grow" />
        <div className="search inline">
          <span>⌕</span>
          <Input value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Restaurant" />
        </div>
      </div>

      <Card padded={false}>
        <Table
          rows={rows}
          empty="No subscriptions match"
          columns={[
            { key: 'tenant_name', label: 'Restaurant', render: (row) => (
              <div className="cell-stack">
                <Link className="link" to={`/tenants/${row.tenant_id}`}>{row.tenant_name}</Link>
                <span className="muted tiny">{row.tenant_slug}</span>
              </div>
            ) },
            { key: 'plan', label: 'Plan', render: (row) => (
              can('billing.manage') ? (
                <Select value={row.plan_id} disabled={busy === row.id} onChange={(event) => changePlan(row, event.target.value)} className="input-inline">
                  {plans.map((plan) => (
                    <option key={plan.id} value={plan.id}>{plan.name}</option>
                  ))}
                </Select>
              ) : (
                <Badge tone="indigo">{row.plan_name}</Badge>
              )
            ) },
            { key: 'status', label: 'Status', render: (row) => (
              <Badge tone={row.status === 'active' ? 'green' : row.status === 'past_due' ? 'amber' : row.status === 'trialing' ? 'indigo' : 'slate'} dot>
                {titleCase(row.status)}
              </Badge>
            ) },
            { key: 'billing_cycle', label: 'Cycle', render: (row) => <span className="muted">{titleCase(row.billing_cycle)}</span> },
            { key: 'amount', label: 'Amount', align: 'right', render: (row) => money(row.amount, row.currency) },
            { key: 'current_period_end', label: 'Renews', align: 'right', render: (row) => (
              <div className="cell-stack end">
                <span>{date(row.current_period_end)}</span>
                {row.days_remaining !== null && row.days_remaining !== undefined && (
                  <span className="muted tiny">{row.days_remaining < 0 ? `${Math.abs(row.days_remaining)}d overdue` : `${row.days_remaining}d left`}</span>
                )}
              </div>
            ) },
            {
              key: 'actions',
              label: '',
              align: 'right',
              render: (row) =>
                can('billing.manage') ? (
                  <div className="row gap-6 end">
                    {['cancelled', 'paused', 'past_due'].includes(row.status) ? (
                      <Button size="xs" variant="secondary" loading={busy === row.id} onClick={() => lifecycle(row, 'resume')}>Resume</Button>
                    ) : (
                      <Button size="xs" variant="ghost" loading={busy === row.id} onClick={() => lifecycle(row, 'cancel')}>Cancel</Button>
                    )}
                  </div>
                ) : null,
            },
          ]}
        />
      </Card>
    </div>
  );
}
