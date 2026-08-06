import type { ReportCategory } from '../types';

export const REPORT_CATEGORIES: { key: ReportCategory; label: string }[] = [
  { key: 'acquisitions', label: 'Acquisitions' },
  { key: 'finance', label: 'Finance' },
  { key: 'inventory', label: 'Inventory' },
  { key: 'circulation', label: 'Circulation' },
  { key: 'users', label: 'Users' },
  { key: 'cataloging', label: 'Cataloging' },
  { key: 'other', label: 'Other' },
];

export function formatCategoryLabel(category: ReportCategory): string {
  return REPORT_CATEGORIES.find((item) => item.key === category)?.label ?? category;
}

export function reportParamKey(reportId: number, name: string): string {
  return `rp.${reportId}.${name}`;
}

export function readReportParamsFromSearch(
  reportId: number,
  searchParams: URLSearchParams,
): Record<string, string> {
  const prefix = `rp.${reportId}.`;
  const values: Record<string, string> = {};

  for (const [key, value] of searchParams.entries()) {
    if (key.startsWith(prefix)) {
      values[key.slice(prefix.length)] = value;
    }
  }

  return values;
}

export function writeReportParamsToSearch(
  reportId: number,
  values: Record<string, string>,
  searchParams: URLSearchParams,
): URLSearchParams {
  const next = new URLSearchParams(searchParams);
  const prefix = `rp.${reportId}.`;

  for (const key of Array.from(next.keys())) {
    if (key.startsWith(prefix)) {
      next.delete(key);
    }
  }

  for (const [name, value] of Object.entries(values)) {
    if (value !== '') {
      next.set(reportParamKey(reportId, name), value);
    }
  }

  return next;
}

export function buildTitleListParams(
  materialType: string,
  sourceParams: Record<string, string>,
): Record<string, string> {
  return {
    acqUnitId: sourceParams.acqUnitId || '',
    startDate: sourceParams.startDate || '',
    endDate: sourceParams.endDate || '',
    materialTypeFilter: materialType,
    polNumberFilter: '',
    titleFilter: '',
    poTypeFilter: '',
    poStatusFilter: '',
    invoiceLineStatusFilter: '',
  };
}

export function buildBudgetDrillthroughSearch(
  currentSearchParams: URLSearchParams,
  sourceParams: Record<string, string>,
  materialType: string,
  sourceReportName: string,
): string {
  const next = writeReportParamsToSearch(3, buildTitleListParams(materialType, sourceParams), currentSearchParams);
  next.set('autorun', '3');
  next.set('sourceReportId', '1');
  next.set('sourceReportName', sourceReportName);
  next.set('sourceMaterialType', materialType);
  return next.toString();
}

export function buildSourceReportSearch(
  currentSearchParams: URLSearchParams,
  sourceReportId: number,
): string {
  const next = new URLSearchParams(currentSearchParams);
  next.set('autorun', String(sourceReportId));
  next.delete('sourceReportId');
  next.delete('sourceReportName');
  next.delete('sourceMaterialType');
  return next.toString();
}
