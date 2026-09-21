import { Route, Routes } from 'react-router-dom';
import Layout from './components/Layout';
import { Spinner } from './components/ui';
import { useSession } from './lib/session';
import Audit from './pages/Audit';
import Dashboard from './pages/Dashboard';
import Invoices from './pages/Invoices';
import Login from './pages/Login';
import NotFound from './pages/NotFound';
import Plans from './pages/Plans';
import Settings from './pages/Settings';
import Subscriptions from './pages/Subscriptions';
import System from './pages/System';
import Team from './pages/Team';
import Tenants from './pages/Tenants';
import TenantDetail from './pages/TenantDetail';
import Usage from './pages/Usage';

export default function App() {
  const { user, loading } = useSession();

  if (loading) return <Spinner label="Checking your session…" />;
  if (!user) return <Login />;

  return (
    <Routes>
      <Route element={<Layout />}>
        <Route index element={<Dashboard />} />
        <Route path="tenants" element={<Tenants />} />
        <Route path="tenants/:id" element={<TenantDetail />} />
        <Route path="plans" element={<Plans />} />
        <Route path="invoices" element={<Invoices />} />
        <Route path="subscriptions" element={<Subscriptions />} />
        <Route path="usage" element={<Usage />} />
        <Route path="audit" element={<Audit />} />
        <Route path="team" element={<Team />} />
        <Route path="settings" element={<Settings />} />
        <Route path="system" element={<System />} />
        <Route path="*" element={<NotFound />} />
      </Route>
    </Routes>
  );
}
