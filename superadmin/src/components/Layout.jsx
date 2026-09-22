import { useEffect, useRef, useState } from 'react';
import { NavLink, Outlet, useLocation, useNavigate } from 'react-router-dom';
import { api } from '../lib/api';
import { relative } from '../lib/format';
import { useSession } from '../lib/session';
import { Avatar, Badge, Button, StatusBadge } from './ui';

const NAV = [
  { group: 'Platform', items: [
    { to: '/', label: 'Overview', icon: '◎', end: true },
    { to: '/tenants', label: 'Restaurants', icon: '▤', permission: 'tenants.view' },
    { to: '/plans', label: 'Plans', icon: '◈', permission: 'plans.view' },
    { to: '/usage', label: 'Usage', icon: '◐', permission: 'usage.view' },
  ] },
  { group: 'Money', items: [
    { to: '/invoices', label: 'Invoices', icon: '▦', permission: 'billing.view' },
    { to: '/subscriptions', label: 'Subscriptions', icon: '⟳', permission: 'billing.view' },
  ] },
  { group: 'Operations', items: [
    { to: '/audit', label: 'Audit log', icon: '⌷', permission: 'audit.view' },
    { to: '/team', label: 'Team', icon: '☰', permission: 'users.manage' },
    { to: '/settings', label: 'Settings', icon: '⚙', permission: 'settings.manage' },
    { to: '/system', label: 'System', icon: '⛭', permission: 'system.view' },
  ] },
];

const ROLE_LABELS = {
  owner:   'Owner',
  admin:   'Administrator',
  support: 'Support',
  billing: 'Billing',
  viewer:  'Read only',
};

