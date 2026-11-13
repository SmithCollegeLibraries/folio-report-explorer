import { describe, expect, it } from 'vitest';
import type { VisualizationData } from '../../api/visualizations';
import { mergeSpendingBudgetRows } from './spendingBudgetRows';

const purchases: VisualizationData['purchasesByCategory'] = [
  { fiscalYearEnd: 2024, electronic: 100, physical: 0, other: -10, total: 90,
    electronicPct: 111.1, physicalPct: 0, otherPct: -11.1, source: 'history' },
  { fiscalYearEnd: 2026, electronic: 0, physical: 0, other: 0, total: 0,
    electronicPct: 0, physicalPct: 0, otherPct: 0, source: 'folio' },
  { fiscalYearEnd: 2030, electronic: 100, physical: 0, other: 0, total: 100,
    electronicPct: 100, physicalPct: 0, otherPct: 0, source: 'history' },
];
const budget: VisualizationData['budget'] = [
  { fiscalYearEnd: 2025, allocated: 0, changePct: null, source: 'history' },
  { fiscalYearEnd: 2027, allocated: -50, changePct: null, source: 'folio' },
  { fiscalYearEnd: 2029, allocated: 100, changePct: null, source: 'history' },
  { fiscalYearEnd: 2030, allocated: 1_000_000, changePct: null, source: 'folio' },
];

describe('mergeSpendingBudgetRows', () => {
  it('joins sparse years without filling missing values and preserves each side’s provenance', () => {
    const rows = mergeSpendingBudgetRows(purchases, budget);

    expect(rows.map((row) => row.fiscalYearEnd)).toEqual([2024, 2025, 2026, 2027, 2029, 2030]);
    expect(rows[0]).toMatchObject({
      electronic: 100, physical: 0, other: -10, total: 90, purchasesSource: 'history',
      allocated: null, allocationSource: null, paidPercent: null,
    });
    expect(rows[1]).toMatchObject({
      electronic: null, total: null, purchasesSource: null, allocated: 0,
      allocationSource: 'history', paidPercent: null,
    });
    expect(rows[2]).toMatchObject({
      electronic: 0, physical: 0, other: 0, total: 0, purchasesSource: 'folio',
      allocated: null, allocationSource: null, paidPercent: null,
    });
    expect(rows[3]).toMatchObject({
      total: null, purchasesSource: null, allocated: -50, allocationSource: 'folio', paidPercent: null,
    });
    expect(rows[4]).toMatchObject({
      total: null, purchasesSource: null, allocated: 100, allocationSource: 'history', paidPercent: null,
    });
    expect(rows[5]).toMatchObject({
      electronic: 100, total: 100, purchasesSource: 'history', allocated: 1_000_000,
      allocationSource: 'folio', paidPercent: 0.01,
    });
  });
});
