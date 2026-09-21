import { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { api, getCsrfToken } from '../lib/api';
import { number, relative, titleCase } from '../lib/format';
import { Badge, Button, Card, Input, Select, Table } from '../components/ui';

export default function Audit() {
  const [rows, setRows] = useState([]);
  const [meta, setMeta] = useState({});
  const [action, setAction] = useState('all');
  const [severity, setSeverity] = useState('all');
  const [search, setSearch] = useState('');
  const [page, setPage] = useState(1);
  const [loading, setLoading] = useState(true);
  const [expanded, setExpanded] = useState(null);

  const load = useCallback(() => {
    setLoading(true);
    api
      .get('/audit-logs', { action, severity, search, page, per_page: 40 })
      .then((response) => {
        setRows(response.data || []);
        setMeta(response.meta || {});
      })
      .catch(() => {})
      .finally(() => setLoading(false));
  }, [action, severity, search, page]);

  useEffect(() => {
    load();
  }, [load]);

  const exportCsv = async () => {
    const response = await fetch(`/api/v1/audit-logs/export?action=${action === 'all' ? '' : action}`, {
      credentials: 'same-origin',
      headers: { 'x-csrf-token': getCsrfToken() || '' },
    });
    const blob = await response.blob();
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = `audit-log-${new Date().toISOString().slice(0, 10)}.csv`;
    link.click();
    URL.revokeObjectURL(url);
  };

  return (
    <div className="col gap-18">
      <header className="page-head">
        <div>
          <h1>Audit log</h1>
          <p className="muted">{number(meta.total || 0)} recorded events — who did what, and to whom</p>
        </div>
        <Button variant="secondary" onClick={exportCsv}>Export CSV</Button>
      </header>

      <div className="filter-row">
        <Select value={action} onChange={(event) => { setAction(event.target.value); setPage(1); }}>
          <option value="all">All actions</option>
          {(meta.actions || []).map((value) => (
            <option key={value} value={value}>{value}</option>
          ))}
        </Select>
        <Select value={severity} onChange={(event) => { setSeverity(event.target.value); setPage(1); }}>
          <option value="all">Any severity</option>
          {['info', 'notice', 'warning', 'critical'].map((value) => (
            <option key={value} value={value}>{titleCase(value)}</option>
          ))}
        </Select>
        <div className="grow" />
        <form className="search inline" onSubmit={(event) => { event.preventDefault(); setPage(1); }}>
          <span>⌕</span>
          <Input value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Search description or actor" />
        </form>
      </div>

      <Card padded={false}>
        <Table
          loading={loading && !rows.length}
          rows={rows}
          empty="No audit entries match"
          onRowClick={(row) => setExpanded(expanded === row.id ? null : row.id)}
          columns={[
            {
              key: 'description',
              label: 'Event',
              render: (row) => (
                <div className="cell-stack">
                  <span>{row.description}</span>
                  <span className="muted tiny">
                    <code>{row.action}</code>
                    {row.tenant_name ? <> · <Link className="link" to={`/tenants/${row.tenant_id}`}>{row.tenant_name}</Link></> : null}
                    {expanded === row.id && row.meta ? <pre className="meta-json">{row.meta}</pre> : null}
                  </span>
                </div>
              ),
            },
            { key: 'actor_name', label: 'Actor', render: (row) => (
              <div className="cell-stack">
                <span>{row.actor_name || '—'}</span>
                <span className="muted tiny">{titleCase(row.actor_type)}</span>
              </div>
            ) },
            { key: 'severity', label: 'Severity', render: (row) => (
              <Badge tone={row.severity === 'critical' ? 'red' : row.severity === 'warning' ? 'amber' : row.severity === 'notice' ? 'indigo' : 'slate'}>
                {titleCase(row.severity)}
              </Badge>
            ) },
            { key: 'ip', label: 'IP', render: (row) => <span className="muted tiny">{row.ip || '—'}</span> },
            { key: 'created_at', label: 'When', align: 'right', render: (row) => <span className="muted">{relative(row.created_at)}</span> },
          ]}
        />
      </Card>

      {meta.last_page > 1 && (
        <div className="pager">
          <Button size="sm" disabled={page <= 1} onClick={() => setPage(page - 1)}>← Newer</Button>
          <span className="muted small">Page {meta.page} of {meta.last_page}</span>
          <Button size="sm" disabled={page >= meta.last_page} onClick={() => setPage(page + 1)}>Older →</Button>
        </div>
      )}
    </div>
  );
}
