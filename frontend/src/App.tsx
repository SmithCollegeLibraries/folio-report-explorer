import { useState, useRef, useEffect } from 'react';
import { Routes, Route, NavLink, useLocation } from 'react-router-dom';
import {
  Database, Wrench, MessageSquare, FileBarChart, Bookmark, Settings,
  LayoutDashboard, Brain, History as HistoryIcon, Users as UsersIcon,
  LogOut, ChevronDown, BookOpen, ShieldCheck, Menu, X, Terminal, ClipboardCheck,
} from 'lucide-react';
import Dashboard from './pages/Dashboard';
import Explorer from './pages/Explorer';
import Builder from './pages/Builder';
import Ask from './pages/Ask';
import SavedQueries from './pages/SavedQueries';
import Reports from './pages/Reports';
import ReportDetail from './pages/ReportDetail';
import SettingsPage from './pages/Settings';
import Training from './pages/Training';
import History from './pages/History';
import Console from './pages/Console';
import UsersPage from './pages/Users';
import LocalDataPage from './pages/LocalData.tsx';
import ReportReviews from './pages/ReportReviews';
import AuthCallback from './pages/AuthCallback';
import AuthPending from './pages/AuthPending';
import ProtectedRoute from './components/ProtectedRoute';
import { ToastProvider } from './components/ToastProvider';
import { useAuth, getShibbolethLoginUrl } from './hooks/useAuth';

// ─── Nav groups ────────────────────────────────────────────────────────────────

const queryItems = [
  { to: '/ask',     label: 'Ask AI',        icon: MessageSquare, desc: 'Natural-language query' },
  { to: '/builder', label: 'Query Builder', icon: Wrench,        desc: 'Drag-and-drop SQL builder' },
  { to: '/console', label: 'SQL Console',   icon: Terminal,      desc: 'Run raw SQL queries' },
  { to: '/explorer',label: 'Schema Explorer',icon: Database,     desc: 'Browse FOLIO tables' },
];

const libraryItems = [
  { to: '/reports', label: 'Reports',       icon: FileBarChart,  desc: 'Scheduled & saved reports' },
  { to: '/saved',   label: 'Saved Queries', icon: Bookmark,      desc: 'Your bookmarked queries' },
  { to: '/history', label: 'History',       icon: HistoryIcon,   desc: 'Past query runs' },
];

const adminItems = [
  { to: '/report-reviews', label: 'AI Report Review', icon: ClipboardCheck, desc: 'Review uncertain Ask AI reports' },
  { to: '/training',label: 'AI Training',   icon: Brain,         desc: 'Tune AI query generation' },
  { to: '/local-data',label: 'Local Data',  icon: Database,      desc: 'Manage ACRL and allocations' },
  { to: '/users',   label: 'Users',         icon: UsersIcon,     desc: 'Manage user access' },
  { to: '/setup',   label: 'Setup',         icon: Settings,      desc: 'System settings' },
];

// ─── Dropdown component ────────────────────────────────────────────────────────

interface DropdownItem { to: string; label: string; icon: React.ElementType; desc: string; }

function NavDropdown({
  label, icon: GroupIcon, items, color = 'folio',
}: {
  label: string;
  icon: React.ElementType;
  items: DropdownItem[];
  color?: 'folio' | 'amber';
}) {
  const [open, setOpen] = useState(false);
  const ref = useRef<HTMLDivElement>(null);
  const location = useLocation();
  const isAnyActive = items.some((item) => location.pathname === item.to);

  useEffect(() => {
    if (!open) return;
    const handler = (e: MouseEvent) => {
      if (ref.current && !ref.current.contains(e.target as Node)) setOpen(false);
    };
    document.addEventListener('mousedown', handler);
    return () => document.removeEventListener('mousedown', handler);
  }, [open]);

  const activeCls = color === 'amber'
    ? 'bg-amber-600 text-white'
    : 'bg-folio-600 text-white';
  const hoverCls = color === 'amber'
    ? 'text-amber-200 hover:bg-folio-700 hover:text-white'
    : 'text-folio-200 hover:bg-folio-700 hover:text-white';
  const dotCls = color === 'amber' ? 'bg-amber-400' : 'bg-folio-400';
  const itemHoverCls = color === 'amber'
    ? 'hover:bg-amber-50 hover:text-amber-700'
    : 'hover:bg-folio-50 hover:text-folio-700';
  const iconCls = color === 'amber' ? 'text-amber-500' : 'text-folio-500';

  return (
    <div className="relative" ref={ref}>
      <button
        onClick={() => setOpen((o) => !o)}
        className={`flex items-center gap-1.5 px-3 py-2 rounded-md text-sm font-medium transition-colors ${
          isAnyActive ? activeCls : hoverCls
        } ${open && !isAnyActive ? 'bg-folio-700 text-white' : ''}`}
      >
        {isAnyActive && <span className={`w-1.5 h-1.5 rounded-full ${dotCls} opacity-0`} />}
        <GroupIcon size={15} />
        {label}
        <ChevronDown size={13} className={`transition-transform ${open ? 'rotate-180' : ''}`} />
      </button>

      {open && (
        <div className="absolute left-0 top-full mt-1 w-56 bg-white rounded-lg shadow-xl border border-gray-100 z-50 py-1 overflow-hidden">
          {items.map(({ to, label: itemLabel, icon: Icon, desc }) => (
            <NavLink
              key={to}
              to={to}
              onClick={() => setOpen(false)}
              className={({ isActive }) =>
                `flex items-start gap-3 px-4 py-3 transition-colors ${
                  isActive
                    ? `bg-folio-50 text-folio-700 border-l-2 border-folio-500`
                    : `text-gray-700 border-l-2 border-transparent ${itemHoverCls}`
                }`
              }
            >
              <Icon size={16} className={`mt-0.5 flex-shrink-0 ${iconCls}`} />
              <div>
                <div className="text-sm font-medium leading-tight">{itemLabel}</div>
                <div className="text-xs text-gray-400 mt-0.5">{desc}</div>
              </div>
            </NavLink>
          ))}
        </div>
      )}
    </div>
  );
}

