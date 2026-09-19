import { useEffect, useState } from 'react';
import { api } from '../lib/api';
import { limitLabel, money, number, titleCase } from '../lib/format';
import { Badge, Button, Card, Empty, Field, Input, Modal, Select, Spinner, Textarea, Toasts, useToasts } from '../components/ui';

const BLANK = {
  code: '', name: '', tagline: '', description: '', price_monthly: 0, price_yearly: 0,
  currency: 'USD', trial_days: 14, badge: '', accent_color: '#6366f1', sort_order: 99,
  is_public: 1, limits: {}, features: {},
};

export default function Plans() {
  const [plans, setPlans] = useState(null);
  const [catalog, setCatalog] = useState({ features: {}, limits: {} });
  const [error, setError] = useState(null);
  const [editing, setEditing] = useState(null);
  const [busy, setBusy] = useState(false);
  const { toasts, push, dismiss } = useToasts();

  const load = () =>
    api
      .get('/plans')
      .then((response) => {
        setPlans(response.data || []);
        setCatalog(response.catalog || { features: {}, limits: {} });
      })
      .catch((failure) => setError(failure.message));

  useEffect(() => {
    load();
  }, []);

  const save = async () => {
    setBusy(true);
    try {
      const payload = { ...editing, price_monthly: Number(editing.price_monthly), price_yearly: Number(editing.price_yearly), trial_days: Number(editing.trial_days), sort_order: Number(editing.sort_order) };
      if (editing.id) await api.patch(`/plans/${editing.id}`, payload);
      else await api.post('/plans', payload);
      push('Saved', `${editing.name} is up to date`, 'success');
      setEditing(null);
      load();
    } catch (failure) {
      push('Could not save the plan', failure.message, 'error');
    } finally {
      setBusy(false);
    }
  };

  const archive = async (plan) => {
    try {
      await api.post(`/plans/${plan.id}/archive`, {});
      push('Plan archived', `${plan.name} will not be offered to new tenants`, 'success');
      load();
    } catch (failure) {
      push('Could not archive', failure.message, 'error');
    }
  };

  if (error) return <Empty title="Could not load plans" body={error} />;
  if (!plans) return <Spinner label="Loading plans…" />;

  return (
    <div className="col gap-18">
      <Toasts toasts={toasts} dismiss={dismiss} />

      <header className="page-head">
        <div>
          <h1>Plans</h1>
          <p className="muted">{plans.length} products · edit pricing, limits and entitlements</p>
        </div>
        <Button variant="primary" onClick={() => setEditing({ ...BLANK, limits: {}, features: {} })}>
          + New plan
        </Button>
      </header>

      <div className="plan-grid">
        {plans.map((plan) => (
          <Card key={plan.id} className={plan.is_archived ? 'archived' : ''}>
            <div className="col gap-12">
              <div className="row between">
                <div>
                  <div className="row gap-8">
                    <h3 style={{ color: plan.accent_color }}>{plan.name}</h3>
                    {plan.badge && <Badge tone="amber">{plan.badge}</Badge>}
                    {plan.is_archived ? <Badge tone="slate">archived</Badge> : null}
                  </div>
                  <p className="muted small">{plan.tagline}</p>
                </div>
                <div className="price-tag">
                  <strong>{money(plan.price_monthly, plan.currency)}</strong>
                  <span className="muted tiny">/mo · {money(plan.price_yearly, plan.currency)}/yr</span>
                </div>
              </div>

              <p className="muted small">{plan.description}</p>

              <dl className="kv compact">
                <dt>Trials</dt>
                <dd>{plan.trial_days} days</dd>
                <dt>Tenants</dt>
                <dd>{number(plan.tenants ?? 0)}</dd>
                <dt>MRR</dt>
                <dd>{money(plan.mrr ?? 0, plan.currency)}</dd>
              </dl>

              <div>
                <span className="muted tiny">LIMITS</span>
                <div className="chips">
                  {Object.entries(plan.limits || {}).map(([key, value]) => (
                    <span key={key} className="chip">
                      {catalog.limits[key]?.label || titleCase(key)}: <b>{limitLabel(value)}</b>
                    </span>
                  ))}
                </div>
              </div>

              <div>
                <span className="muted tiny">FEATURES</span>
                <div className="chips">
                  {Object.entries(plan.features || {})
                    .filter(([, enabled]) => enabled)
                    .map(([key]) => (
                      <span key={key} className="chip on">
                        {catalog.features[key]?.label || titleCase(key)}
                      </span>
                    ))}
                  {Object.values(plan.features || {}).every((value) => !value) && <span className="muted tiny">none</span>}
                </div>
              </div>

              <div className="row gap-8">
                <Button size="sm" onClick={() => setEditing({ ...plan, limits: plan.limits || {}, features: plan.features || {} })}>
                  Edit plan
                </Button>
                {!plan.is_archived && (
                  <Button size="sm" variant="ghost" onClick={() => archive(plan)}>
                    Archive
                  </Button>
                )}
              </div>
            </div>
          </Card>
        ))}
      </div>

      <Modal
        open={Boolean(editing)}
        onClose={() => setEditing(null)}
        title={editing?.id ? `Edit ${editing.name}` : 'New plan'}
        subtitle="Limits accept a number; -1 means unlimited and 0 means not metered."
        width={720}
        footer={
          <>
            <Button variant="ghost" onClick={() => setEditing(null)}>Cancel</Button>
            <Button variant="primary" loading={busy} onClick={save}>Save plan</Button>
          </>
        }
      >
        {editing && (
          <div className="col gap-14">
            <div className="grid-3">
              <Field label="Name"><Input value={editing.name} onChange={(event) => setEditing({ ...editing, name: event.target.value })} /></Field>
              <Field label="Code" hint="Used in URLs and API payloads">
                <Input value={editing.code} onChange={(event) => setEditing({ ...editing, code: event.target.value })} disabled={Boolean(editing.id)} />
              </Field>
              <Field label="Badge"><Input value={editing.badge || ''} onChange={(event) => setEditing({ ...editing, badge: event.target.value })} placeholder="Most popular" /></Field>
            </div>
            <Field label="Tagline"><Input value={editing.tagline || ''} onChange={(event) => setEditing({ ...editing, tagline: event.target.value })} /></Field>
            <Field label="Description"><Textarea value={editing.description || ''} onChange={(event) => setEditing({ ...editing, description: event.target.value })} /></Field>
            <div className="grid-4">
              <Field label="Monthly price"><Input type="number" step="0.01" value={editing.price_monthly} onChange={(event) => setEditing({ ...editing, price_monthly: event.target.value })} /></Field>
              <Field label="Yearly price"><Input type="number" step="0.01" value={editing.price_yearly} onChange={(event) => setEditing({ ...editing, price_yearly: event.target.value })} /></Field>
              <Field label="Trial days"><Input type="number" value={editing.trial_days} onChange={(event) => setEditing({ ...editing, trial_days: event.target.value })} /></Field>
              <Field label="Accent">
                <Select value={editing.accent_color} onChange={(event) => setEditing({ ...editing, accent_color: event.target.value })}>
                  {['#6366f1', '#8b5cf6', '#0ea5e9', '#10b981', '#f59e0b', '#ef4444'].map((color) => (
                    <option key={color} value={color}>{color}</option>
                  ))}
                </Select>
              </Field>
            </div>

            <div>
              <span className="field-label">Limits</span>
              <div className="grid-2 tight">
                {Object.entries(catalog.limits).map(([key, definition]) => (
                  <div key={key} className="row gap-10 between">
                    <span className="small">{definition.label}</span>
                    <Input
                      type="number"
                      className="input-inline"
                      value={editing.limits?.[key] ?? ''}
                      placeholder="unlimited"
                      onChange={(event) =>
                        setEditing({ ...editing, limits: { ...editing.limits, [key]: event.target.value === '' ? null : Number(event.target.value) } })
                      }
                    />
                  </div>
                ))}
              </div>
            </div>

            <div>
              <span className="field-label">Features</span>
              <div className="grid-2 tight">
                {Object.entries(catalog.features).map(([key, definition]) => (
                  <label key={key} className="checkbox">
                    <input
                      type="checkbox"
                      checked={Boolean(editing.features?.[key])}
                      onChange={(event) => setEditing({ ...editing, features: { ...editing.features, [key]: event.target.checked } })}
                    />
                    {definition.label}
                  </label>
                ))}
              </div>
            </div>
          </div>
        )}
      </Modal>
    </div>
  );
}
