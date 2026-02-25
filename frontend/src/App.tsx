import { Routes, Route, NavLink } from 'react-router-dom';
import { Database, Wrench, MessageSquare, FileBarChart, Bookmark, Settings, LayoutDashboard, Brain, History as HistoryIcon, Users as UsersIcon, LogOut } from 'lucide-react';
import Dashboard from './pages/Dashboard';
import Explorer from './pages/Explorer';
import Builder from './pages/Builder';
import Ask from './pages/Ask';
import SavedQueries from './pages/SavedQueries';
import Reports from './pages/Reports';
import SettingsPage from './pages/Settings';
import Training from './pages/Training';
import History from './pages/History';
import UsersPage from './pages/Users';
import AuthCallback from './pages/AuthCallback';
import AuthPending from './pages/AuthPending';
import ProtectedRoute from './components/ProtectedRoute';
import { useAuth, getShibbolethLoginUrl } from './hooks/useAuth';

/** Navigation items visible to all authenticated users */
const userNavItems = [
  { to: '/', label: 'Dashboard', icon: LayoutDashboard },
  { to: '/explorer', label: 'Explorer', icon: Database },
  { to: '/builder', label: 'Query Builder', icon: Wrench },
  { to: '/ask', label: 'Ask AI', icon: MessageSquare },
  { to: '/reports', label: 'Reports', icon: FileBarChart },
  { to: '/saved', label: 'Saved', icon: Bookmark },
  { to: '/history', label: 'History', icon: HistoryIcon },
];

/** Navigation items visible only to admins */
const adminNavItems = [
  { to: '/training', label: 'AI Training', icon: Brain },
  { to: '/users', label: 'Users', icon: UsersIcon },
  { to: '/setup', label: 'Setup', icon: Settings },
];

export default function App() {
  const { user, isAdmin, authEnabled, logout } = useAuth();

  // Build nav items based on role
  const navItems = authEnabled && !isAdmin
    ? userNavItems
    : [...userNavItems, ...adminNavItems];

  const handleLogout = () => {
    logout();
    if (authEnabled) {
      window.location.href = getShibbolethLoginUrl();
    }
  };

  return (
    <div className="min-h-screen flex flex-col">
      {/* Top navigation bar */}
      <header className="bg-folio-800 text-white shadow-lg">
        <div className="max-w-screen-2xl mx-auto px-4 flex items-center h-14">
          <h1 className="text-lg font-bold mr-8 tracking-tight">
            FOLIO Report Explorer
          </h1>
          <nav className="flex gap-1 flex-1">
            {navItems.map(({ to, label, icon: Icon }) => (
              <NavLink
                key={to}
                to={to}
                end={to === '/'}
                className={({ isActive }) =>
                  `flex items-center gap-2 px-4 py-2 rounded-md text-sm font-medium transition-colors ${
                    isActive
                      ? 'bg-folio-600 text-white'
                      : 'text-folio-200 hover:bg-folio-700 hover:text-white'
                  }`
                }
              >
                <Icon size={16} />
                {label}
              </NavLink>
            ))}
          </nav>

          {/* User info / auth controls */}
          {authEnabled && user && (
            <div className="flex items-center gap-3 ml-4">
              <span className="text-folio-200 text-sm">
                {user.displayName}
                {isAdmin && (
                  <span className="ml-1 text-xs bg-folio-600 px-1.5 py-0.5 rounded">admin</span>
                )}
              </span>
              <button
                onClick={handleLogout}
                className="text-folio-300 hover:text-white p-1"
                title="Logout"
              >
                <LogOut size={16} />
              </button>
            </div>
          )}
        </div>
      </header>

      {/* Main content */}
      <main className="flex-1">
        <Routes>
          {/* Public routes (auth callback, pending) */}
          <Route path="/auth/callback" element={<AuthCallback />} />
          <Route path="/auth/pending" element={<AuthPending />} />

          {/* User routes */}
          <Route path="/" element={<ProtectedRoute><Dashboard /></ProtectedRoute>} />
          <Route path="/explorer" element={<ProtectedRoute><Explorer /></ProtectedRoute>} />
          <Route path="/builder" element={<ProtectedRoute><Builder /></ProtectedRoute>} />
          <Route path="/ask" element={<ProtectedRoute><Ask /></ProtectedRoute>} />
          <Route path="/reports" element={<ProtectedRoute><Reports /></ProtectedRoute>} />
          <Route path="/saved" element={<ProtectedRoute><SavedQueries /></ProtectedRoute>} />
          <Route path="/history" element={<ProtectedRoute><History /></ProtectedRoute>} />

          {/* Admin routes */}
          <Route path="/training" element={<ProtectedRoute adminOnly><Training /></ProtectedRoute>} />
          <Route path="/users" element={<ProtectedRoute adminOnly><UsersPage /></ProtectedRoute>} />
          <Route path="/setup" element={<ProtectedRoute adminOnly><SettingsPage /></ProtectedRoute>} />
        </Routes>
      </main>
    </div>
  );
}
