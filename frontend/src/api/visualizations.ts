import { api } from './client';

export interface VisualizationUnit {
  id: string;
  code: string;
  name: string;
}

export interface VisualizationOptions {
  units: VisualizationUnit[];
  years: number[];
}

export interface VisualizationFilters {
  acqUnitId: string;
  years: number;
  materialFiscalYearEnd?: number | 'all';
}

export interface VisualizationData {
  unit: VisualizationUnit;
  currentFiscalYearEnd: number | null;
  fiscalYearEnds: number[];
  materialFiscalYearEnds: number[];
  purchasesByCategory: {
    fiscalYearEnd: number;
    electronic: number;
    physical: number;
    other: number;
    total: number;
    electronicPct: number | null;
    physicalPct: number | null;
    otherPct: number | null;
    source: 'history' | 'folio';
  }[];
  purchasesByMaterial: {
    fiscalYearEnd: number | 'all' | null;
    items: { materialType: string; amount: number }[];
  };
  budget: {
    fiscalYearEnd: number;
    allocated: number;
    changePct: number | null;
    source: 'history' | 'folio';
  }[];
}

export interface SpendingPaceMonth {
  fiscalMonth: number;
  label: string;
  currentCumulative: number | null;
  previousCumulative: number | null;
}

export interface SpendingPaceData {
  available: boolean;
  reason: 'no_current_fy' | 'no_series' | 'no_funds' | null;
  unit: VisualizationUnit;
  currentFiscalYearEnd: number | null;
  previousFiscalYearEnd: number | null;
  asOf: string;
  months: SpendingPaceMonth[];
  openEncumbrances: number | null;
  allocated: { amount: number | null; source: 'history' | 'folio' | null; fiscalYearEnd: number | null };
}

export interface VisualizationHistoryInput {
  acqUnitCode: string;
  kind: 'purchases' | 'budget';
  fiscalYearEnd: number;
  totalAmount: number;
  electronicAmount: number | null;
  physicalAmount: number | null;
  note: string | null;
}

export interface VisualizationHistoryRow extends VisualizationHistoryInput {
  id: number;
  updatedBy: number | null;
  updatedAt: string;
}

export async function fetchVisualizationOptions(): Promise<VisualizationOptions> {
  const { data } = await api.get('/visualizations/options');
  return data;
}

export async function fetchVisualizations(filters: VisualizationFilters): Promise<VisualizationData> {
  const { data } = await api.get('/visualizations', { params: filters });
  return data;
}

export async function fetchSpendingPace(acqUnitId: string): Promise<SpendingPaceData> {
  const { data } = await api.get('/visualizations/spending-pace', { params: { acqUnitId } });
  return data;
}

export async function fetchVisualizationHistory(acqUnitCode: string): Promise<VisualizationHistoryRow[]> {
  const { data } = await api.get('/visualizations/history', { params: { acqUnitCode } });
  return data;
}

export async function saveVisualizationHistory(input: VisualizationHistoryInput): Promise<VisualizationHistoryRow> {
  const { data } = await api.put('/visualizations/history', input);
  return data;
}

export async function deleteVisualizationHistory(id: number): Promise<void> {
  await api.delete(`/visualizations/history/${id}`);
}
