import { Routes, Route, NavLink } from 'react-router-dom';
import { Database, Wrench, MessageSquare, FileBarChart, Bookmark, Settings, LayoutDashboard, Brain } from 'lucide-react';
import Dashboard from './pages/Dashboard';
import Explorer from './pages/Explorer';
import Builder from './pages/Builder';
import Ask from './pages/Ask';
import SavedQueries from './pages/SavedQueries';
import Reports from './pages/Reports';
import SettingsPage from './pages/Settings';
import Training from './pages/Training';

const navItems = [
  { to: '/', label: 'Dashboard', icon: LayoutDashboard },
  { to: '/explorer', label: 'Explorer', icon: Database },
  { to: '/builder', label: 'Query Builder', icon: Wrench },
  { to: '/ask', label: 'Ask AI', icon: MessageSquare },
  { to: '/training', label: 'AI Training', icon: Brain },
  { to: '/reports', label: 'Reports', icon: FileBarChart },
  { to: '/saved', label: 'Saved', icon: Bookmark },
  { to: '/setup', label: 'Setup', icon: Settings },
];

export default function App() {
  return (
    <div className="min-h-screen flex flex-col">
      {/* Top navigation bar */}
      <header className="bg-folio-800 text-white shadow-lg">
        <div className="max-w-screen-2xl mx-auto px-4 flex items-center h-14">
          <h1 className="text-lg font-bold mr-8 tracking-tight">
            FOLIO Report Explorer
          </h1>
          <nav className="flex gap-1">
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
        </div>
      </header>

      {/* Main content */}
      <main className="flex-1">
        <Routes>
          <Route path="/" element={<Dashboard />} />
          <Route path="/explorer" element={<Explorer />} />
          <Route path="/builder" element={<Builder />} />
          <Route path="/ask" element={<Ask />} />
          <Route path="/training" element={<Training />} />
          <Route path="/reports" element={<Reports />} />
          <Route path="/saved" element={<SavedQueries />} />
          <Route path="/setup" element={<SettingsPage />} />
        </Routes>
      </main>
    </div>
  );
}
