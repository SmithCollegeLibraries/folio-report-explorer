import { useCallback, useRef, useState } from 'react';
import { useVisualizations } from '../hooks/useVisualizations';
import { useAuth } from '../hooks/useAuth';
import { errorMessage } from '../utils/errorMessage';
import { PurchaseCharts } from '../components/visualizations/PurchaseCharts';
import { BudgetCharts } from '../components/visualizations/BudgetCharts';
import { HistoryDialog } from '../components/visualizations/HistoryDialog';
import { GateCountCharts } from '../components/visualizations/GateCountCharts';

export default function Visualizations() {
  const state = useVisualizations();
  const { isAdmin } = useAuth();
  const [historyOpen, setHistoryOpen] = useState(false);
  const historyButtonRef = useRef<HTMLButtonElement>(null);
  const closeHistory = useCallback(() => setHistoryOpen(false), []);
  const data = state.data;
  const error = state.options.error ?? state.figures.error;
  const loading = state.options.isPending || (Boolean(state.unitId) && state.figures.isPending);
  const empty = !error && !state.figures.isFetching && data
    && data.purchasesByCategory.length === 0 && data.budget.length === 0;

  return <div className="max-w-screen-2xl mx-auto px-4 sm:px-6 py-6 sm:py-8">
    <header className="mb-6 border-b border-gray-200 pb-6">
      <p className="text-xs uppercase tracking-widest text-folio-700 font-semibold mb-2">Acquisitions overview</p>
      <h1 className="text-2xl sm:text-3xl font-semibold text-gray-900">Visualizations</h1>
      <p className="text-gray-600 mt-2">Explore library purchases and budgets across fiscal years.</p>
      {state.options.data && <div className="flex flex-wrap gap-4 mt-5">
        <div><label htmlFor="visualization-unit" className="block text-sm font-medium mb-1">Acquisitions unit</label>
          <select id="visualization-unit" value={state.unitId ?? ''} onChange={(event) => state.changeUnit(event.target.value)}
            className="border border-gray-300 rounded-md bg-white px-3 py-2 min-w-40">
            {state.options.data.units.map((unit) => <option key={unit.id} value={unit.id}>{unit.name}</option>)}
          </select></div>
        <div><label htmlFor="visualization-years" className="block text-sm font-medium mb-1">Years</label>
          <select id="visualization-years" value={state.years} onChange={(event) => state.setYears(Number(event.target.value))}
            className="border border-gray-300 rounded-md bg-white px-3 py-2">
            {state.options.data.years.map((year) => <option key={year} value={year}>{year}</option>)}
          </select></div>
        {isAdmin && data && <button ref={historyButtonRef} type="button" onClick={() => setHistoryOpen(true)}
          className="self-end rounded-md border border-gray-300 bg-white px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
          Manage historical figures
        </button>}
      </div>}
    </header>
    <div aria-live="polite" role="status" className="text-sm text-gray-600">
      {loading ? 'Loading visualizations…' : state.figures.isFetching ? 'Updating visualizations…' : ''}
    </div>
    {error && <div role="alert" className="p-4 border border-red-200 bg-red-50 rounded-md text-red-800 mb-6">
      <p>{errorMessage(error)}</p><button type="button" onClick={() => void state.retry()} className="underline font-medium mt-2">Retry</button>
    </div>}
    {state.options.data?.units.length === 0 && <p>No acquisitions units available.</p>}
    {empty && <p className="p-4 mb-6 bg-gray-50 border rounded-md">No purchase or budget data for this unit in the selected years</p>}
    {data && <>
      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6 min-w-0">
        <PurchaseCharts data={data} materialYear={state.materialYear}
          materialFiscalYearEnds={state.materialFiscalYearEnds} materialLoading={state.materialLoading}
          materialError={state.materialError} materialAvailable={state.materialAvailable}
          onMaterialYear={state.setMaterialYear} />
        <div className="min-w-0 lg:col-span-2"><BudgetCharts data={data} /></div>
      </div>
    </>}
    {isAdmin && data && historyOpen && <HistoryDialog key={data.unit.code} unitCode={data.unit.code}
      currentYear={data.currentFiscalYearEnd} triggerRef={historyButtonRef} onClose={closeHistory} />}
    <GateCountCharts />
  </div>;
}
