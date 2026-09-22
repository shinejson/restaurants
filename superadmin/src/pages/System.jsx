import { useCallback, useEffect, useState } from 'react';
import { api } from '../lib/api';
import { bytes, number, titleCase } from '../lib/format';
import { useSession } from '../lib/session';
import {
  Badge, Button, Card, Empty, Modal, Select, Spinner, Table, Toasts, useToasts,
} from '../components/ui';

const TABS = [
  { key: 'overview',     label: 'Overview & Runtime',     icon: '◉' },
  { key: 'diagnostics',  label: 'Environment & PHP',      icon: '🩺' },
  { key: 'backups',      label: 'Database Backups',       icon: '💾' },
];

export default function System() {
  useSession();
  const { toasts, push, dismiss } = useToasts();

  const [tab, setTab] = useState('overview');

  // ── overview state ──
  const [overview, setOverview] = useState(null);
  const [overviewLoading, setOverviewLoading] = useState(true);

  // ── diagnostics / requirements state ──
  const [requirements, setRequirements] = useState(null);
  const [reqLoading, setReqLoading] = useState(false);

  // ── backups state ──
  const [backups, setBackups] = useState(null);
  const [backupsLoading, setBackupsLoading] = useState(false);
  const [backupBusy, setBackupBusy] = useState(null);
  const [tenants, setTenants] = useState([]);
  const [selectedTenant, setSelectedTenant] = useState('');
  const [newBackupMsg, setNewBackupMsg] = useState(null);
  const [deleteConfirm, setDeleteConfirm] = useState(null);

  /* ──────────────────────────── loaders ──────────────────────────── */

  const loadOverview = useCallback(() => {
    setOverviewLoading(true);
    api.get('/system')
      .then((res) => setOverview(res.data))
      .catch((err) => push('Could not load system overview', err.message, 'error'))
      .finally(() => setOverviewLoading(false));
  }, [push]);

  const loadRequirements = useCallback(() => {
    setReqLoading(true);
    api.get('/system/requirements')
      .then((res) => setRequirements(res.data))
      .catch((err) => push('Could not load diagnostics', err.message, 'error'))
      .finally(() => setReqLoading(false));
  }, [push]);

  const loadBackups = useCallback(() => {
    setBackupsLoading(true);
    api.get('/system/backups')
      .then((res) => setBackups(res.data))
      .catch((err) => push('Could not load backups', err.message, 'error'))
      .finally(() => setBackupsLoading(false));
  }, [push]);

  const loadTenants = useCallback(() => {
    api.get('/tenants', { per_page: 100 })
      .then((res) => setTenants(res.data ?? []))
      .catch(() => {});
  }, []);

  useEffect(() => {
    loadOverview();
  }, [loadOverview]);

  useEffect(() => {
    if (tab === 'diagnostics' && !requirements) {
      loadRequirements();
    } else if (tab === 'backups') {
      if (!backups) loadBackups();
      if (!tenants.length) loadTenants();
    }
  }, [tab, requirements, backups, tenants.length, loadRequirements, loadBackups, loadTenants]);

  /* ──────────────────────────── backup handlers ──────────────────── */

  const triggerPlatformBackup = async () => {
    setBackupBusy('platform');
    try {
      const res = await api.post('/system/backup/platform');
      setNewBackupMsg({
        file: res.data.file,
        size: res.data.size,
        type: 'platform',
      });
      push('Platform backup complete', res.data.file, 'success');
      loadBackups();
    } catch (err) {
      push('Platform backup failed', err.message, 'error');
    } finally {
      setBackupBusy(null);
    }
  };

  const triggerTenantBackup = async () => {
    if (!selectedTenant) return;
    setBackupBusy('tenant');
    try {
      const res = await api.post(`/system/backup/tenant/${selectedTenant}`);
      setNewBackupMsg({
        file: res.data.file,
        size: res.data.size,
        type: 'tenant',
        tenant: res.data.tenant,
      });
      push('Tenant backup complete', res.data.file, 'success');
      setSelectedTenant('');
      loadBackups();
    } catch (err) {
      push('Tenant backup failed', err.message, 'error');
    } finally {
      setBackupBusy(null);
    }
  };

  const confirmDelete = async () => {
    if (!deleteConfirm) return;
    const fileName = deleteConfirm;
    setBackupBusy(`delete-${fileName}`);
    setDeleteConfirm(null);
    try {
      await api.delete(`/system/backups/${encodeURIComponent(fileName)}`);
      push('Backup deleted', fileName, 'success');
      loadBackups();
    } catch (err) {
      push('Delete failed', err.message, 'error');
    } finally {
      setBackupBusy(null);
    }
  };

  const downloadBackup = (fileName) => {
    const downloadUrl = api.url(`/system/backups/${encodeURIComponent(fileName)}`);
    const a = document.createElement('a');
    a.href = downloadUrl;
    a.download = fileName;
    document.body.appendChild(a);
    a.click();
    a.remove();
  };

  /* ════════════════════════════ TAB: OVERVIEW ══════════════════════ */

  const renderOverview = () => {
    if (overviewLoading && !overview) {
      return <Spinner label="Inspecting system runtime…" />;
    }
    if (!overview) {
      return (
        <Empty
          title="Could not read system status"
          body="Check database connection or inspect error logs."
          action={<Button size="sm" onClick={loadOverview}>Try again</Button>}
        />
      );
    }

    return (
      <div className="col gap-18">
        <div className="two-col">
          {/* Runtime & Server Card */}
          <Card title="Runtime & Server" subtitle="PHP process and database connection environment">
            <dl className="kv">
              <dt>PHP Version</dt>
              <dd><code>PHP {overview.php_version}</code></dd>
              <dt>Database Driver</dt>
              <dd><code>{titleCase(overview.driver)}</code></dd>
              <dt>Environment</dt>
              <dd>
                <Badge tone={overview.app_env === 'production' ? 'green' : 'amber'}>
                  {titleCase(overview.app_env)}
                </Badge>
              </dd>
              <dt>Timezone</dt>
              <dd>{overview.timezone}</dd>
              <dt>Server Clock</dt>
              <dd className="mono small">{overview.server_time}</dd>
              <dt>Tenant Tables</dt>
              <dd>{number(overview.tables)}</dd>
              <dt>Debug Mode</dt>
              <dd>
                {overview.debug ? (
                  <Badge tone="amber" dot>Debug ON</Badge>
                ) : (
                  <Badge tone="green" dot>Production Mode</Badge>
                )}
              </dd>
            </dl>
          </Card>

          {/* Storage Health Card */}
          <Card title="Storage Health" subtitle="Directory writability and disk space usage">
            <dl className="kv">
              {(overview.storage?.directories || []).map((dir) => (
                <div key={dir.path} className="row between">
                  <dt>{titleCase(dir.name)}</dt>
                  <dd className="row gap-8">
                    <span>{bytes(dir.size)}</span>
                    <Badge tone={dir.writable ? 'green' : 'red'}>
                      {dir.writable ? 'writable' : 'read-only'}
                    </Badge>
                  </dd>
                </div>
              ))}
              {(!overview.storage?.directories || !overview.storage.directories.length) && (
                <div className="row between">
                  <dt>Storage Root</dt>
                  <dd className="row gap-8">
                    <span>{overview.storage?.size ?? '0 B'}</span>
                    <Badge tone={overview.storage?.writable ? 'green' : 'red'}>
                      {overview.storage?.writable ? 'writable' : 'read-only'}
                    </Badge>
                  </dd>
                </div>
              )}
            </dl>
          </Card>
        </div>

        <div className="two-col">
          {/* Data Volume Card */}
          <Card title="Data Volume" subtitle="Core database record counts across the platform">
            <dl className="kv">
              {Object.entries(overview.counts || {}).map(([key, value]) => (
                <div key={key} className="row between">
                  <dt>{titleCase(key)}</dt>
                  <dd>
                    <strong>{typeof value === 'number' ? number(value) : String(value)}</strong>
                  </dd>
                </div>
              ))}
            </dl>
          </Card>

          {/* Database Migrations Card */}
          <Card title="Database Migrations" subtitle="Applied database schema migrations">
            <Table
              rows={overview.migrations || []}
              empty="No migrations applied"
              rowKey={(row) => row.migration}
              columns={[
                {
                  key: 'migration',
                  label: 'Migration',
                  render: (row) => <code>{row.migration}</code>,
                },
                {
                  key: 'batch',
                  label: 'Batch',
                  align: 'right',
                  render: (row) => <Badge tone="slate">{row.batch ?? 1}</Badge>,
                },
                {
                  key: 'applied_at',
                  label: 'Applied',
                  align: 'right',
                  render: (row) => (
                    <span className="muted tiny">{row.applied_at || row.created_at || '—'}</span>
                  ),
                },
              ]}
            />
          </Card>
        </div>
      </div>
    );
  };

  /* ════════════════════════════ TAB: DIAGNOSTICS ═══════════════════ */

  const renderDiagnostics = () => {
    if (reqLoading && !requirements) {
      return <Spinner label="Testing PHP environment & extensions…" />;
    }
    if (!requirements) {
      return (
        <Empty
          title="Could not load requirements report"
          body="An error occurred while inspecting PHP directives and modules."
          action={<Button size="sm" onClick={loadRequirements}>Retry</Button>}
        />
      );
    }

    const exts = Object.entries(requirements.extensions || {});
    const directives = Object.entries(requirements.directives || {});

    return (
      <div className="col gap-18">
        <div className="two-col">
          {/* PHP Version Requirement */}
          <Card title="PHP Version" subtitle="Minimum requirement: PHP 8.1 or higher">
            <dl className="kv">
              <dt>Detected Version</dt>
              <dd><code>PHP {requirements.php?.version || requirements.php_version?.version || 'Unknown'}</code></dd>
              <dt>Required</dt>
              <dd><code>&gt;= {requirements.php?.min_version || '8.1.0'}</code></dd>
              <dt>Status</dt>
              <dd>
                <Badge tone={requirements.php?.ok ? 'green' : 'red'} dot>
                  {requirements.php?.ok ? 'Pass — Compatible' : 'Fail — Upgrade required'}
                </Badge>
              </dd>
            </dl>
          </Card>

          {/* PHP Directives Card */}
          <Card title="Directives & Environment" subtitle="Key php.ini settings for performance & security">
            <dl className="kv">
              {directives.map(([key, item]) => (
                <div key={key} className="row between">
                  <dt>{item.label || key}</dt>
                  <dd className="row gap-8">
                    {item.value && <span className="mono small">{item.value}</span>}
                    <Badge tone={item.ok ? 'green' : 'amber'}>
                      {item.ok ? 'Pass' : 'Notice'}
                    </Badge>
                  </dd>
                </div>
              ))}
              {requirements.disk && (
                <div className="row between">
                  <dt>Disk Free ({requirements.disk.path ? 'storage' : ''})</dt>
                  <dd className="row gap-8">
                    <strong>{requirements.disk.free}</strong>
                    <Badge tone={requirements.disk.ok ? 'green' : 'red'}>
                      {requirements.disk.ok ? 'OK' : 'Low space'}
                    </Badge>
                  </dd>
                </div>
              )}
            </dl>
          </Card>
        </div>

        {/* Extensions Card */}
        <Card title="PHP Extensions" subtitle="Required drivers, cryptography, and string handling modules">
          <Table
            rows={exts}
            rowKey={([ext]) => ext}
            columns={[
              {
                key: 'name',
                label: 'Extension',
                render: ([ext, info]) => (
                  <div>
                    <strong>{ext}</strong>
                    <span className="muted small block">{info.label}</span>
                  </div>
                ),
              },
              {
                key: 'version',
                label: 'Version',
                render: ([, info]) => (
                  <span className="mono small">{info.version || '—'}</span>
                ),
              },
              {
                key: 'status',
                label: 'Status',
                align: 'right',
                render: ([, info]) => (
                  <Badge tone={info.ok ? 'green' : 'red'} dot>
                    {info.ok ? 'Loaded' : 'Missing'}
                  </Badge>
                ),
              },
            ]}
          />
        </Card>
      </div>
    );
  };

  /* ════════════════════════════ TAB: BACKUPS ═══════════════════════ */

  const renderBackups = () => {
    if (backupsLoading && !backups) {
      return <Spinner label="Scanning backup archives…" />;
    }

    const files = backups?.files ?? [];

    return (
      <div className="col gap-18">
        {/* Banner for newly created backup */}
        {newBackupMsg && (
          <div className="banner-preview row between" style={{ background: 'var(--green-soft, rgba(52, 211, 153, 0.12))', borderColor: 'var(--green)' }}>
            <div>
              <strong>✓ New backup successfully created</strong>
              <p className="muted small" style={{ marginTop: 2 }}>
                {newBackupMsg.type === 'platform' ? 'Platform database dump' : `Tenant database dump (${newBackupMsg.tenant?.slug || 'tenant'})`}
                {newBackupMsg.size ? ` — ${bytes(newBackupMsg.size)}` : ''}
              </p>
            </div>
            <div className="row gap-8">
              <Button size="sm" variant="secondary" onClick={() => downloadBackup(newBackupMsg.file)}>
                Download
              </Button>
              <Button size="sm" variant="ghost" onClick={() => setNewBackupMsg(null)}>
                Dismiss
              </Button>
            </div>
          </div>
        )}

        <div className="two-col">
          {/* Storage Info Card */}
          <Card title="Backup Storage" subtitle="Archive location on server disk">
            <dl className="kv">
              <dt>Directory</dt>
              <dd className="mono small">{backups?.directory || 'storage/backups'}</dd>
              <dt>Total Archive Size</dt>
              <dd><strong>{bytes(backups?.total_size || 0)}</strong></dd>
              <dt>Archives Available</dt>
              <dd>{number(files.length)}</dd>
            </dl>
          </Card>

          {/* Quick Actions Card */}
          <Card title="Create Backup" subtitle="Trigger an on-demand database dump">
            <div className="col gap-14">
              <div className="row between gap-12 wrap">
                <div>
                  <strong>Platform Database</strong>
                  <p className="muted small">Dump the main control plane database</p>
                </div>
                <Button
                  variant="primary"
                  size="sm"
                  loading={backupBusy === 'platform'}
                  onClick={triggerPlatformBackup}
                >
                  Backup Platform
                </Button>
              </div>

              <div style={{ height: 1, background: 'var(--slate-700)' }} />

              <div className="col gap-8">
                <strong>Tenant Database</strong>
                <p className="muted small">Dump a specific restaurant's isolated database</p>
                <div className="row gap-8 wrap">
                  <div className="grow">
                    <Select
                      value={selectedTenant}
                      onChange={(e) => setSelectedTenant(e.target.value)}
                    >
                      <option value="">Select a restaurant tenant…</option>
                      {tenants.map((t) => (
                        <option key={t.id} value={t.id}>
                          {t.name} ({t.slug})
                        </option>
                      ))}
                    </Select>
                  </div>
                  <Button
                    variant="secondary"
                    size="sm"
                    disabled={!selectedTenant}
                    loading={backupBusy === 'tenant'}
                    onClick={triggerTenantBackup}
                  >
                    Backup Tenant
                  </Button>
                </div>
              </div>
            </div>
          </Card>
        </div>

        {/* Archives Table */}
        <Card title="Existing Backups" subtitle="Manage, download, or delete database dump archives">
          {!files.length ? (
            <Empty
              title="No backups found"
              body="Create your first database dump using the actions above."
            />
          ) : (
            <Table
              rows={files}
              rowKey={(row) => row.name}
              columns={[
                {
                  key: 'name',
                  label: 'Filename',
                  render: (row) => (
                    <div className="row gap-8">
                      <span className="mono small">{row.name}</span>
                      <Badge tone={row.type === 'gzip' ? 'indigo' : 'slate'}>
                        {row.type}
                      </Badge>
                    </div>
                  ),
                },
                {
                  key: 'size',
                  label: 'Size',
                  align: 'right',
                  render: (row) => <span>{bytes(row.size)}</span>,
                },
                {
                  key: 'modified',
                  label: 'Created / Modified',
                  align: 'right',
                  render: (row) => <span className="muted tiny">{row.modified}</span>,
                },
                {
                  key: 'actions',
                  label: '',
                  align: 'right',
                  render: (row) => (
                    <div className="row gap-8 end">
                      <Button
                        size="sm"
                        variant="ghost"
                        onClick={() => downloadBackup(row.name)}
                        title="Download archive"
                      >
                        Download
                      </Button>
                      <Button
                        size="sm"
                        variant="ghost"
                        style={{ color: 'var(--red)' }}
                        loading={backupBusy === `delete-${row.name}`}
                        onClick={() => setDeleteConfirm(row.name)}
                        title="Delete archive"
                      >
                        Delete
                      </Button>
                    </div>
                  ),
                },
              ]}
            />
          )}
        </Card>
      </div>
    );
  };

  /* ════════════════════════════ MAIN RENDER ════════════════════════ */

  return (
    <div className="col gap-18">
      <Toasts toasts={toasts} dismiss={dismiss} />

      {/* Confirmation Modal for Delete */}
      <Modal
        open={Boolean(deleteConfirm)}
        title="Delete database backup?"
        subtitle={`Are you sure you want to permanently remove "${deleteConfirm}" from storage? This cannot be undone.`}
        onClose={() => setDeleteConfirm(null)}
        footer={
          <>
            <Button variant="ghost" onClick={() => setDeleteConfirm(null)}>
              Cancel
            </Button>
            <Button variant="danger" onClick={confirmDelete}>
              Delete Backup
            </Button>
          </>
        }
      />

      {/* Page Header */}
      <header className="page-head">
        <div>
          <h1>System</h1>
          <p className="muted">Runtime health, storage reports, environment diagnostics and database backups</p>
        </div>
        <div className="row gap-8">
          {overview && (
            <Badge tone={overview.debug ? 'amber' : 'green'} dot>
              {overview.debug ? 'Debug Mode' : 'Production-ready'}
            </Badge>
          )}
          <Button
            size="sm"
            variant="ghost"
            onClick={() => {
              if (tab === 'overview') loadOverview();
              else if (tab === 'diagnostics') loadRequirements();
              else if (tab === 'backups') loadBackups();
            }}
          >
            Refresh
          </Button>
        </div>
      </header>

      {/* Tab Strip */}
      <div className="settings-tabs">
        {TABS.map((t) => (
          <button
            key={t.key}
            className={`settings-tab ${tab === t.key ? 'active' : ''}`}
            onClick={() => setTab(t.key)}
          >
            <span className="settings-tab-icon">{t.icon}</span>
            {t.label}
          </button>
        ))}
      </div>

      {/* Active Tab View */}
      {tab === 'overview' && renderOverview()}
      {tab === 'diagnostics' && renderDiagnostics()}
      {tab === 'backups' && renderBackups()}
    </div>
  );
}
