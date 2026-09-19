import { useEffect, useState } from 'react';
import { api } from '../lib/api';
import { bytes, number, titleCase } from '../lib/format';
import { Badge, Card, Empty, Spinner, Table } from '../components/ui';

export default function System() {
  const [data, setData] = useState(null);
  const [error, setError] = useState(null);

  useEffect(() => {
    api.get('/system').then((response) => setData(response.data)).catch((failure) => setError(failure.message));
  }, []);

  if (error) return <Empty title="Could not read system status" body={error} />;
  if (!data) return <Spinner label="Inspecting the platform…" />;

  return (
    <div className="col gap-18">
      <header className="page-head">
        <div>
          <h1>System</h1>
          <p className="muted">Runtime, storage and schema health for the control plane</p>
        </div>
        <Badge tone={data.debug ? 'amber' : 'green'} dot>
          {data.debug ? 'Debug mode' : 'Production-ready'}
        </Badge>
      </header>

      <div className="two-col">
        <Card title="Runtime">
          <dl className="kv">
            <dt>PHP</dt><dd>{data.php_version}</dd>
            <dt>Database driver</dt><dd>{titleCase(data.driver)}</dd>
            <dt>Environment</dt><dd>{titleCase(data.app_env)}</dd>
            <dt>Timezone</dt><dd>{data.timezone}</dd>
            <dt>Server time</dt><dd>{data.server_time}</dd>
            <dt>Tenant tables</dt><dd>{number(data.tables)}</dd>
          </dl>
        </Card>

        <Card title="Storage">
          <dl className="kv">
            {(data.storage?.directories || []).map((directory) => (
              <div key={directory.path} className="row between">
                <dt>{titleCase(directory.name)}</dt>
                <dd>
                  {bytes(directory.size)}
                  {!directory.writable && <Badge tone="red">read-only</Badge>}
                </dd>
              </div>
            ))}
            {!data.storage?.directories?.length && <dd className="muted">No storage report available.</dd>}
          </dl>
        </Card>
      </div>

      <div className="two-col">
        <Card title="Data volume">
          <dl className="kv">
            {Object.entries(data.counts || {}).map(([key, value]) => (
              <div key={key} className="row between">
                <dt>{titleCase(key)}</dt>
                <dd>{typeof value === 'number' ? number(value) : String(value)}</dd>
              </div>
            ))}
          </dl>
        </Card>

        <Card title="Migrations" subtitle="Applied in order at boot">
          <Table
            rows={data.migrations || []}
            empty="No migrations applied"
            rowKey={(row) => row.migration}
            columns={[
              { key: 'migration', label: 'Migration', render: (row) => <code>{row.migration}</code> },
              { key: 'batch', label: 'Batch', align: 'right', render: (row) => row.batch ?? 1 },
              { key: 'applied_at', label: 'Applied', align: 'right', render: (row) => <span className="muted tiny">{row.applied_at || row.created_at || '—'}</span> },
            ]}
          />
        </Card>
      </div>
    </div>
  );
}
