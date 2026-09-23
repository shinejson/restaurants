import { useState } from 'react';
import { Button, Field, Input } from '../components/ui';
import { useSession } from '../lib/session';

export default function Login({ expired = false }) {
  const { login } = useSession();
  const [email, setEmail] = useState('owner@restaurantos.test');
  const [password, setPassword] = useState('');
  const [remember, setRemember] = useState(true);
  const [error, setError] = useState(null);
  const [busy, setBusy] = useState(false);

  const submit = async (event) => {
    event.preventDefault();
    setBusy(true);
    setError(null);
    try {
      await login(email, password, remember);
    } catch (failure) {
      setError(failure.message || 'Could not sign in');
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="login-page">
      <div className="login-card">
        <span className="brand-mark big">R</span>
        <h1>Platform console</h1>
        <p className="muted">
          Manage restaurants, subscriptions and support sessions for every tenant on RestaurantOS.
        </p>

        <form onSubmit={submit} className="col gap-14">
          <Field label="Work email">
            <Input type="email" value={email} onChange={(event) => setEmail(event.target.value)} autoComplete="username" required />
          </Field>
          <Field label="Password">
            <Input
              type="password"
              value={password}
              onChange={(event) => setPassword(event.target.value)}
              autoComplete="current-password"
              placeholder="••••••••"
              required
            />
          </Field>

          <label className="checkbox">
            <input type="checkbox" checked={remember} onChange={(event) => setRemember(event.target.checked)} />
            Keep me signed in on this device
          </label>

          {expired && (
            <div className="alert alert-warning">Your session has expired. Please sign in again.</div>
          )}

          {error && <div className="alert alert-error">{error}</div>}

          <Button type="submit" variant="primary" loading={busy} className="btn-block">
            Sign in
          </Button>
        </form>

        <p className="muted tiny center">
          Demo owner credentials live in <code>config/.env</code> — the seeded account is
          <code> owner@restaurantos.test</code>.
        </p>
      </div>
    </div>
  );
}