// ─── App ───────────────────────────────────────────────────────────────────────

function MobileNavGroup({
  label, icon: GroupIcon, items, color = 'folio',
}: {
  label: string;
  icon: React.ElementType;
  items: DropdownItem[];
  color?: 'folio' | 'amber';
}) {
  const [open, setOpen] = useState(false);
  const location = useLocation();
  const isAnyActive = items.some((item) => location.pathname === item.to);
  const iconCls = color === 'amber' ? 'text-amber-400' : 'text-folio-400';
  const groupClsActive = color === 'amber' ? 'text-amber-200' : 'text-folio-200';

  return (
    <div>
      <button
        onClick={() => setOpen((o) => !o)}
        className={`w-full flex items-center gap-2 px-3 py-2.5 rounded-md text-sm font-medium transition-colors ${
          isAnyActive ? 'text-white' : 'text-folio-200 hover:bg-folio-700 hover:text-white'
        }`}
      >
        <GroupIcon size={16} className={isAnyActive ? 'text-white' : groupClsActive} />
        <span>{label}</span>
        <ChevronDown size={14} className={`ml-auto transition-transform ${open ? 'rotate-180' : ''}`} />
      </button>
      {open && (
        <div className="ml-6 mt-0.5 space-y-0.5">
          {items.map(({ to, label: itemLabel, icon: Icon }) => (
            <NavLink
              key={to}
              to={to}
              className={({ isActive }) =>
                `flex items-center gap-2 px-3 py-2 rounded-md text-sm transition-colors ${
                  isActive
                    ? 'bg-folio-600 text-white'
                    : 'text-folio-300 hover:bg-folio-700 hover:text-white'
                }`
              }
            >
              <Icon size={14} className={iconCls} />
              {itemLabel}
            </NavLink>
          ))}
        </div>
      )}
    </div>
  );
}

