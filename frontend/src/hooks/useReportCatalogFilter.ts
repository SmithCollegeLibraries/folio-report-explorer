import { useEffect, useMemo, useState } from 'react';
import type { ReportCategory, ReportSummary } from '../types';
import { REPORT_CATEGORIES } from '../utils/reports';

const CATALOG_FILTERS_STORAGE_KEY = 'folio-report-explorer:report-catalog-filters';
export type ReportCatalogCategory = ReportCategory | 'all';

interface CatalogFilters {
  search: string;
  category: ReportCatalogCategory;
}

function isReportCategory(value: unknown): value is ReportCategory {
  return REPORT_CATEGORIES.some((category) => category.key === value);
}

function readCatalogFilters(): CatalogFilters {
  try {
    const saved = window.sessionStorage.getItem(CATALOG_FILTERS_STORAGE_KEY);
    if (!saved) return { search: '', category: 'all' };

    const parsed: unknown = JSON.parse(saved);
    if (typeof parsed !== 'object' || parsed === null) return { search: '', category: 'all' };
    const value = parsed as Record<string, unknown>;

    return {
      search: typeof value.search === 'string' ? value.search : '',
      category: value.category === 'all' || isReportCategory(value.category) ? value.category : 'all',
    };
  } catch {
    return { search: '', category: 'all' };
  }
}

export function useReportCatalogFilter(reports: ReportSummary[]) {
  const [filters, setFilters] = useState<CatalogFilters>(readCatalogFilters);

  useEffect(() => {
    try {
      window.sessionStorage.setItem(CATALOG_FILTERS_STORAGE_KEY, JSON.stringify(filters));
    } catch {
      // Filtering remains available when session storage is disabled.
    }
  }, [filters]);

  useEffect(() => {
    if (reports.length > 0 && filters.category !== 'all'
      && !reports.some((report) => report.category === filters.category)) {
      setFilters((current) => ({ ...current, category: 'all' }));
    }
  }, [filters.category, reports]);

  const categoryCounts = useMemo(() => {
    const counts = Object.fromEntries(
      REPORT_CATEGORIES.map((category) => [category.key, 0]),
    ) as Record<ReportCategory, number>;

    reports.forEach((report) => {
      counts[report.category] += 1;
    });
    return counts;
  }, [reports]);

  const filteredReports = useMemo(() => {
    const normalizedSearch = filters.search.trim().toLowerCase();

    return reports.filter((report) => {
      const matchesCategory = filters.category === 'all' || report.category === filters.category;
      const searchableText = `${report.name} ${report.description}`.toLowerCase();
      return matchesCategory && (!normalizedSearch || searchableText.includes(normalizedSearch));
    });
  }, [filters.category, filters.search, reports]);

  return {
    search: filters.search,
    setSearch: (search: string) => setFilters((current) => ({ ...current, search })),
    category: filters.category,
    setCategory: (category: ReportCatalogCategory) => setFilters((current) => ({ ...current, category })),
    categoryCounts,
    filteredReports,
  };
}
