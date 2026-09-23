import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { api, setCsrfToken } from './api';

const SessionContext = createContext(null);

/** Re-validate the session periodically while the console stays open. */
const HEARTBEAT_MS = 60_000;
/** setTimeout ceiling (~24.8 days) — longer waits re-arm themselves. */
const MAX_TIMEOUT_MS = 2_147_483_647;

/** Parse the API's UTC "Y-m-d H:i:s" expiry into epoch milliseconds. */
const expiryToMs = (value) => {
  if (!value) return null;
  const iso = String(value).includes('T') ? String(value) : String(value).replace(' ', 'T');
  const ms = Date.parse(/(?:Z|[+-]\d{2}:?\d{2})$/.test(iso) ? iso : `${iso}Z`);
  return Number.isNaN(ms) ? null : ms;
};

export function SessionProvider({ children }) {
  const navigate = useNavigate();
  const [user, setUser] = useState(null);
  const [permissions, setPermissions] = useState({});
  const [unread, setUnread] = useState(0);
  const [loading, setLoading] = useState(true);
  const [expired, setExpired] = useState(false);
  const [expiresAtMs, setExpiresAtMs] = useState(null);
  const userRef = useRef(null);

  useEffect(() => {
    userRef.current = user;
  }, [user]);

  const clearSession = useCallback(() => {
    setCsrfToken(null);
    setUser(null);
    setPermissions({});
    setUnread(0);
    setExpiresAtMs(null);
  }, []);

  const refresh = useCallback(async () => {
    try {
      const response = await api.get('/auth/me');
      if (!response?.data?.user) {
        clearSession();
        return null;
      }
      setCsrfToken(response.data.csrf_token || null);
      setUser(response.data.user);
      setPermissions(response.data.can || {});
      setUnread(response.data.unread || 0);
      setExpiresAtMs(expiryToMs(response.data.session_expires_at));
      return response.data.user;
    } catch (failure) {
      // Only a definitive answer from the API signs the user out — a network
      // blip or a 5xx must never destroy a healthy session.
      const status = failure?.status ?? 0;
      if (status !== 0 && status < 500) {
        if (status === 401 && userRef.current) setExpired(true);
        clearSession();
      }
      return null;
    } finally {
      setLoading(false);
    }
  }, [clearSession]);

  useEffect(() => {
    refresh();
  }, [refresh]);

  useEffect(() => {
    const handleUnauthorized = () => {
      // The API only sends 401 when the session is gone (expired/revoked).
      if (userRef.current) setExpired(true);
      clearSession();
    };

    window.addEventListener('platform:unauthorized', handleUnauthorized);
    return () => window.removeEventListener('platform:unauthorized', handleUnauthorized);
  }, [clearSession]);

  // Heartbeat: catch expiry/revocation even when the tab sits idle.
  useEffect(() => {
    if (!user) return undefined;

    const revalidate = () => { refresh(); };
    const onVisibility = () => {
      if (document.visibilityState === 'visible') revalidate();
    };

    const interval = setInterval(revalidate, HEARTBEAT_MS);
    document.addEventListener('visibilitychange', onVisibility);
    window.addEventListener('focus', revalidate);
    return () => {
      clearInterval(interval);
      document.removeEventListener('visibilitychange', onVisibility);
      window.removeEventListener('focus', revalidate);
    };
  }, [user, refresh]);

  // Sign the user out the moment their session expires, even with no activity.
  const expireSession = useCallback(async () => {
    try {
      await api.post('/auth/logout'); // best effort: revoke whatever is left
    } catch {
      // Already dead server-side — nothing left to clean up.
    }
    setExpired(true);
    clearSession();
    try {
      navigate('/', { replace: true });
    } catch {
      // Fallback
    }
  }, [clearSession, navigate]);

  useEffect(() => {
    if (!user || !expiresAtMs) return undefined;

    let timer = null;
    const arm = () => {
      const remaining = expiresAtMs - Date.now();
      if (remaining <= 0) {
        expireSession();
        return;
      }
      timer = setTimeout(arm, Math.min(remaining, MAX_TIMEOUT_MS));
    };
    arm();

    return () => clearTimeout(timer);
  }, [user, expiresAtMs, expireSession]);

  const login = useCallback(async (email, password, remember) => {
    const response = await api.post('/auth/login', { email, password, remember });
    if (!response?.data?.user) {
      throw new Error(response?.error?.message || 'Login failed: unexpected response from server');
    }
    setExpired(false);
    setCsrfToken(response.data.csrf_token || null);
    setUser(response.data.user);
    await refresh();
    return response.data.user;
  }, [refresh]);

  const logout = useCallback(async () => {
    try {
      await api.post('/auth/logout');
    } catch (e) {
      console.warn('Logout server notification warning:', e);
    } finally {
      setExpired(false); // a deliberate sign-out, not an expiry
      clearSession();
      try {
        navigate('/', { replace: true });
      } catch {
        // Fallback
      }
    }
  }, [clearSession, navigate]);

  const can = useCallback(
    (permission) => {
      if (!permission) return true;
      if (permissions['*']) return true;
      return Boolean(permissions[permission]);
    },
    [permissions],
  );

  const value = useMemo(
    () => ({ user, permissions, unread, setUnread, loading, expired, login, logout, refresh, can }),
    [user, permissions, unread, loading, expired, login, logout, refresh, can],
  );

  return <SessionContext.Provider value={value}>{children}</SessionContext.Provider>;
}

export function useSession() {
  const context = useContext(SessionContext);
  if (!context) throw new Error('useSession must be used inside <SessionProvider>');
  return context;
}
