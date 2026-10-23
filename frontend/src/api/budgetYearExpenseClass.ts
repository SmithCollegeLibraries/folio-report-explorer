import { api } from './client';

export interface BudgetYearExpenseClassOptions {
  prefix: string;
  funds: Array<{
    id: string;
    code: string;
    name: string;
    label: string;
    defaultSelected: boolean;
  }>;
  expenseClasses: Array<{ code: string; name: string }>;
  fiscalYears: Array<{
    year: number;
    code: string;
    startDate: string;
    endDate: string;
  }>;
}

export async function getBudgetYearExpenseClassOptions(acqUnit: string): Promise<BudgetYearExpenseClassOptions> {
  const { data } = await api.get('/budget-year-expense-class/options', { params: { acqUnit } });
  return data;
}
