import { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react';
import { api, setCsrfToken } from './api';

const SessionContext = createContext(null);

export function SessionProvider({ children }) {
  const [user, setUser] = useState(null);
  const [permissions, setPermissions] = useState({});
  const [unread, setUnread] = useState(0);
  const [loading, setLoading] = useState(true);

  const refresh = useCallback(async () => {
    try {
      const response = await api.get('/auth/me');
      setCsrfToken(response.data.csrf_token);
      setUser(response.data.user);
      setPermissions(response.data.can || {});
      setUnread(response.data.unread || 0);
      return response.data.user;
    } catch {
      setUser(null);
      setPermissions({});
      return null;
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    refresh();
  }, [refresh]);

  const login = useCallback(async (email, password, remember) => {
    const response = await api.post('/auth/login', { email, password, remember });
    setCsrfToken(response.data.csrf_token);
    setUser(response.data.user);
    await refresh();
    return response.data.user;
  }, [refresh]);

  const logout = useCallback(async () => {
    try {
      await api.post('/auth/logout');
    } finally {
      setCsrfToken(null);
      setUser(null);
      setPermissions({});
    }
  }, []);

  const can = useCallback(
    (permission) => {
      if (!permission) return true;
      if (permissions['*']) return true;
      return Boolean(permissions[permission]);
    },
    [permissions],
  );

  const value = useMemo(
    () => ({ user, permissions, unread, setUnread, loading, login, logout, refresh, can }),
    [user, permissions, unread, loading, login, logout, refresh, can],
  );

  return <SessionContext.Provider value={value}>{children}</SessionContext.Provider>;
}

export function useSession() {
  const context = useContext(SessionContext);
  if (!context) throw new Error('useSession must be used inside <SessionProvider>');
  return context;
}