export default function Layout() {
  const { user, logout, can, unread, setUnread } = useSession();
  const navigate    = useNavigate();
  const location    = useLocation();
  const profileRef  = useRef(null);

  const [notifications, setNotifications] = useState([]);
  const [panelOpen,     setPanelOpen]     = useState(false);
  const [sidebarOpen,   setSidebarOpen]   = useState(false);   // mobile overlay
  const [collapsed,     setCollapsed]     = useState(false);   // desktop icon-only
  const [profileOpen,   setProfileOpen]   = useState(false);   // profile dropdown
  const [query,         setQuery]         = useState('');

  const [theme, setTheme] = useState(() => {
    const saved = globalThis.localStorage?.getItem('restaurantos-superadmin-theme');
    return saved === 'light' ? 'light' : 'dark';
  });

  /* ---- theme ---- */
  useEffect(() => {
    document.documentElement.setAttribute('data-theme', theme);
    globalThis.localStorage?.setItem('restaurantos-superadmin-theme', theme);
  }, [theme]);

  /* ---- close panels on navigation ---- */
  useEffect(() => {
    setSidebarOpen(false);
    setPanelOpen(false);
    setProfileOpen(false);
  }, [location.pathname]);

  /* ---- notifications ---- */
  useEffect(() => {
    api
      .get('/notifications', { per_page: 12 })
      .then((res) => setNotifications(res.data || []))
      .catch(() => {});
  }, [location.pathname]);

  /* ---- close profile dropdown on outside click ---- */
  useEffect(() => {
    if (!profileOpen) return undefined;
    const handler = (e) => {
      if (profileRef.current && !profileRef.current.contains(e.target)) {
        setProfileOpen(false);
      }
    };
    document.addEventListener('mousedown', handler);
    return () => document.removeEventListener('mousedown', handler);
  }, [profileOpen]);

  /* ---- handlers ---- */
  const markRead = async () => {
    await api.post('/notifications/read', {});
    setUnread(0);
    setNotifications((curr) => curr.map((n) => ({ ...n, read_at: n.read_at || 'now' })));
  };

  const submitSearch = (e) => {
    e.preventDefault();
    if (!query.trim()) return;
    navigate(`/tenants?search=${encodeURIComponent(query.trim())}`);
  };

  /**
   * Hamburger logic:
   *  - Mobile (<860 px, matches the existing breakpoint): toggle overlay sidebar
   *  - Desktop: collapse sidebar to icon-only rail
   */
  const handleHamburger = () => {
    if (window.innerWidth < 860) {
      setSidebarOpen((o) => !o);
    } else {
      setCollapsed((c) => !c);
    }
  };

  /* ---- render ---- */
  return (
    <div className="shell">

      {/* ===================== SIDEBAR ===================== */}
      <aside className={`sidebar ${sidebarOpen ? 'open' : ''} ${collapsed ? 'collapsed' : ''}`}>
        <div className="brand">
          <span className="brand-mark">R</span>
          {!collapsed && (
            <div>
              <strong>RestaurantOS</strong>
              <span className="muted small">Platform console</span>
            </div>
          )}
        </div>

        <nav>
          {NAV.map((section) => {
            const items = section.items.filter((item) => can(item.permission));
            if (!items.length) return null;
            return (
              <div key={section.group} className="nav-group">
                {!collapsed && <span className="nav-group-label">{section.group}</span>}
                {items.map((item) => (
                  <NavLink
                    key={item.to}
                    to={item.to}
                    end={item.end}
                    title={collapsed ? item.label : undefined}
                    className={({ isActive }) => `nav-item ${isActive ? 'active' : ''}`}
                  >
                    <span className="nav-icon">{item.icon}</span>
                    {!collapsed && item.label}
                  </NavLink>
                ))}
              </div>
            );
          })}
        </nav>

        <div className="sidebar-foot">
          {collapsed ? (
            <div className="sidebar-collapsed-foot">
              <div title={`${user?.name} (${ROLE_LABELS[user?.role] || user?.role || 'Staff'})`}>
                <Avatar name={user?.name} size={32} />
              </div>
              <button
                className="sidebar-logout-icon"
                onClick={logout}
                title="Sign out"
                aria-label="Sign out"
              >
                ⏻
              </button>
            </div>
          ) : (
            <div className="sidebar-user-card">
              <Avatar name={user?.name} size={34} />
              <div className="sidebar-user-details">
                <strong className="sidebar-user-name" title={user?.name}>{user?.name}</strong>
                <span className="sidebar-user-badge">
                  {ROLE_LABELS[user?.role] || user?.role || 'Staff'}
                </span>
              </div>
              <button
                type="button"
                className="sidebar-logout-btn"
                onClick={logout}
                title="Sign out"
                aria-label="Sign out"
              >
                <span className="sidebar-logout-icon-glyph">⏻</span>
              </button>
            </div>
          )}
        </div>
      </aside>

      {sidebarOpen && <div className="sidebar-scrim" onClick={() => setSidebarOpen(false)} />}

      {/* ===================== MAIN ===================== */}
      <div className="main">

        {/* ---- TOPBAR ---- */}
        <header className="topbar">

          {/* Hamburger — always visible */}
          <button
            className="icon-btn"
            onClick={handleHamburger}
            aria-label={collapsed ? 'Expand sidebar' : 'Collapse sidebar'}
            title={collapsed ? 'Expand sidebar' : 'Collapse sidebar'}
          >
            ☰
          </button>

          {/* Search */}
          <form className="search" onSubmit={submitSearch}>
            <span>⌕</span>
            <input
              value={query}
              onChange={(e) => setQuery(e.target.value)}
              placeholder="Search restaurants…"
              aria-label="Search restaurants"
            />
          </form>

          <div className="grow" />

          {/* Theme toggle */}
          <button
            className="icon-btn"
            onClick={() => setTheme((t) => (t === 'dark' ? 'light' : 'dark'))}
            aria-label="Toggle theme"
            title={theme === 'dark' ? 'Switch to light mode' : 'Switch to dark mode'}
          >
            {theme === 'dark' ? '☀' : '☾'}
          </button>

          {/* Notification bell */}
          <button
            className={`icon-btn ${panelOpen ? 'active' : ''}`}
            onClick={() => setPanelOpen((o) => !o)}
            aria-label="Notifications"
            title="Notifications"
          >
            ✦
            {unread > 0 && <span className="badge-count">{unread}</span>}
          </button>

          {/* ---- User profile ---- */}
          <div className="profile-wrap" ref={profileRef}>
            <button
              className={`profile-btn ${profileOpen ? 'active' : ''}`}
              onClick={() => setProfileOpen((o) => !o)}
              aria-label="Your profile"
              aria-expanded={profileOpen}
            >
              <Avatar name={user?.name} size={30} />
              <span className="profile-btn-name">{user?.name}</span>
              <span className="profile-chevron">{profileOpen ? '▲' : '▾'}</span>
            </button>

            {profileOpen && (
              <div className="profile-menu" role="menu">
                {/* Avatar + identity */}
                <div className="profile-menu-head">
                  <Avatar name={user?.name} size={44} />
                  <div className="grow" style={{ minWidth: 0 }}>
                    <strong style={{ display: 'block', fontSize: '0.94rem' }}>{user?.name}</strong>
                    <span className="muted small block" style={{ wordBreak: 'break-all' }}>{user?.email}</span>
                    {user?.role && (
                      <span className="badge badge-indigo" style={{ marginTop: 6, display: 'inline-block' }}>
                        {ROLE_LABELS[user.role] ?? user.role}
                      </span>
                    )}
                  </div>
                </div>

                {/* Secondary info */}
                {(user?.job_title || user?.last_login_at) && (
                  <>
                    <div className="profile-menu-divider" />
                    <div className="profile-menu-body">
                      {user?.job_title && (
                        <p className="muted small" style={{ marginBottom: 4 }}>{user.job_title}</p>
                      )}
                      {user?.last_login_at && (
                        <p className="muted tiny">Last signed in {relative(user.last_login_at)}</p>
                      )}
                    </div>
                  </>
                )}

                <div className="profile-menu-divider" />

                {/* Actions */}
                <button
                  className="profile-menu-action danger"
                  role="menuitem"
                  onClick={() => { setProfileOpen(false); logout(); }}
                >
                  Sign out
                </button>
              </div>
            )}
          </div>
        </header>

        {/* ---- Notification panel ---- */}
        {panelOpen && (
          <div className="notification-panel">
            <header>
              <strong>Notifications</strong>
              {unread > 0 && (
                <Button size="sm" variant="ghost" onClick={markRead}>
                  Mark all read
                </Button>
              )}
            </header>
            <div className="notification-list">
              {notifications.length === 0 && <p className="muted small pad">Nothing yet.</p>}
              {notifications.map((item) => (
                <div key={item.id} className={`notification ${item.read_at ? '' : 'unread'}`}>
                  <Badge tone={item.level === 'warning' ? 'amber' : item.level === 'success' ? 'green' : 'indigo'}>
                    {item.type}
                  </Badge>
                  <strong>{item.title}</strong>
                  {item.body && <p className="muted small">{item.body}</p>}
                  <span className="muted tiny">{relative(item.created_at)}</span>
                </div>
              ))}
            </div>
          </div>
        )}

        <main className="content">
          <Outlet />
        </main>
      </div>
    </div>
  );
}

export { StatusBadge };
