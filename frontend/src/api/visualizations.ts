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
    electronicPct: number;
    physicalPct: number;
    otherPct: number;
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
  throw new Error('not implemented');
}

export async function fetchVisualizations(_filters: VisualizationFilters): Promise<VisualizationData> {
  throw new Error('not implemented');
}

export async function fetchVisualizationHistory(_acqUnitCode: string): Promise<VisualizationHistoryRow[]> {
  throw new Error('not implemented');
}

export async function saveVisualizationHistory(_input: VisualizationHistoryInput): Promise<VisualizationHistoryRow> {
  throw new Error('not implemented');
}

export async function deleteVisualizationHistory(_id: number): Promise<void> {
  throw new Error('not implemented');
}
