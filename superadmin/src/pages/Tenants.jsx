import { useCallback, useEffect, useMemo, useState } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import { api } from '../lib/api';
import { date, money, number, relative, titleCase } from '../lib/format';
import { useSession } from '../lib/session';
import {
  Avatar, Badge, Button, Card, Empty, Field, Input, Modal, Select, StatusBadge, Table, Toasts, useToasts,
} from '../components/ui';

const STATUS_FILTERS = ['all', 'active', 'trial', 'past_due', 'suspended', 'cancelled'];

const EMPTY_FORM = {
  name: '',
  owner_name: '',
  owner_email: '',
  owner_phone: '',
  city: '',
  country: '',
  plan_id: '',
  billing_cycle: 'monthly',
  seat_count: 5,
  trial_days: 14,
  seed_demo_data: true,
};

export default function Tenants() {
  const navigate = useNavigate();
  const [params, setParams] = useSearchParams();
  const { can } = useSession();
  const { toasts, push, dismiss } = useToasts();

  const [rows, setRows] = useState([]);
  const [meta, setMeta] = useState({ counts: {}, total_all: 0, page: 1, last_page: 1, total: 0 });
  const [plans, setPlans] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);

  const [search, setSearch] = useState(params.get('search') || '');
  const status = params.get('status') || 'all';
  const page = Number(params.get('page') || 1);
  const sort = params.get('sort') || 'created_at';
  const direction = params.get('direction') || 'DESC';

  const [creating, setCreating] = useState(params.get('new') === '1');
  const [form, setForm] = useState(EMPTY_FORM);
  const [formError, setFormError] = useState(null);
  const [fieldErrors, setFieldErrors] = useState({});
  const [busy, setBusy] = useState(false);
  const [created, setCreated] = useState(null);

  const update = useCallback(
    (patch) => {
      const next = new URLSearchParams(params);
      Object.entries(patch).forEach(([key, value]) => {
        if (value === undefined || value === null || value === '' || value === 'all' || value === 1 && key === 'page') next.delete(key);
        else next.set(key, value);
      });
      setParams(next, { replace: true });
    },
    [params, setParams],
  );

  useEffect(() => {
    api.get('/plans').then((response) => setPlans(response.data || [])).catch(() => {});
  }, []);

  useEffect(() => {
    let cancelled = false;
    setLoading(true);
    api
      .get('/tenants', { status, search, page, per_page: 25, sort, direction })
      .then((response) => {
        if (cancelled) return;
        setRows(response.data || []);
        setMeta(response.meta || {});
        setError(null);
      })
      .catch((failure) => !cancelled && setError(failure.message))
      .finally(() => !cancelled && setLoading(false));
    return () => {
      cancelled = true;
    };
  }, [status, search, page, sort, direction]);

  const counts = meta.counts || {};
  const selectedPlan = useMemo(() => plans.find((plan) => String(plan.id) === String(form.plan_id)), [plans, form.plan_id]);

  useEffect(() => {
    if (!form.plan_id && plans.length) {
      const fallback = plans.find((plan) => plan.code === 'growth') || plans[0];
      setForm((current) => ({ ...current, plan_id: fallback.id, trial_days: fallback.trial_days ?? 14 }));
    }
  }, [plans, form.plan_id]);

  const createTenant = async (event) => {
    event.preventDefault();
    setBusy(true);
    setFormError(null);
    setFieldErrors({});
    try {
      const response = await api.post('/tenants', { ...form, plan_id: Number(form.plan_id), seat_count: Number(form.seat_count), trial_days: Number(form.trial_days) });
      setCreated(response.data);
      setForm(EMPTY_FORM);
      update({ status: 'all' });
      const refreshed = await api.get('/tenants', { status: 'all', per_page: 25, page: 1 });
      setRows(refreshed.data || []);
      setMeta(refreshed.meta || {});
    } catch (failure) {
      setFormError(failure.message);
      setFieldErrors(failure.details || {});
    } finally {
      setBusy(false);
    }
  };

  const toggleSort = (column) =>
    update({ sort: column, direction: sort === column && direction === 'DESC' ? 'ASC' : 'DESC' });

  const sortIcon = (column) => (sort === column ? (direction === 'DESC' ? '↓' : '↑') : '');

  return (
    <div className="col gap-18">
      <header className="page-head">
        <div>
          <h1>Restaurants</h1>
          <p className="muted">
            {number(meta.total_all || 0)} tenants · {counts.active || 0} active · {counts.trial || 0} trialling
          </p>
        </div>
        {can('tenants.create') && (
          <Button variant="primary" onClick={() => setCreating(true)}>
            + New restaurant
          </Button>
        )}
      </header>

      <div className="filter-row">
        <div className="segmented">
          {STATUS_FILTERS.map((value) => (
            <button key={value} className={status === value ? 'active' : ''} onClick={() => update({ status: value, page: 1 })}>
              {titleCase(value)} {value !== 'all' && counts[value] ? <span className="pill">{counts[value]}</span> : null}
            </button>
          ))}
        </div>
        <div className="grow" />
        <form
          className="search inline"
          onSubmit={(event) => {
            event.preventDefault();
            update({ search, page: 1 });
          }}
        >
          <span>⌕</span>
          <input value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Name, owner or email" />
        </form>
        <Select value={sort} onChange={(event) => update({ sort: event.target.value, page: 1 })}>
          <option value="created_at">Newest</option>
          <option value="name">Name</option>
          <option value="mrr">MRR</option>
          <option value="last_activity_at">Last activity</option>
          <option value="status">Status</option>
        </Select>
      </div>

      <Card padded={false}>
        <Table
          loading={loading && !rows.length}
          rows={rows}
          empty={error || 'No restaurants match those filters'}
          rowKey={(row) => row.id}
          onRowClick={(row) => navigate(`/tenants/${row.id}`)}
          columns={[
            {
              key: 'name',
              label: 'Restaurant',
              render: (row) => (
                <div className="row gap-10">
                  <Avatar name={row.name} tone={row.plan?.accent_color} />
                  <div className="cell-stack">
                    <div className="row gap-6" style={{ alignItems: 'center' }}>
                      <strong>{row.name}</strong>
                      <Badge tone="purple" style={{ fontSize: '0.72rem', letterSpacing: '0.08em', padding: '1px 5px', fontWeight: 600 }}>
                        {row.access_code}
                      </Badge>
                    </div>
                    <span className="muted tiny">
                      {row.slug} · {row.owner_name} ({row.owner_email})
                    </span>
                  </div>
                </div>
              ),
            },
            {
              key: 'links',
              label: 'Platform Links',
              render: (row) => {
                const storefront = row.links?.storefront || row.unique_url;
                const adminUrl = row.links?.admin || row.admin_url;
                return (
                  <div className="row gap-6 wrap" onClick={(event) => event.stopPropagation()} style={{ alignItems: 'center' }}>
                    <Button
                      size="sm"
                      variant="ghost"
                      style={{ padding: '2px 8px', fontSize: '0.78rem' }}
                      title="Open storefront"
                      onClick={() => window.open(storefront, '_blank', 'noopener')}
                    >
                      Storefront ↗
                    </Button>
                    <Button
                      size="sm"
                      variant="ghost"
                      style={{ padding: '2px 8px', fontSize: '0.78rem' }}
                      title="Open admin login"
                      onClick={() => window.open(adminUrl, '_blank', 'noopener')}
                    >
                      Admin ↗
                    </Button>
                    <Button
                      size="sm"
                      variant="ghost"
                      style={{ padding: '2px 6px', fontSize: '0.78rem' }}
                      title="Copy storefront link"
                      onClick={() => {
                        navigator.clipboard.writeText(storefront);
                        push('Copied', `Copied link for ${row.name}`, 'success');
                      }}
                    >
                      📋
                    </Button>
                  </div>
                );
              },
            },
            { key: 'plan', label: 'Plan', render: (row) => <Badge tone="indigo">{row.plan?.name || '—'}</Badge> },
            {
              key: 'status',
              label: 'Status',
              render: (row) => (
                <div className="cell-stack">
                  <StatusBadge status={row.status} />
                  {row.on_trial && row.trial_days_left !== null && (
                    <span className="muted tiny">{row.trial_days_left <= 0 ? 'trial ended' : `${row.trial_days_left}d of trial left`}</span>
                  )}
                </div>
              ),
            },
            { key: 'mrr', label: 'MRR', align: 'right', sortable: true, render: (row) => money(row.mrr, row.currency) },
            {
              key: 'last_activity_at',
              label: 'Last activity',
              align: 'right',
              render: (row) => <span className="muted">{row.last_activity_ago || 'never'}</span>,
            },
            { key: 'created_at', label: 'Joined', align: 'right', render: (row) => <span className="muted">{date(row.created_at)}</span> },
            {
              key: 'actions',
              label: '',
              align: 'right',
              width: 90,
              render: (row) => (
                <Link className="btn btn-ghost btn-sm" to={`/tenants/${row.id}`} onClick={(event) => event.stopPropagation()}>
                  Manage →
                </Link>
              ),
            },
          ]}
        />
      </Card>

      {meta.last_page > 1 && (
        <div className="pager">
          <Button size="sm" disabled={page <= 1} onClick={() => update({ page: page - 1 })}>
            ← Previous
          </Button>
          <span className="muted small">
            Page {meta.page} of {meta.last_page} · {number(meta.total)} restaurants
          </span>
          <Button size="sm" disabled={page >= meta.last_page} onClick={() => update({ page: page + 1 })}>
            Next →
          </Button>
        </div>
      )}

      <Modal
        open={creating}
        onClose={() => {
          setCreating(false);
          setCreated(null);
          update({ new: undefined });
        }}
        title={created ? 'Restaurant is ready' : 'Onboard a restaurant'}
        subtitle={created ? 'Share these credentials with the owner — the password is shown once.' : 'Creates the tenant database, seeds the catalogue and starts the trial.'}
        width={620}
        footer={
          created ? (
            <Button variant="primary" onClick={() => navigate(`/tenants/${created.tenant.id}`)}>
              Open {created.tenant.name}
            </Button>
          ) : (
            <>
              <Button variant="ghost" onClick={() => setCreating(false)}>
                Cancel
              </Button>
              <Button variant="primary" form="create-tenant" type="submit" loading={busy}>
                Create & provision
              </Button>
            </>
          )
        }
      >
        {created ? (
          <div className="col gap-12">
            <div className="alert alert-success">
              <strong>{created.tenant.name}</strong> is live on the {created.tenant.plan?.name} plan.
            </div>
            <dl className="kv">
              <dt>Storefront Link</dt>
              <dd className="row gap-8" style={{ alignItems: 'center' }}>
                <a className="link" href={created.tenant.links?.storefront || created.tenant.unique_url} target="_blank" rel="noreferrer" style={{ wordBreak: 'break-all' }}>
                  {created.tenant.links?.storefront || created.tenant.unique_url}
                </a>
                <Button size="sm" variant="ghost" onClick={() => {
                  navigator.clipboard.writeText(created.tenant.links?.storefront || created.tenant.unique_url);
                  push('Copied', 'Storefront link copied', 'success');
                }}>Copy</Button>
              </dd>
              <dt>Admin Portal</dt>
              <dd className="row gap-8" style={{ alignItems: 'center' }}>
                <a className="link" href={created.tenant.links?.admin || created.tenant.admin_url} target="_blank" rel="noreferrer" style={{ wordBreak: 'break-all' }}>
                  {created.tenant.links?.admin || created.tenant.admin_url}
                </a>
                <Button size="sm" variant="ghost" onClick={() => {
                  navigator.clipboard.writeText(created.tenant.links?.admin || created.tenant.admin_url);
                  push('Copied', 'Admin link copied', 'success');
                }}>Copy</Button>
              </dd>
              <dt>Access Code</dt>
              <dd className="row gap-8" style={{ alignItems: 'center' }}>
                <Badge tone="purple" style={{ fontWeight: 700, letterSpacing: '0.1em' }}>
                  {created.tenant.access_code}
                </Badge>
                <Button size="sm" variant="ghost" onClick={() => {
                  navigator.clipboard.writeText(created.tenant.access_code);
                  push('Copied', 'Access code copied', 'success');
                }}>Copy</Button>
              </dd>
              <dt>Admin Username</dt>
              <dd>
                <code>{created.admin_username}</code>
              </dd>
              <dt>Password</dt>
              <dd>
                <code className="secret">{created.owner_password}</code>
              </dd>
              <dt>Database</dt>
              <dd>
                <code>{created.tenant.db_name}</code>
              </dd>
            </dl>
            <Button
              size="sm"
              variant="secondary"
              style={{ width: '100%', marginTop: 4 }}
              onClick={() => {
                const sUrl = created.tenant.links?.storefront || created.tenant.unique_url;
                const aUrl = created.tenant.links?.admin || created.tenant.admin_url;
                const note = `Restaurant: ${created.tenant.name}
Storefront URL: ${sUrl}
Admin Portal: ${aUrl}
Access Code: ${created.tenant.access_code}
Admin User: ${created.admin_username}
Admin Password: ${created.owner_password}`;
                navigator.clipboard.writeText(note);
                push('Copied All', 'Onboarding credentials copied to clipboard', 'success');
              }}
            >
              📋 Copy All Credentials (to send to Owner)
            </Button>
          </div>
        ) : (
          <form id="create-tenant" className="col gap-14" onSubmit={createTenant}>
            <div className="grid-2">
              <Field label="Restaurant name" error={fieldErrors.name}>
                <Input value={form.name} onChange={(event) => setForm({ ...form, name: event.target.value })} placeholder="Aurora Kitchen" required />
              </Field>
              <Field label="Plan">
                <Select value={form.plan_id} onChange={(event) => setForm({ ...form, plan_id: event.target.value, trial_days: plans.find((plan) => String(plan.id) === event.target.value)?.trial_days ?? form.trial_days })}>
                  {plans.map((plan) => (
                    <option key={plan.id} value={plan.id}>
                      {plan.name} — {money(plan.price_monthly, plan.currency)}/mo
                    </option>
                  ))}
                </Select>
              </Field>
            </div>

            <div className="grid-2">
              <Field label="Owner name" error={fieldErrors.owner_name}>
                <Input value={form.owner_name} onChange={(event) => setForm({ ...form, owner_name: event.target.value })} placeholder="Adjoa Mensah" required />
              </Field>
              <Field label="Owner email" error={fieldErrors.owner_email}>
                <Input type="email" value={form.owner_email} onChange={(event) => setForm({ ...form, owner_email: event.target.value })} placeholder="owner@restaurant.test" required />
              </Field>
            </div>

            <div className="grid-3">
              <Field label="City">
                <Input value={form.city} onChange={(event) => setForm({ ...form, city: event.target.value })} placeholder="Accra" />
              </Field>
              <Field label="Country">
                <Input value={form.country} onChange={(event) => setForm({ ...form, country: event.target.value })} placeholder="Ghana" />
              </Field>
              <Field label="Phone">
                <Input value={form.owner_phone} onChange={(event) => setForm({ ...form, owner_phone: event.target.value })} placeholder="+233 …" />
              </Field>
            </div>

            <div className="grid-3">
              <Field label="Billing cycle">
                <Select value={form.billing_cycle} onChange={(event) => setForm({ ...form, billing_cycle: event.target.value })}>
                  <option value="monthly">Monthly</option>
                  <option value="yearly">Yearly</option>
                </Select>
              </Field>
              <Field label="Trial days" hint={selectedPlan ? `Plan default: ${selectedPlan.trial_days}` : undefined}>
                <Input type="number" min="0" max="90" value={form.trial_days} onChange={(event) => setForm({ ...form, trial_days: event.target.value })} />
              </Field>
              <Field label="Staff seats" hint={selectedPlan ? `Included: ${selectedPlan.limits?.staff_users ?? 'unlimited'}` : undefined}>
                <Input type="number" min="1" value={form.seat_count} onChange={(event) => setForm({ ...form, seat_count: event.target.value })} />
              </Field>
            </div>

            <label className="checkbox">
              <input type="checkbox" checked={form.seed_demo_data} onChange={(event) => setForm({ ...form, seed_demo_data: event.target.checked })} />
              Seed demo menu, tables and orders so the owner can explore immediately
            </label>

            {formError && <div className="alert alert-error">{formError}</div>}
          </form>
        )}
      </Modal>
      <Toasts toasts={toasts} dismiss={dismiss} />
    </div>
  );
}
