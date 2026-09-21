import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { api } from '../lib/api';
import { compact, money, number, relative, titleCase } from '../lib/format';
import { Badge, BarChart, Card, Empty, Stat, StatusBadge, Table } from '../components/ui';

export default function Dashboard() {
  const [data, setData] = useState(null);
  const [error, setError] = useState(null);

  useEffect(() => {
    api
      .get('/overview')
      .then((response) => setData(response.data))
      .catch((failure) => setError(failure.message));
  }, []);

  if (error) return <Empty title="Could not load the overview" body={error} />;
  if (!data) return <div className="skeleton-grid">{[...Array(8)].map((_, index) => <div key={index} className="skeleton" />)}</div>;

  const { metrics, series, needs_attention: attention, leaderboard, activity, plans } = data;
  const statuses = metrics.tenants.by_status || {};

  return (
    <div className="col gap-20">
      <header className="page-head">
        <div>
          <h1>Platform overview</h1>
          <p className="muted">Every restaurant, subscription and support signal in one place.</p>
        </div>
        <div className="row gap-8">
          <Link className="btn btn-primary btn-md" to="/tenants?new=1">
            + New restaurant
          </Link>
        </div>
      </header>

      <div className="stat-grid">
        <Stat label="MRR" value={money(metrics.revenue.mrr, metrics.revenue.currency)} hint={`ARR ${money(metrics.revenue.arr, metrics.revenue.currency)}`} trend={metrics.revenue.collected_change} />
        <Stat label="Active tenants" value={number((statuses.active || 0) + (statuses.trial || 0))} hint={`${statuses.trial || 0} on trial · ${statuses.past_due || 0} past due`} tone="green" />
        <Stat label="Collected this month" value={money(metrics.revenue.collected_this_month, metrics.revenue.currency)} hint={`${money(metrics.revenue.outstanding, metrics.revenue.currency)} outstanding`} tone="violet" trend={metrics.revenue.collected_change} />
        <Stat label="Orders this month" value={compact(metrics.usage.orders_this_month)} hint={`${compact(metrics.usage.orders_last_month)} last month`} tone="cyan" trend={metrics.usage.orders_change} />
      </div>

      <div className="two-col">
        <Card title="Growth" subtitle="Signups, churn and revenue across the last 12 months">
          <BarChart
            data={series}
            xKey="month"
            series={[
              { key: 'revenue', label: 'Revenue', color: '#818cf8' },
              { key: 'signups', label: 'Signups', color: '#34d399' },
              { key: 'churn', label: 'Churn', color: '#f87171' },
            ]}
          />
        </Card>

        <Card title="Needs attention" subtitle={`${attention.length} restaurant(s) flagged`}>
          {attention.length === 0 ? (
            <Empty title="All clear" body="No overdue payments, expired trials or dormant accounts." />
          ) : (
            <div className="stack">
              {attention.map((tenant) => (
                <Link key={tenant.id} to={`/tenants/${tenant.id}`} className="attention-row">
                  <div className="grow">
                    <strong>{tenant.name}</strong>
                    <span className="muted small">{tenant.reason}</span>
                  </div>
                  <div className="row gap-8">
                    {Number(tenant.outstanding) > 0 && <Badge tone="amber">{money(tenant.outstanding)} due</Badge>}
                    <StatusBadge status={tenant.status} />
                  </div>
                </Link>
              ))}
            </div>
          )}
        </Card>
      </div>

      <div className="two-col">
        <Card title="Top restaurants" subtitle="Ranked by orders this month">
          <Table
            rows={leaderboard}
            rowKey={(row) => row.id}
            empty="No usage recorded yet"
            columns={[
              {
                key: 'name',
                label: 'Restaurant',
                render: (row) => (
                  <Link to={`/tenants/${row.id}`} className="link">
                    {row.name}
                  </Link>
                ),
              },
              { key: 'orders', label: 'Orders', align: 'right', render: (row) => number(row.orders) },
              { key: 'mrr', label: 'MRR', align: 'right', render: (row) => money(row.mrr, row.currency) },
              { key: 'status', label: 'Status', render: (row) => <StatusBadge status={row.status} /> },
            ]}
          />
        </Card>

        <Card title="Plan mix" subtitle="Tenants and recurring revenue per plan">
          <div className="stack">
            {plans.map((plan) => {
              const share = metrics.tenants.total ? (plan.tenants / metrics.tenants.total) * 100 : 0;
              return (
                <div key={plan.id} className="plan-mix">
                  <div className="row gap-8">
                    <i className="swatch" style={{ background: plan.accent_color }} />
                    <strong>{plan.name}</strong>
                    <span className="muted small grow">{plan.tenants} tenant(s)</span>
                    <span>{money(plan.mrr)}</span>
                  </div>
                  <div className="progress">
                    <span style={{ width: `${share}%`, background: plan.accent_color }} />
                  </div>
                </div>
              );
            })}
            {plans.length === 0 && <Empty title="No plans yet" />}
          </div>
        </Card>
      </div>

      <Card title="Recent platform activity" subtitle="Everything staff and tenants have done lately" padded={false}>
        <Table
          rows={activity.slice(0, 12)}
          rowKey={(row) => row.id}
          empty="No activity recorded yet"
          columns={[
            {
              key: 'description',
              label: 'Event',
              render: (row) => (
                <div className="cell-stack">
                  <span>{row.description}</span>
                  <span className="muted tiny">
                    {row.actor_name || titleCase(row.actor_type)} {row.tenant_name ? `· ${row.tenant_name}` : ''}
                  </span>
                </div>
              ),
            },
            {
              key: 'severity',
              label: 'Severity',
              width: 110,
              render: (row) => (
                <Badge tone={row.severity === 'critical' ? 'red' : row.severity === 'warning' ? 'amber' : 'slate'}>
                  {titleCase(row.severity)}
                </Badge>
              ),
            },
            { key: 'created_at', label: 'When', width: 120, align: 'right', render: (row) => <span className="muted">{relative(row.created_at)}</span> },
          ]}
        />
      </Card>
    </div>
  );
}
