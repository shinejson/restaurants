import { useCallback, useEffect, useState } from 'react';
import { api } from '../lib/api';
import { relative, titleCase } from '../lib/format';
import { useSession } from '../lib/session';
import { Avatar, Badge, Button, Card, Field, Input, Modal, Select, Spinner, Table, Toasts, useToasts } from '../components/ui';

export default function Team() {
  const { user, refresh } = useSession();
  const { toasts, push, dismiss } = useToasts();
  const [rows, setRows] = useState(null);
  const [roles, setRoles] = useState({});
  const [invite, setInvite] = useState(false);
  const [form, setForm] = useState({ name: '', email: '', role: 'admin', password: '' });
  const [busy, setBusy] = useState(null);
  const [passwordOpen, setPasswordOpen] = useState(false);
  const [passwordForm, setPasswordForm] = useState({ current_password: '', password: '', password_confirmation: '' });

  const load = useCallback(() => {
    api
      .get('/team')
      .then((response) => {
        setRows(response.data || []);
        setRoles(response.meta?.roles || {});
      })
      .catch((failure) => push('Could not load the team', failure.message, 'error'));
  }, [push]);

  useEffect(() => {
    load();
  }, [load]);

  const create = async () => {
    setBusy('create');
    try {
      const response = await api.post('/team', form);
      push('Invited', `${form.name} can now sign in${response.data?.generated_password ? ` with ${response.data.generated_password}` : ''}`, 'success');
      setInvite(false);
      setForm({ name: '', email: '', role: 'admin', password: '' });
      load();
    } catch (failure) {
      push('Could not invite', failure.message, 'error');
    } finally {
      setBusy(null);
    }
  };

  const patch = async (member, changes) => {
    setBusy(member.id);
    try {
      await api.patch(`/team/${member.id}`, changes);
      push('Updated', `${member.name} saved`, 'success');
      load();
      if (member.id === user?.id) refresh();
    } catch (failure) {
      push('Could not update', failure.message, 'error');
    } finally {
      setBusy(null);
    }
  };

  const remove = async (member) => {
    setBusy(member.id);
    try {
      await api.delete(`/team/${member.id}`);
      push('Removed', `${member.name} no longer has access`, 'success');
      load();
    } catch (failure) {
      push('Could not remove', failure.message, 'error');
    } finally {
      setBusy(null);
    }
  };

  const changePassword = async () => {
    setBusy('password');
    try {
      await api.post('/auth/password', passwordForm);
      push('Password updated', 'Use it the next time you sign in', 'success');
      setPasswordOpen(false);
      setPasswordForm({ current_password: '', password: '', password_confirmation: '' });
    } catch (failure) {
      push('Could not change the password', failure.message, 'error');
    } finally {
      setBusy(null);
    }
  };

  if (!rows) return <Spinner label="Loading team…" />;

  return (
    <div className="col gap-18">
      <Toasts toasts={toasts} dismiss={dismiss} />

      <header className="page-head">
        <div>
          <h1>Platform team</h1>
          <p className="muted">Who can reach the console, and what they are allowed to do</p>
        </div>
        <div className="row gap-8">
          <Button variant="secondary" onClick={() => setPasswordOpen(true)}>Change my password</Button>
          <Button variant="primary" onClick={() => setInvite(true)}>+ Invite teammate</Button>
        </div>
      </header>

      <Card padded={false}>
        <Table
          rows={rows}
          empty="No platform users"
          columns={[
            { key: 'name', label: 'Person', render: (row) => (
              <div className="row gap-10">
                <Avatar name={row.name} size={30} />
                <div className="cell-stack">
                  <strong>{row.name} {row.id === user?.id ? <span className="muted tiny">(you)</span> : null}</strong>
                  <span className="muted tiny">{row.email}</span>
                </div>
              </div>
            ) },
            { key: 'role', label: 'Role', render: (row) => (
              <Select
                value={row.role}
                disabled={busy === row.id}
                onChange={(event) => patch(row, { role: event.target.value })}
                className="input-inline"
              >
                {Object.entries(roles).map(([slug, definition]) => (
                  <option key={slug} value={slug}>{definition.label || titleCase(slug)}</option>
                ))}
              </Select>
            ) },
            { key: 'is_active', label: 'Status', render: (row) => (
              <Badge tone={row.is_active ? 'green' : 'slate'} dot>{row.is_active ? 'Active' : 'Disabled'}</Badge>
            ) },
            { key: 'last_login_at', label: 'Last seen', align: 'right', render: (row) => <span className="muted">{row.last_login_at ? relative(row.last_login_at) : 'never'}</span> },
            { key: 'created_at', label: 'Added', align: 'right', render: (row) => <span className="muted">{relative(row.created_at)}</span> },
            { key: 'actions', label: '', align: 'right', render: (row) => (
              row.id === user?.id ? (
                <span className="muted tiny">current session</span>
              ) : (
                <div className="row gap-6 end">
                  <Button size="xs" variant="ghost" loading={busy === row.id} onClick={() => patch(row, { is_active: row.is_active ? 0 : 1 })}>
                    {row.is_active ? 'Disable' : 'Enable'}
                  </Button>
                  <Button size="xs" variant="ghost" onClick={() => remove(row)}>Remove</Button>
                </div>
              )
            ) },
          ]}
        />
      </Card>

      <Card title="Role matrix" subtitle="What each platform role can do">
        <div className="role-grid">
          {Object.entries(roles).map(([slug, definition]) => (
            <div key={slug} className="role-card">
              <strong>{definition.label || titleCase(slug)}</strong>
              <p className="muted small">{definition.description}</p>
              <div className="chips">
                {(definition.permissions || []).map((permission) => (
                  <span key={permission} className="chip">{permission}</span>
                ))}
              </div>
            </div>
          ))}
        </div>
      </Card>

      <Modal
        open={invite}
        onClose={() => setInvite(false)}
        title="Invite a teammate"
        subtitle="They can sign in immediately with the password you set (or we generate one)."
        footer={<><Button variant="ghost" onClick={() => setInvite(false)}>Cancel</Button><Button variant="primary" loading={busy === 'create'} onClick={create}>Send invite</Button></>}
      >
        <div className="col gap-14">
          <Field label="Full name"><Input value={form.name} onChange={(event) => setForm({ ...form, name: event.target.value })} /></Field>
          <Field label="Email"><Input type="email" value={form.email} onChange={(event) => setForm({ ...form, email: event.target.value })} /></Field>
          <Field label="Role">
            <Select value={form.role} onChange={(event) => setForm({ ...form, role: event.target.value })}>
              {Object.entries(roles).map(([slug, definition]) => (
                <option key={slug} value={slug}>{definition.label || titleCase(slug)}</option>
              ))}
            </Select>
          </Field>
          <Field label="Temporary password" hint="Leave blank to have one generated.">
            <Input value={form.password} onChange={(event) => setForm({ ...form, password: event.target.value })} placeholder="at least 10 characters" />
          </Field>
        </div>
      </Modal>

      <Modal
        open={passwordOpen}
        onClose={() => setPasswordOpen(false)}
        title="Change your password"
        footer={<><Button variant="ghost" onClick={() => setPasswordOpen(false)}>Cancel</Button><Button variant="primary" loading={busy === 'password'} onClick={changePassword}>Update password</Button></>}
      >
        <div className="col gap-14">
          <Field label="Current password"><Input type="password" value={passwordForm.current_password} onChange={(event) => setPasswordForm({ ...passwordForm, current_password: event.target.value })} /></Field>
          <Field label="New password" hint="Minimum 10 characters."><Input type="password" value={passwordForm.password} onChange={(event) => setPasswordForm({ ...passwordForm, password: event.target.value })} /></Field>
          <Field label="Repeat new password"><Input type="password" value={passwordForm.password_confirmation} onChange={(event) => setPasswordForm({ ...passwordForm, password_confirmation: event.target.value })} /></Field>
        </div>
      </Modal>
    </div>
  );
}
