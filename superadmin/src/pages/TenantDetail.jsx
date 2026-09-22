import { useCallback, useEffect, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { api } from '../lib/api';
import { bytes, date, money, number, relative, titleCase } from '../lib/format';
import { useSession } from '../lib/session';
import {
  Avatar, Badge, Button, Card, Empty, Field, Input, Modal, Progress, Select, Spinner, StatusBadge, Table, Textarea, useToasts, Toasts,
} from '../components/ui';

const TABS = ['overview', 'usage', 'billing', 'people', 'notes', 'activity', 'danger'];

export default function TenantDetail() {
  const { id } = useParams();
  const navigate = useNavigate();
  const { can, refresh } = useSession();
  const { toasts, push, dismiss } = useToasts();

  const [data, setData] = useState(null);
  const [plans, setPlans] = useState([]);
  const [tab, setTab] = useState('overview');
  const [error, setError] = useState(null);
  const [busy, setBusy] = useState(null);
  const [stats, setStats] = useState(null);

  const [editOpen, setEditOpen] = useState(false);
  const [editForm, setEditForm] = useState({});
  const [statusOpen, setStatusOpen] = useState(false);
  const [statusForm, setStatusForm] = useState({ status: 'suspended', reason: '' });
  const [noteBody, setNoteBody] = useState('');
  const [impersonateOpen, setImpersonateOpen] = useState(false);
  const [impersonateReason, setImpersonateReason] = useState('Support session from the console');
  const [deleteOpen, setDeleteOpen] = useState(false);
  const [deleteConfirm, setDeleteConfirm] = useState('');
  const [trialOpen, setTrialOpen] = useState(false);
  const [trialDays, setTrialDays] = useState(14);

  const load = useCallback(async () => {
    try {
      const response = await api.get(`/tenants/${id}`);
      setData(response.data);
      setError(null);
    } catch (failure) {
      setError(failure.message);
    }
  }, [id]);

  const copyText = (text, label) => {
    if (!text) return;
    navigator.clipboard.writeText(text);
    push('Copied', `${label} copied to clipboard`, 'success');
  };

  const copyAllAccess = () => {
    if (!tenant) return;
    const sUrl = tenant.links?.storefront || tenant.unique_url;
    const aUrl = tenant.links?.admin || tenant.admin_url;
    const text = `Restaurant: ${tenant.name}
Storefront URL: ${sUrl}
Admin Portal: ${aUrl}
Access Code: ${tenant.access_code}
Owner: ${tenant.owner_name} (${tenant.owner_email})`;
    navigator.clipboard.writeText(text);
    push('Copied All Access Info', 'Restaurant login and access details copied to clipboard', 'success');
  };

  useEffect(() => {
    load();
    api.get('/plans').then((response) => setPlans(response.data || [])).catch(() => {});
  }, [load]);

  useEffect(() => {
    if (tab === 'usage' && !stats) {
      api.get(`/tenants/${id}/stats`).then((response) => setStats(response.data)).catch(() => {});
    }
  }, [tab, id, stats]);

  const run = async (key, action, successMessage) => {
    setBusy(key);
    try {
      const result = await action();
      await load();
      if (successMessage) push(successMessage, result?.data?.message, 'success');
      return result;
    } catch (failure) {
      push('That did not work', failure.message, 'error');
      throw failure;
    } finally {
      setBusy(null);
    }
  };

  if (error) return <Empty title="Restaurant not found" body={error} action={<Link className="btn btn-secondary" to="/tenants">Back to restaurants</Link>} />;
  if (!data) return <Spinner label="Loading restaurant…" />;

  const { tenant, subscription, invoices, staff, notes, activity, counts, features: catalog, health } = data;
  const usage = tenant.usage || { counters: {}, quotas: [] };
  const quotaByMetric = Object.fromEntries((usage.quotas || []).map((quota) => [quota.metric, quota]));

  const setStatus = (status, reason = '') =>
    run('status', () => api.post(`/tenants/${id}/status`, { status, reason }), `Marked ${status}`);

  const saveEdit = () =>
    run('edit', () => api.patch(`/tenants/${id}`, editForm), 'Restaurant updated').then(() => setEditOpen(false));

  const toggleFeature = (key, enabled) =>
    run(`feature-${key}`, () => api.post(`/tenants/${id}/features`, { feature_key: key, enabled, note: 'Toggled in the console' }), `${titleCase(key)} ${enabled ? 'enabled' : 'disabled'}`);

  const clearFeature = (key) =>
    run(`feature-${key}`, () => api.delete(`/tenants/${id}/features/${key}`), `${titleCase(key)} reset to plan default`);

  const impersonate = async () => {
    const result = await run('impersonate', () => api.post(`/tenants/${id}/impersonate`, { reason: impersonateReason, force: true }));
    if (result?.data?.url) window.open(result.data.url, '_blank', 'noopener');
    setImpersonateOpen(false);
  };

  const destroy = () =>
    run('delete', () => api.delete(`/tenants/${id}`, { confirm: deleteConfirm, drop_database: true })).then(() => navigate('/tenants'));

  return (
    <div className="col gap-18">
      <Toasts toasts={toasts} dismiss={dismiss} />

      <nav className="breadcrumb">
        <Link to="/tenants">Restaurants</Link>
        <span>/</span>
        <span>{tenant.name}</span>
      </nav>

      <header className="tenant-head">
        <Avatar name={tenant.name} size={56} tone={tenant.plan?.accent_color} />
        <div className="grow">
          <div className="row gap-10 wrap">
            <h1>{tenant.name}</h1>
            <StatusBadge status={tenant.status} />
            {tenant.plan && <Badge tone="indigo">{tenant.plan.name}</Badge>}
            <Badge tone={health.status === 'at_risk' ? 'red' : health.status === 'watch' ? 'amber' : 'green'}>
              Health {health.score}
            </Badge>
          </div>
          <p className="muted small">
            {tenant.slug} · {tenant.owner_name} · {tenant.owner_email}
            {tenant.city ? ` · ${tenant.city}` : ''}
            {tenant.country ? `, ${tenant.country}` : ''} · customer since {date(tenant.created_at)}
            {tenant.age_days !== null ? ` (${tenant.age_days}d)` : ''}
          </p>
        </div>
        <div className="row gap-8 wrap">
          <Button size="sm" onClick={() => window.open(tenant.links?.storefront || tenant.unique_url, '_blank', 'noopener')}>Storefront ↗</Button>
          <Button size="sm" onClick={() => window.open(tenant.links?.admin || tenant.admin_url, '_blank', 'noopener')}>Admin ↗</Button>
          {can('tenants.impersonate') && (
            <Button size="sm" variant="secondary" onClick={() => setImpersonateOpen(true)} loading={busy === 'impersonate'}>
              Sign in as owner
            </Button>
          )}
          {can('tenants.update') && (
            <Button size="sm" variant="secondary" onClick={() => { setEditForm({
              name: tenant.name,
              owner_name: tenant.owner_name,
              owner_email: tenant.owner_email,
              owner_phone: tenant.owner_phone || '',
              city: tenant.city || '',
              country: tenant.country || '',
              timezone: tenant.timezone || 'UTC',
              currency: tenant.currency || 'USD',
              seat_count: tenant.seat_count,
              billing_cycle: tenant.billing_cycle,
              plan_id: tenant.plan_id,
              custom_domain: tenant.custom_domain || '',
            }); setEditOpen(true); }}>
              Edit
            </Button>
          )}
          {can('tenants.suspend') && ['active', 'trial'].includes(tenant.status) && (
            <Button size="sm" variant="danger" onClick={() => { setStatusForm({ status: 'suspended', reason: '' }); setStatusOpen(true); }}>
              Suspend
            </Button>
          )}
          {can('tenants.suspend') && ['suspended', 'cancelled', 'past_due'].includes(tenant.status) && (
            <Button size="sm" variant="primary" loading={busy === 'status'} onClick={() => setStatus('active', 'Access restored from the platform console')}>
              Reactivate Account
            </Button>
          )}
        </div>
      </header>

      {tenant.suspended_reason && tenant.status === 'suspended' && (
        <div className="alert alert-warn">
          <strong>Suspended.</strong> {tenant.suspended_reason} (since {date(tenant.suspended_at)})
        </div>
      )}
      {tenant.status === 'cancelled' && (
        <div className="alert alert-error">
          <strong>Account Cancelled.</strong> This restaurant was cancelled (since {date(tenant.cancelled_at)}). Their storefront and staff portals are blocked. Click <strong>Reactivate Account</strong> above to restore full access.
        </div>
      )}
      {tenant.status === 'past_due' && (
        <div className="alert alert-warn">
          <strong>Subscription Past Due.</strong> This restaurant's payment is overdue. Click <strong>Reactivate Account</strong> above to restore access.
        </div>
      )}
      {health.issues?.length > 0 && (
        <div className="health-strip">
          {health.issues.map((issue) => (
            <span key={issue.title} className={`health-chip health-${issue.level}`}>
              <strong>{issue.title}</strong> {issue.detail}
            </span>
          ))}
        </div>
      )}

      <div className="tabs">
        {TABS.filter((value) => can('tenants.delete') || value !== 'danger').map((value) => (
          <button key={value} className={tab === value ? 'active' : ''} onClick={() => setTab(value)}>
            {titleCase(value)}
          </button>
        ))}
      </div>

      {tab === 'overview' && (
        <div className="two-col">
          <Card title="Access & Unique Links" subtitle="Dedicated URLs for this restaurant's storefront and staff portal">
            <div className="col gap-12">
              <div>
                <label className="muted tiny uppercase bold" style={{ display: 'block', marginBottom: 4 }}>
                  Storefront Unique Link
                </label>
                <div className="row gap-8" style={{ alignItems: 'center' }}>
                  <code style={{ flex: 1, padding: '6px 10px', background: 'var(--slate-900, #0f172a)', border: '1px solid var(--border)', borderRadius: 6, fontSize: '0.85rem', wordBreak: 'break-all' }}>
                    {tenant.links?.storefront || tenant.unique_url}
                  </code>
                  <Button size="sm" variant="secondary" onClick={() => copyText(tenant.links?.storefront || tenant.unique_url, 'Storefront link')}>
                    Copy
                  </Button>
                  <Button size="sm" onClick={() => window.open(tenant.links?.storefront || tenant.unique_url, '_blank', 'noopener')}>
                    Open ↗
                  </Button>
                </div>
              </div>

              <div>
                <label className="muted tiny uppercase bold" style={{ display: 'block', marginBottom: 4 }}>
                  Restaurant Admin Login
                </label>
                <div className="row gap-8" style={{ alignItems: 'center' }}>
                  <code style={{ flex: 1, padding: '6px 10px', background: 'var(--slate-900, #0f172a)', border: '1px solid var(--border)', borderRadius: 6, fontSize: '0.85rem', wordBreak: 'break-all' }}>
                    {tenant.links?.admin || tenant.admin_url}
                  </code>
                  <Button size="sm" variant="secondary" onClick={() => copyText(tenant.links?.admin || tenant.admin_url, 'Admin login link')}>
                    Copy
                  </Button>
                  <Button size="sm" onClick={() => window.open(tenant.links?.admin || tenant.admin_url, '_blank', 'noopener')}>
                    Open ↗
                  </Button>
                </div>
              </div>

              <div className="row gap-12 wrap" style={{ marginTop: 4, paddingTop: 10, borderTop: '1px solid var(--border)' }}>
                <div style={{ flex: 1, minWidth: 160 }}>
                  <span className="muted tiny uppercase bold" style={{ display: 'block' }}>Restaurant Access Code</span>
                  <div className="row gap-8" style={{ alignItems: 'center', marginTop: 4 }}>
                    <Badge tone="purple" style={{ fontSize: '0.95rem', letterSpacing: '0.15em', fontWeight: 700, padding: '4px 10px' }}>
                      {tenant.access_code}
                    </Badge>
                    <Button size="sm" variant="ghost" onClick={() => copyText(tenant.access_code, 'Access code')}>
                      Copy
                    </Button>
                  </div>
                  <span className="muted tiny" style={{ marginTop: 2, display: 'block' }}>
                    Staff use this code to sign in directly
                  </span>
                </div>

                <div style={{ flex: 1, minWidth: 160 }}>
                  <span className="muted tiny uppercase bold" style={{ display: 'block' }}>Domain / Routing</span>
                  <div style={{ marginTop: 4 }}>
                    {tenant.custom_domain ? (
                      <span className="badge badge-green">Custom: {tenant.custom_domain}</span>
                    ) : (
                      <span className="muted small">{tenant.subdomain || `${tenant.slug}.localhost`}</span>
                    )}
                  </div>
                </div>
              </div>

              <div style={{ marginTop: 6, paddingTop: 10, borderTop: '1px solid var(--border)' }}>
                <Button size="sm" variant="ghost" style={{ width: '100%' }} onClick={copyAllAccess}>
                  📋 Copy All Access Details (to send to Owner)
                </Button>
              </div>
            </div>
          </Card>
          <Card title="Subscription">
            <div className="col gap-14">
              <div className="row gap-12">
                <div className="grow">
                  <strong className="big">{subscription?.status ? titleCase(subscription.status) : 'No subscription'}</strong>
                  <p className="muted small">
                    {tenant.plan?.name} · {money(subscription?.amount ?? tenant.mrr, subscription?.currency || tenant.currency)} / {subscription?.billing_cycle || tenant.billing_cycle}
                  </p>
                </div>
                {tenant.plan && <Badge tone="indigo">{tenant.plan.badge || titleCase(tenant.plan.code)}</Badge>}
              </div>
              <dl className="kv">
                <dt>Renews</dt>
                <dd>{date(subscription?.current_period_end || tenant.trial_ends_at)}</dd>
                <dt>{tenant.on_trial ? 'Trial ends' : 'Billing cycle'}</dt>
                <dd>{tenant.on_trial ? `${tenant.trial_days_left} day(s) left` : titleCase(tenant.billing_cycle || 'monthly')}</dd>
                <dt>Seats</dt>
                <dd>{tenant.seat_count}</dd>
                <dt>MRR</dt>
                <dd>{money(tenant.mrr, tenant.currency)}</dd>
                <dt>Lifetime revenue</dt>
                <dd>{money(tenant.snapshot?.lifetime_revenue, tenant.currency)}</dd>
              </dl>
              <div className="row gap-8 wrap">
                {can('tenants.update') && (
                  <Button size="sm" onClick={() => setTrialOpen(true)}>Extend trial</Button>
                )}
                {can('billing.manage') && tenant.status !== 'active' && (
                  <Button size="sm" variant="primary" loading={busy === 'activate'} onClick={() => run('activate', () => api.post(`/subscriptions/${id}/activate`, { plan_id: tenant.plan_id, billing_cycle: tenant.billing_cycle, invoice: true }), 'Subscription activated')}>
                    Activate subscription
                  </Button>
                )}
                {can('billing.manage') && subscription && ['active', 'past_due', 'trialing'].includes(subscription.status) && (
                  <Button size="sm" variant="ghost" onClick={() => run('cancel', () => api.post(`/subscriptions/${id}/cancel`, { immediately: false, reason: 'Cancelled from the console' }), 'Subscription cancelled at period end')}>
                    Cancel at period end
                  </Button>
                )}
              </div>
            </div>
          </Card>

          <Card title="Workspace" subtitle="Live row counts in the tenant database">
            <div className="mini-grid">
              <div><span className="muted small">Orders</span><strong>{number(counts.orders)}</strong><span className="muted tiny">{number(counts.orders_30d)} in 30d</span></div>
              <div><span className="muted small">Menu items</span><strong>{number(counts.food_items)}</strong></div>
              <div><span className="muted small">Customers</span><strong>{number(counts.customers)}</strong></div>
              <div><span className="muted small">Tables</span><strong>{number(counts.restaurant_tables)}</strong></div>
              <div><span className="muted small">Events</span><strong>{number(counts.events)}</strong><span className="muted tiny">{number(counts.event_bookings)} bookings</span></div>
              <div><span className="muted small">Staff</span><strong>{number(staff.length)}</strong><span className="muted tiny">{number(counts.roles)} roles</span></div>
              <div><span className="muted small">Printers</span><strong>{number(counts.printers)}</strong></div>
              <div><span className="muted small">Database</span><strong>{bytes(counts.db_size_bytes)}</strong></div>
            </div>
          </Card>

          <Card title="Features" subtitle="Plan entitlements with per-tenant overrides" className="span-2">
            <div className="feature-grid">
              {Object.entries(catalog.features).map(([key, definition]) => {
                const enabled = Boolean(tenant.features?.[key]);
                return (
                  <div key={key} className={`feature ${enabled ? 'on' : 'off'}`}>
                    <div className="grow">
                      <strong>{definition.label}</strong>
                      <span className="muted tiny">{definition.description || definition.group}</span>
                    </div>
                    {can('tenants.update') ? (
                      <div className="row gap-6">
                        <Button size="xs" variant={enabled ? 'ghost' : 'secondary'} loading={busy === `feature-${key}`} onClick={() => toggleFeature(key, !enabled)}>
                          {enabled ? 'Disable' : 'Enable'}
                        </Button>
                        <Button size="xs" variant="ghost" title="Reset to plan default" onClick={() => clearFeature(key)}>
                          ↺
                        </Button>
                      </div>
                    ) : (
                      <Badge tone={enabled ? 'green' : 'slate'}>{enabled ? 'On' : 'Off'}</Badge>
                    )}
                  </div>
                );
              })}
            </div>

            <div className="limit-grid">
              {Object.entries(catalog.limits).map(([key, definition]) => {
                const quota = quotaByMetric[key];
                const limit = quota?.limit ?? tenant.limits?.[key];
                const used = quota?.used ?? usage.counters?.[key] ?? usage.counters?.[definition.unit] ?? 0;
                return (
                  <div key={key}>
                    <div className="row between">
                      <span className="small">{definition.label}</span>
                      <span className="muted tiny">{definition.hard ? 'hard cap' : 'soft'}</span>
                    </div>
                    <Progress value={used} max={limit} />
                  </div>
                );
              })}
            </div>
          </Card>
        </div>
      )}

      {tab === 'usage' && (
        <div className="col gap-16">
          <div className="stat-grid">
            <div className="stat"><span className="stat-label">Orders this month</span><span className="stat-value">{number(usage.counters?.orders)}</span></div>
            <div className="stat"><span className="stat-label">API calls</span><span className="stat-value">{number(usage.counters?.api_calls)}</span></div>
            <div className="stat"><span className="stat-label">Emails sent</span><span className="stat-value">{number(usage.counters?.email_sends)}</span></div>
            <div className="stat"><span className="stat-label">Open invoices</span><span className="stat-value">{number(tenant.snapshot?.open_invoices)}</span></div>
          </div>

          <Card title="Monthly usage" subtitle="Orders, API calls and emails by period">
            {usage.quotas?.length ? (
              <div className="quota-list">
                {usage.quotas.map((quota) => (
                  <div key={quota.metric}>
                    <div className="row between">
                      <span>{titleCase(quota.metric)}</span>
                      <span className="muted small">
                        {number(quota.used)} / {quota.limit === null || quota.limit === -1 ? 'unlimited' : number(quota.limit)}
                      </span>
                    </div>
                    <Progress value={quota.used} max={quota.limit} label={false} />
                  </div>
                ))}
              </div>
            ) : (
              <p className="muted">No quota data yet.</p>
            )}

            <div className="history-grid">
              {Object.entries(usage.history || {}).map(([period, counters]) => (
                <div key={period} className="history-card">
                  <strong>{period}</strong>
                  <span>{number(counters.orders)} orders</span>
                  <span>{number(counters.api_calls)} API</span>
                  <span>{number(counters.email_sends)} emails</span>
                </div>
              ))}
            </div>
          </Card>

          <Card title="Orders per day" subtitle="Last 30 days">
            {stats?.orders_by_day?.length ? (
              <div className="spark-list">
                {stats.orders_by_day.slice(-14).map((day) => (
                  <div key={day.day} className="spark-row">
                    <span className="muted small">{day.day}</span>
                    <div className="progress"><span style={{ width: `${Math.min(100, (Number(day.total) / Math.max(...stats.orders_by_day.map((entry) => Number(entry.total)) || [1])) * 100)}%`, background: 'var(--indigo)' }} /></div>
                    <span>{number(day.total)}</span>
                    <span className="muted">{money(day.revenue, tenant.currency)}</span>
                  </div>
                ))}
              </div>
            ) : (
              <p className="muted">No orders in the last 30 days.</p>
            )}
          </Card>
        </div>
      )}

      {tab === 'billing' && (
        <Card title="Invoices" subtitle={`${invoices.meta?.total || 0} invoice(s) for this restaurant`} padded={false}>
          <Table
            rows={invoices.data}
            empty="No invoices yet"
            columns={[
              { key: 'number', label: 'Invoice', render: (row) => <code>{row.number}</code> },
              { key: 'status', label: 'Status', render: (row) => <Badge tone={row.status === 'paid' ? 'green' : row.status === 'past_due' ? 'amber' : 'slate'}>{titleCase(row.status)}</Badge> },
              { key: 'issued_at', label: 'Issued', render: (row) => date(row.issued_at) },
              { key: 'due_at', label: 'Due', render: (row) => date(row.due_at) },
              { key: 'total', label: 'Total', align: 'right', render: (row) => money(row.total, row.currency) },
              { key: 'amount_paid', label: 'Paid', align: 'right', render: (row) => money(row.amount_paid, row.currency) },
              {
                key: 'actions',
                label: '',
                align: 'right',
                render: (row) =>
                  can('billing.manage') && row.status !== 'paid' && row.status !== 'void' ? (
                    <Button size="xs" variant="secondary" loading={busy === `pay-${row.id}`} onClick={() => run(`pay-${row.id}`, () => api.post(`/invoices/${row.id}/pay`, {}), 'Payment recorded')}>
                      Record payment
                    </Button>
                  ) : null,
              },
            ]}
          />
        </Card>
      )}

      {tab === 'people' && (
        <Card title="Staff accounts" subtitle="Owners and staff inside this restaurant" padded={false}>
          <Table
            rows={staff}
            empty="No staff accounts"
            columns={[
              { key: 'full_name', label: 'Person', render: (row) => (
                <div className="row gap-10">
                  <Avatar name={row.full_name || row.username} size={30} />
                  <div className="cell-stack">
                    <strong>{row.full_name || row.username}</strong>
                    <span className="muted tiny">{row.email}</span>
                  </div>
                </div>
              ) },
              { key: 'username', label: 'Username', render: (row) => <code>{row.username}</code> },
              { key: 'role_name', label: 'Role', render: (row) => <Badge tone="indigo">{row.role_name || row.role}</Badge> },
              { key: 'is_active', label: 'Active', render: (row) => <Badge tone={row.is_active ? 'green' : 'slate'}>{row.is_active ? 'Yes' : 'No'}</Badge> },
              { key: 'last_login_at', label: 'Last login', align: 'right', render: (row) => <span className="muted">{row.last_login_at ? relative(row.last_login_at) : 'never'}</span> },
            ]}
          />
        </Card>
      )}

      {tab === 'notes' && (
        <Card title="Support notes" subtitle="Only visible to platform staff">
          <div className="col gap-12">
            <Textarea value={noteBody} onChange={(event) => setNoteBody(event.target.value)} placeholder="Called the owner about the trial ending…" />
            <div className="row gap-8">
              <Button
                variant="primary"
                size="sm"
                loading={busy === 'note'}
                disabled={!noteBody.trim()}
                onClick={() => run('note', async () => { const result = await api.post(`/tenants/${id}/notes`, { body: noteBody }); setNoteBody(''); return result; }, 'Note added')}
              >
                Add note
              </Button>
            </div>

            {notes.length === 0 ? (
              <Empty title="No notes yet" body="Record calls, promises and escalations here." />
            ) : (
              <div className="stack">
                {notes.map((note) => (
                  <div key={note.id} className="note">
                    <div className="row between">
                      <strong>{note.author_name || 'Platform staff'}</strong>
                      <span className="muted tiny">{relative(note.created_at)}</span>
                    </div>
                    <p>{note.body}</p>
                    {note.is_pinned ? <Badge tone="amber">pinned</Badge> : null}
                  </div>
                ))}
              </div>
            )}
          </div>
        </Card>
      )}

      {tab === 'activity' && (
        <Card title="Activity" subtitle="Audit trail for this restaurant" padded={false}>
          <Table
            rows={activity}
            empty="Nothing recorded yet"
            columns={[
              { key: 'description', label: 'Event', render: (row) => (
                <div className="cell-stack">
                  <span>{row.description}</span>
                  <span className="muted tiny">{row.actor_name || titleCase(row.actor_type)} · {row.action}</span>
                </div>
              ) },
              { key: 'severity', label: 'Severity', render: (row) => <Badge tone={row.severity === 'critical' ? 'red' : row.severity === 'warning' ? 'amber' : 'slate'}>{titleCase(row.severity)}</Badge> },
              { key: 'created_at', label: 'When', align: 'right', render: (row) => <span className="muted">{relative(row.created_at)}</span> },
            ]}
          />
        </Card>
      )}

      {tab === 'danger' && (
        <Card title="Delete this restaurant" subtitle="Drops the tenant database and removes every trace">
          <div className="col gap-12">
            <div className="alert alert-error">
              This permanently deletes <strong>{tenant.name}</strong>, its database <code>{tenant.db_name}</code> and all
              orders, customers and configuration. This cannot be undone.
            </div>
            <Button variant="danger" onClick={() => setDeleteOpen(true)}>
              Delete {tenant.name}
            </Button>
          </div>
        </Card>
      )}

      {/* ---------- modals ---------- */}
      <Modal open={editOpen} onClose={() => setEditOpen(false)} title="Edit restaurant" width={620}
        footer={<><Button variant="ghost" onClick={() => setEditOpen(false)}>Cancel</Button><Button variant="primary" loading={busy === 'edit'} onClick={saveEdit}>Save changes</Button></>}>
        <div className="col gap-14">
          <div className="grid-2">
            <Field label="Name"><Input value={editForm.name || ''} onChange={(event) => setEditForm({ ...editForm, name: event.target.value })} /></Field>
            <Field label="Plan">
              <Select value={editForm.plan_id || ''} onChange={(event) => setEditForm({ ...editForm, plan_id: Number(event.target.value) })}>
                {plans.map((plan) => <option key={plan.id} value={plan.id}>{plan.name}</option>)}
              </Select>
            </Field>
          </div>
          <div className="grid-2">
            <Field label="Owner name"><Input value={editForm.owner_name || ''} onChange={(event) => setEditForm({ ...editForm, owner_name: event.target.value })} /></Field>
            <Field label="Owner email"><Input value={editForm.owner_email || ''} onChange={(event) => setEditForm({ ...editForm, owner_email: event.target.value })} /></Field>
          </div>
          <div className="grid-3">
            <Field label="City"><Input value={editForm.city || ''} onChange={(event) => setEditForm({ ...editForm, city: event.target.value })} /></Field>
            <Field label="Country"><Input value={editForm.country || ''} onChange={(event) => setEditForm({ ...editForm, country: event.target.value })} /></Field>
            <Field label="Phone"><Input value={editForm.owner_phone || ''} onChange={(event) => setEditForm({ ...editForm, owner_phone: event.target.value })} /></Field>
          </div>
          <div className="grid-3">
            <Field label="Currency"><Input value={editForm.currency || ''} onChange={(event) => setEditForm({ ...editForm, currency: event.target.value.toUpperCase() })} maxLength={3} /></Field>
            <Field label="Seats"><Input type="number" value={editForm.seat_count || 1} onChange={(event) => setEditForm({ ...editForm, seat_count: Number(event.target.value) })} /></Field>
            <Field label="Billing cycle">
              <Select value={editForm.billing_cycle || 'monthly'} onChange={(event) => setEditForm({ ...editForm, billing_cycle: event.target.value })}>
                <option value="monthly">Monthly</option>
                <option value="yearly">Yearly</option>
              </Select>
            </Field>
          </div>
          <Field label="Custom domain" hint="Requires the custom_domain feature; DNS points at the platform.">
            <Input value={editForm.custom_domain || ''} onChange={(event) => setEditForm({ ...editForm, custom_domain: event.target.value })} placeholder="orders.example.com" />
          </Field>
        </div>
      </Modal>

      <Modal open={statusOpen} onClose={() => setStatusOpen(false)} title="Suspend restaurant"
        footer={<><Button variant="ghost" onClick={() => setStatusOpen(false)}>Cancel</Button><Button variant="danger" loading={busy === 'status'} onClick={() => setStatus(statusForm.status, statusForm.reason).then(() => setStatusOpen(false))}>Suspend access</Button></>}>
        <div className="col gap-12">
          <Field label="Reason (shown to the tenant)">
            <Textarea value={statusForm.reason} onChange={(event) => setStatusForm({ ...statusForm, reason: event.target.value })} placeholder="Payment overdue — the card on file failed." />
          </Field>
          <p className="muted small">Owners keep access to billing and login so they can fix payment, but every other page returns a 402 page.</p>
        </div>
      </Modal>

      <Modal open={trialOpen} onClose={() => setTrialOpen(false)} title="Extend trial"
        footer={<><Button variant="ghost" onClick={() => setTrialOpen(false)}>Cancel</Button><Button variant="primary" loading={busy === 'trial'} onClick={() => run('trial', () => api.post(`/tenants/${id}/extend-trial`, { days: Number(trialDays) }), 'Trial extended').then(() => setTrialOpen(false))}>Extend</Button></>}>
        <Field label="Days to add" hint={`Current end: ${date(tenant.trial_ends_at)}`}>
          <Input type="number" min="1" max="180" value={trialDays} onChange={(event) => setTrialDays(event.target.value)} />
        </Field>
      </Modal>

      <Modal open={impersonateOpen} onClose={() => setImpersonateOpen(false)} title="Sign in as the owner"
        footer={<><Button variant="ghost" onClick={() => setImpersonateOpen(false)}>Cancel</Button><Button variant="primary" loading={busy === 'impersonate'} onClick={impersonate}>Open support session</Button></>}>
        <div className="col gap-12">
          <Field label="Why? (recorded in the audit log)">
            <Input value={impersonateReason} onChange={(event) => setImpersonateReason(event.target.value)} />
          </Field>
          <p className="muted small">
            Opens the tenant admin in a new tab with a purple “support session” banner. The link is single-use and expires
            in 24 hours; the owner sees the session in their notification feed.
          </p>
        </div>
      </Modal>

      <Modal open={deleteOpen} onClose={() => setDeleteOpen(false)} title={`Delete ${tenant.name}`}
        footer={<><Button variant="ghost" onClick={() => setDeleteOpen(false)}>Cancel</Button><Button variant="danger" disabled={deleteConfirm !== tenant.slug} loading={busy === 'delete'} onClick={destroy}>Delete permanently</Button></>}>
        <div className="col gap-12">
          <p>Type <code>{tenant.slug}</code> to confirm.</p>
          <Input value={deleteConfirm} onChange={(event) => setDeleteConfirm(event.target.value)} placeholder={tenant.slug} />
        </div>
      </Modal>
    </div>
  );
}