export default function App() {
  const { user, isAdmin, authEnabled, logout } = useAuth();
  const [mobileOpen, setMobileOpen] = useState(false);
  const location = useLocation();

  // Close mobile menu on navigation
  useEffect(() => {
    setMobileOpen(false);
  }, [location.pathname]);

  const showAdmin = isAdmin || !authEnabled;

  const handleLogout = () => {
    logout();
    if (authEnabled) {
      window.location.href = getShibbolethLoginUrl();
    }
  };

  return (
    <ToastProvider>
      <div className="min-h-screen flex flex-col">
      {/* Top navigation bar */}
      <header className="bg-folio-800 text-white shadow-lg relative z-40">
        <div className="max-w-screen-2xl mx-auto px-4 flex items-center h-14">
          {/* Brand */}
          <NavLink to="/" className="flex items-center gap-2 mr-4 flex-shrink-0">
            <h1 className="text-base font-bold tracking-tight whitespace-nowrap">
              <span className="hidden sm:inline">FOLIO Report Explorer</span>
              <span className="sm:hidden">FRE</span>
            </h1>
          </NavLink>

          {/* Desktop nav groups — hidden on mobile */}
          <nav className="hidden md:flex items-center gap-1 flex-1">
            {/* Dashboard — standalone */}
            <NavLink
              to="/"
              end
              className={({ isActive }) =>
                `flex items-center gap-1.5 px-3 py-2 rounded-md text-sm font-medium transition-colors ${
                  isActive ? 'bg-folio-600 text-white' : 'text-folio-200 hover:bg-folio-700 hover:text-white'
                }`
              }
            >
              <LayoutDashboard size={15} />
              Dashboard
            </NavLink>

            <NavDropdown label="Query" icon={MessageSquare} items={queryItems} />
            <NavDropdown label="Library" icon={BookOpen} items={libraryItems} />
            {showAdmin && (
              <NavDropdown label="Admin" icon={ShieldCheck} items={adminItems} color="amber" />
            )}
          </nav>

          {/* Spacer on mobile */}
          <div className="flex-1 md:hidden" />

          {/* User info / auth controls — desktop */}
          {authEnabled && user && (
            <div className="hidden md:flex items-center gap-3 ml-4 flex-shrink-0">
              <span className="text-folio-200 text-sm">
                {user.displayName}
                {isAdmin && (
                  <span className="ml-1.5 text-xs bg-amber-600 px-1.5 py-0.5 rounded font-medium">
                    admin
                  </span>
                )}
              </span>
              <button
                onClick={handleLogout}
                className="text-folio-300 hover:text-white p-1 rounded"
                title="Logout"
              >
                <LogOut size={16} />
              </button>
            </div>
          )}

          {/* Hamburger button — mobile only */}
          <button
            onClick={() => setMobileOpen((o) => !o)}
            className="md:hidden p-2 rounded text-folio-200 hover:text-white hover:bg-folio-700 ml-2"
            aria-label={mobileOpen ? 'Close menu' : 'Open menu'}
          >
            {mobileOpen ? <X size={20} /> : <Menu size={20} />}
          </button>
        </div>

        {/* Mobile nav drawer */}
        {mobileOpen && (
          <div className="md:hidden border-t border-folio-700 bg-folio-800 px-4 py-3 space-y-1">
            <NavLink
              to="/"
              end
              className={({ isActive }) =>
                `flex items-center gap-2 px-3 py-2.5 rounded-md text-sm font-medium transition-colors ${
                  isActive ? 'bg-folio-600 text-white' : 'text-folio-200 hover:bg-folio-700 hover:text-white'
                }`
              }
            >
              <LayoutDashboard size={16} />
              Dashboard
            </NavLink>

            <MobileNavGroup label="Query" icon={MessageSquare} items={queryItems} />
            <MobileNavGroup label="Library" icon={BookOpen} items={libraryItems} />
            {showAdmin && (
              <MobileNavGroup label="Admin" icon={ShieldCheck} items={adminItems} color="amber" />
            )}

            {authEnabled && user && (
              <div className="border-t border-folio-700 pt-2 mt-2 flex items-center justify-between">
                <span className="text-folio-300 text-sm">
                  {user.displayName}
                  {isAdmin && (
                    <span className="ml-1.5 text-xs bg-amber-600 px-1.5 py-0.5 rounded font-medium text-white">
                      admin
                    </span>
                  )}
                </span>
                <button
                  onClick={handleLogout}
                  className="text-folio-300 hover:text-white p-1 rounded flex items-center gap-1 text-sm"
                >
                  <LogOut size={15} /> Logout
                </button>
              </div>
            )}
          </div>
        )}
      </header>

      {/* Development environment banner */}
      {import.meta.env.VITE_APP_ENV === 'development' && (
        <div className="bg-amber-400 text-amber-900 text-xs font-semibold text-center py-1 px-3 tracking-wide z-30">
          ⚠ DEVELOPMENT — Docker local environment
        </div>
      )}

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
          <Route path="/reports/:id" element={<ProtectedRoute><ReportDetail /></ProtectedRoute>} />
          <Route path="/saved" element={<ProtectedRoute><SavedQueries /></ProtectedRoute>} />
          <Route path="/history" element={<ProtectedRoute><History /></ProtectedRoute>} />
          <Route path="/history/:jobId" element={<ProtectedRoute><History /></ProtectedRoute>} />
          <Route path="/console" element={<ProtectedRoute><Console /></ProtectedRoute>} />

          {/* Admin routes */}
          <Route path="/training" element={<ProtectedRoute adminOnly><Training /></ProtectedRoute>} />
          <Route path="/local-data" element={<ProtectedRoute adminOnly><LocalDataPage /></ProtectedRoute>} />
          <Route path="/users" element={<ProtectedRoute adminOnly><UsersPage /></ProtectedRoute>} />
          <Route path="/setup" element={<ProtectedRoute adminOnly><SettingsPage /></ProtectedRoute>} />
          <Route path="/report-reviews" element={<ProtectedRoute adminOnly><ReportReviews /></ProtectedRoute>} />
        </Routes>
      </main>
      </div>
    </ToastProvider>
  );
}
