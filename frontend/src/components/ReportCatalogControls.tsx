import { Search, X } from 'lucide-react';
import type { ReportCategory } from '../types';
import type { ReportCatalogCategory } from '../hooks/useReportCatalogFilter';

interface ReportCatalogControlsProps {
  search: string;
  category: ReportCatalogCategory;
  categories: { key: ReportCategory; label: string; count: number }[];
  filteredCount: number;
  totalCount: number;
  onSearchChange: (value: string) => void;
  onCategoryChange: (category: ReportCatalogCategory) => void;
}

export default function ReportCatalogControls({
  search,
  category,
  categories,
  filteredCount,
  totalCount,
  onSearchChange,
  onCategoryChange,
}: ReportCatalogControlsProps) {
  return (
    <div className="space-y-5 rounded-2xl border border-stone-200 bg-stone-50/70 p-4 sm:p-5">
      <div className="flex flex-wrap items-center gap-4">
        <label className="relative min-w-[240px] flex-1">
          <span className="sr-only">Search reports</span>
          <Search size={17} className="pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 text-stone-400" />
          <input
            type="search"
            value={search}
            onChange={(event) => onSearchChange(event.target.value)}
            placeholder="Search by report name or description"
            aria-label="Search reports"
            className="h-11 w-full rounded-xl border border-stone-300 bg-white pl-10 pr-11 text-sm text-stone-900 outline-none transition focus:border-folio-500 focus:ring-2 focus:ring-folio-100"
          />
          {search && (
            <button
              type="button"
              onClick={() => onSearchChange('')}
              aria-label="Clear search"
              className="absolute right-2 top-1/2 -translate-y-1/2 rounded-lg p-2 text-stone-400 transition hover:bg-stone-100 hover:text-stone-700"
            >
              <X size={15} />
            </button>
          )}
        </label>
        <p role="status" aria-live="polite" className="text-sm font-medium text-stone-600">
          <span className="font-semibold tabular-nums text-stone-900">{filteredCount}</span> of{' '}
          <span className="tabular-nums">{totalCount}</span> reports
        </p>
      </div>

      <div className="flex flex-wrap gap-2" role="group" aria-label="Filter reports by category">
        <button
          type="button"
          onClick={() => onCategoryChange('all')}
          aria-pressed={category === 'all'}
          className={`rounded-full border px-3.5 py-2 text-sm font-medium transition-colors ${category === 'all'
            ? 'border-folio-700 bg-folio-700 text-white shadow-sm'
            : 'border-stone-300 bg-white text-stone-600 hover:border-stone-400 hover:text-stone-900'}`}
        >
          All <span className={category === 'all' ? 'text-folio-100' : 'text-stone-400'}>({totalCount})</span>
        </button>
        {categories.map((item) => {
          const isActive = category === item.key;
          return (
            <button
              key={item.key}
              type="button"
              onClick={() => onCategoryChange(item.key)}
              aria-pressed={isActive}
              className={`rounded-full border px-3.5 py-2 text-sm font-medium transition-colors ${isActive
                ? 'border-folio-700 bg-folio-700 text-white shadow-sm'
                : 'border-stone-300 bg-white text-stone-600 hover:border-stone-400 hover:text-stone-900'}`}
            >
              {item.label}{' '}
              <span className={isActive ? 'text-folio-100' : 'text-stone-400'}>({item.count})</span>
            </button>
          );
        })}
      </div>
    </div>
  );
}
