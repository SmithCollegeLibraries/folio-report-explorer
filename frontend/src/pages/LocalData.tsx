import { Link } from 'react-router-dom';
import ExpenseAllocationsPanel from '../components/ExpenseAllocationsPanel';
import { useAuth } from '../hooks/useAuth';

export default function LocalDataPage() {
  const { isAdmin, authEnabled, allocationColleges, canEditAllocationsFor } = useAuth();
  const allocationEditorOnly = authEnabled && !isAdmin && canEditAllocationsFor();

  return (
    <div className="max-w-screen-2xl mx-auto p-4 sm:p-6 space-y-4">
      <div className="flex items-center justify-between gap-3 flex-wrap">
        <h2 className="text-xl font-semibold">Local Data</h2>
        {isAdmin && <Link className="rounded bg-folio-600 px-3 py-2 text-sm font-medium text-white hover:bg-folio-700" to="/acrl">ACRL question catalogue</Link>}
      </div>
      <p className="text-sm text-gray-500">Manage expense allocations.</p>
      <ExpenseAllocationsPanel allowedColleges={allocationEditorOnly ? allocationColleges : undefined} />
    </div>
  );
}
