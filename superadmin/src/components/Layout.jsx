import { useEffect, useState } from 'react';
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

export default function Layout() {
  const { user, logout, can, unread, setUnread } = useSession();
  const navigate = useNavigate();
  const location = useLocation();
  const [notifications, setNotifications] = useState([]);
  const [panelOpen, setPanelOpen] = useState(false);
  const [sidebarOpen, setSidebarOpen] = useState(false);
  const [query, setQuery] = useState('');

  useEffect(() => {
    setSidebarOpen(false);
    setPanelOpen(false);
  }, [location.pathname]);

  useEffect(() => {
    api
      .get('/notifications', { per_page: 12 })
      .then((response) => setNotifications(response.data || []))
      .catch(() => {});
  }, [location.pathname]);

  const markRead = async () => {
    await api.post('/notifications/read', {});
    setUnread(0);
    setNotifications((current) => current.map((item) => ({ ...item, read_at: item.read_at || 'now' })));
  };

  const submitSearch = (event) => {
    event.preventDefault();
    if (!query.trim()) return;
    navigate(`/tenants?search=${encodeURIComponent(query.trim())}`);
  };

  return (
    <div className="shell">
      <aside className={`sidebar ${sidebarOpen ? 'open' : ''}`}>
        <div className="brand">
          <span className="brand-mark">R</span>
          <div>
            <strong>RestaurantOS</strong>
            <span className="muted small">Platform console</span>
          </div>
        </div>

        <nav>
          {NAV.map((section) => {
            const items = section.items.filter((item) => can(item.permission));
            if (!items.length) return null;
            return (
              <div key={section.group} className="nav-group">
                <span className="nav-group-label">{section.group}</span>
                {items.map((item) => (
                  <NavLink key={item.to} to={item.to} end={item.end} className={({ isActive }) => `nav-item ${isActive ? 'active' : ''}`}>
                    <span className="nav-icon">{item.icon}</span>
                    {item.label}
                  </NavLink>
                ))}
              </div>
            );
          })}
        </nav>

        <div className="sidebar-foot">
          <div className="user-chip">
            <Avatar name={user?.name} size={36} />
            <div className="grow">
              <strong>{user?.name}</strong>
              <span className="muted small">{user?.email}</span>
            </div>
          </div>
          <Button size="sm" variant="ghost" onClick={logout}>
            Sign out
          </Button>
        </div>
      </aside>

      {sidebarOpen && <div className="sidebar-scrim" onClick={() => setSidebarOpen(false)} />}

      <div className="main">
        <header className="topbar">
          <button className="icon-btn only-mobile" onClick={() => setSidebarOpen(true)} aria-label="Menu">
            ☰
          </button>
          <form className="search" onSubmit={submitSearch}>
            <span>⌕</span>
            <input
              value={query}
              onChange={(event) => setQuery(event.target.value)}
              placeholder="Search restaurants…"
              aria-label="Search restaurants"
            />
          </form>
          <div className="grow" />
          <button className={`icon-btn ${panelOpen ? 'active' : ''}`} onClick={() => setPanelOpen((open) => !open)} aria-label="Notifications">
            ✦
            {unread > 0 && <span className="badge-count">{unread}</span>}
          </button>
        </header>

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
