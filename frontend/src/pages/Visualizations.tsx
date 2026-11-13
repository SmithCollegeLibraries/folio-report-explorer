import { AcquisitionsVisualizations } from '../components/visualizations/AcquisitionsVisualizations';
import { GateCountCharts } from '../components/visualizations/GateCountCharts';
import { VisualizationTabs } from '../components/visualizations/VisualizationTabs';
import { useSearchParams } from 'react-router-dom';

export default function Visualizations() {
  const [searchParams] = useSearchParams();
  const acquisitionsActive = searchParams.get('tab') !== 'gate-counts';
  return <div className="max-w-screen-2xl mx-auto px-4 sm:px-6 py-6 sm:py-8">
    <header className="mb-6 border-b border-gray-200 pb-6">
      <p className="text-xs uppercase tracking-widest text-folio-700 font-semibold mb-2">Library data</p>
      <h1 className="text-2xl sm:text-3xl font-semibold text-gray-900">Visualizations</h1>
      <p className="text-gray-600 mt-2">Explore purchases, budgets and gate counts across fiscal years.</p>
    </header>
    <VisualizationTabs acquisitions={<AcquisitionsVisualizations active={acquisitionsActive} />} gateCounts={<GateCountCharts />} />
  </div>;
}
