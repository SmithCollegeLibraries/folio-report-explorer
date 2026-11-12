import type { VisualizationData } from '../../api/visualizations';

export type SpendingBudgetRow = {
  fiscalYearEnd: number;
  electronic: number | null;
  physical: number | null;
  other: number | null;
  total: number | null;
  purchasesSource: 'history' | 'folio' | null;
  allocated: number | null;
  allocationSource: 'history' | 'folio' | null;
  paidPercent: number | null;
};

export function mergeSpendingBudgetRows(
  purchases: VisualizationData['purchasesByCategory'],
  budget: VisualizationData['budget'],
): SpendingBudgetRow[] {
  const purchasesByYear = new Map(purchases.map((row) => [row.fiscalYearEnd, row]));
  const budgetByYear = new Map(budget.map((row) => [row.fiscalYearEnd, row]));
  const years = new Set([...purchasesByYear.keys(), ...budgetByYear.keys()]);

  return Array.from(years).sort((a, b) => a - b).map((fiscalYearEnd) => {
    const purchase = purchasesByYear.get(fiscalYearEnd);
    const allocation = budgetByYear.get(fiscalYearEnd);
    return {
      fiscalYearEnd,
      electronic: purchase?.electronic ?? null,
      physical: purchase?.physical ?? null,
      other: purchase?.other ?? null,
      total: purchase?.total ?? null,
      purchasesSource: purchase?.source ?? null,
      allocated: allocation?.allocated ?? null,
      allocationSource: allocation?.source ?? null,
      paidPercent: purchase && allocation && allocation.allocated > 0
        ? purchase.total / allocation.allocated * 100 : null,
    };
  });
}
