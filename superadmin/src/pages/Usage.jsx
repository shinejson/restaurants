import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { api } from '../lib/api';
import { number, titleCase } from '../lib/format';
import { Badge, Card, Empty, Progress, Spinner, Stat, Table } from '../components/ui';

export default function Usage() {
  const [data, setData] = useState(null);
  const [meta, setMeta] = useState({});
  const [error, setError] = useState(null);
  const [sort, setSort] = useState('orders');

  useEffect(() => {
    api
      .get('/usage')
      .then((response) => {
        setData(response.data || []);
        setMeta(response.meta || {});
      })
      .catch((failure) => setError(failure.message));
  }, []);

  if (error) return <Empty title="Could not load usage" body={error} />;
  if (!data) return <Spinner label="Loading usage…" />;

  const totals = meta.totals || {};
  const sorted = [...data].sort((a, b) => Number(b[sort] || 0) - Number(a[sort] || 0));
  const peak = sorted[0]?.[sort] || 1;

  return (
    <div className="col gap-18">
      <header className="page-head">
        <div>
          <h1>Usage</h1>
          <p className="muted">Metering for {meta.period} · {meta.tenants} restaurant(s) measured</p>
        </div>
        <div className="segmented">
          {['orders', 'api_calls', 'emails'].map((key) => (
            <button key={key} className={sort === key ? 'active' : ''} onClick={() => setSort(key)}>
              {titleCase(key)}
            </button>
          ))}
        </div>
      </header>

      <div className="stat-grid">
        <Stat label="Orders" value={number(totals.orders)} tone="indigo" />
        <Stat label="API calls" value={number(totals.api_calls)} tone="cyan" />
        <Stat label="Emails sent" value={number(totals.emails)} tone="violet" />
        <Stat label="Restaurants" value={number(meta.tenants)} tone="green" />
      </div>

      <Card title={`By ${titleCase(sort)}`} subtitle="Relative consumption this billing period">
        <div className="stack">
          {sorted.slice(0, 12).map((row) => (
            <div key={row.id} className="usage-row">
              <div className="row gap-8 between">
                <Link className="link" to={`/tenants/${row.id}`}>{row.name}</Link>
                <span className="muted small">{row.plan_name}</span>
              </div>
              <Progress value={row[sort]} max={peak} label={false} />
            </div>
          ))}
        </div>
      </Card>

      <Card title="All restaurants" padded={false}>
        <Table
          rows={data}
          empty="No usage recorded"
          columns={[
            { key: 'name', label: 'Restaurant', render: (row) => (
              <div className="cell-stack">
                <Link className="link" to={`/tenants/${row.id}`}>{row.name}</Link>
                <span className="muted tiny">{row.slug}</span>
              </div>
            ) },
            { key: 'plan_name', label: 'Plan', render: (row) => <Badge tone="indigo">{row.plan_name || '—'}</Badge> },
            { key: 'status', label: 'Status', render: (row) => <Badge tone={row.status === 'active' ? 'green' : row.status === 'past_due' ? 'amber' : 'slate'}>{titleCase(row.status)}</Badge> },
            { key: 'seat_count', label: 'Seats', align: 'right', render: (row) => number(row.seat_count) },
            { key: 'orders', label: 'Orders', align: 'right', render: (row) => number(row.orders) },
            { key: 'api_calls', label: 'API calls', align: 'right', render: (row) => number(row.api_calls) },
            { key: 'emails', label: 'Emails', align: 'right', render: (row) => number(row.emails) },
          ]}
        />
      </Card>
    </div>
  );
}
