import { useEffect, useMemo, useRef } from 'react';
import { useQuery } from '@tanstack/react-query';
import type { ReportParam } from '../types';
import { getBudgetYearExpenseClassOptions } from '../api/budgetYearExpenseClass';
import SearchableMultiSelect from './SearchableMultiSelect';

const COLLEGES = [
  { code: 'AC', name: 'Amherst College' },
  { code: 'HC', name: 'Hampshire College' },
  { code: 'MH', name: 'Mount Holyoke College' },
  { code: 'SC', name: 'Smith College' },
  { code: 'UM', name: 'UMass Amherst' },
];

interface Props {
  parameters: ReportParam[];
  values: Record<string, string>;
  selectOptions?: Record<string, Array<{ value: string; label: string }>>;
  serverFieldErrors: Record<string, string>;
  onChange: (name: string, value: string) => void;
}

export default function BudgetYearExpenseClassParameters({
  parameters,
  values,
  serverFieldErrors,
  onChange,
}: Props) {
  const unit = values.acqUnit || '';
  const defaultedUnit = useRef('');
  const optionsQuery = useQuery({
    queryKey: ['budget-year-expense-class-options', unit],
    queryFn: () => getBudgetYearExpenseClassOptions(unit),
    enabled: unit !== '',
  });
  const options = optionsQuery.data?.prefix === unit ? optionsQuery.data : undefined;
  const fiscalYears = options?.fiscalYears ?? [];
  const selectedFiscalYear = useMemo(
    () => fiscalYears.find((year) => year.year === Number(values.fiscalYear)),
    [fiscalYears, values.fiscalYear],
  );
  const label = (name: string, fallback: string) =>
    parameters.find((parameter) => parameter.name === name)?.label || fallback;
  const description = (name: string, fallback: string) =>
    parameters.find((parameter) => parameter.name === name)?.description || fallback;

  useEffect(() => {
    if (!unit || !options || defaultedUnit.current === unit) {
      return;
    }
    const year = fiscalYears.find((item) => item.year === Number(values.fiscalYear)) || fiscalYears[0];
    if (year) {
      if (!values.fiscalYear) onChange('fiscalYear', String(year.year));
    }
    if (!values.funds) {
      onChange(
        'funds',
        options.funds.filter((fund) => fund.defaultSelected).map((fund) => fund.id).join(','),
      );
    }
    defaultedUnit.current = unit;
  }, [fiscalYears, onChange, options, unit, values.fiscalYear, values.funds]);

  const fundOptions = useMemo(
    () => (options?.funds ?? []).map((fund) => ({
      value: fund.id,
      label: fund.label || (fund.code + ' — ' + fund.name),
    })),
    [options],
  );

  const selectUnit = (nextUnit: string) => {
    defaultedUnit.current = '';
    onChange('acqUnit', nextUnit);
    onChange('fiscalYear', '');
    onChange('startDate', '');
    onChange('endDate', '');
    onChange('funds', '');
  };

  const selectYear = (nextYear: string) => {
    onChange('fiscalYear', nextYear);
  };

  const fieldError = (name: string) => serverFieldErrors[name];
  const error = (name: string) => fieldError(name)
    ? <p id={'report-error-' + name} role="alert" className="mt-1 text-xs text-red-600">{fieldError(name)}</p>
    : null;

  return (
    <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
      <div>
        <label htmlFor="report-param-acqUnit" className="mb-1 block text-xs font-medium text-gray-600">
          {label('acqUnit', 'Acquisitions Unit')}<span aria-hidden="true" className="ml-0.5 text-red-400">*</span>
        </label>
        <select
          id="report-param-acqUnit"
          value={unit}
          required
          aria-invalid={fieldError('acqUnit') ? true : undefined}
          aria-describedby={fieldError('acqUnit') ? 'report-error-acqUnit' : undefined}
          onChange={(event) => selectUnit(event.target.value)}
          className="w-full rounded-lg border border-stone-300 bg-white px-3 py-2 text-sm"
        >
          <option value="">Select college</option>
          {COLLEGES.map((college) => (
            <option key={college.code} value={college.code}>{college.name} ({college.code})</option>
          ))}
        </select>
        {error('acqUnit')}
      </div>

      <div>
        <label htmlFor="report-param-fiscalYear" className="mb-1 block text-xs font-medium text-gray-600">
          {label('fiscalYear', 'Fiscal Year')}<span aria-hidden="true" className="ml-0.5 text-red-400">*</span>
        </label>
        <input
          id="report-param-fiscalYear"
          type="number"
          list="budget-year-expense-class-years"
          value={values.fiscalYear || ''}
          required
          aria-invalid={fieldError('fiscalYear') ? true : undefined}
          aria-describedby={fieldError('fiscalYear') ? 'report-error-fiscalYear' : undefined}
          onChange={(event) => selectYear(event.target.value)}
          disabled={!unit}
          className="w-full rounded-lg border border-stone-300 px-3 py-2 text-sm"
        />
        <datalist id="budget-year-expense-class-years">
          {fiscalYears.map((year) => <option key={year.code} value={year.year} label={year.code} />)}
        </datalist>
        {error('fiscalYear')}
      </div>

      <div>
        <label htmlFor="report-param-startDate" className="mb-1 block text-xs font-medium text-gray-600">
          {label('startDate', 'Start Date')}
        </label>
        <input
          id="report-param-startDate"
          type="date"
          value={values.startDate || ''}
          aria-invalid={fieldError('startDate') ? true : undefined}
          aria-describedby={fieldError('startDate') ? 'report-error-startDate' : undefined}
          onChange={(event) => onChange('startDate', event.target.value)}
          disabled={!selectedFiscalYear}
          className="w-full rounded-lg border border-stone-300 px-3 py-2 text-sm"
        />
        <p className="mt-1 text-xs text-gray-400">
          {description('startDate', 'Optional inclusive payment-date lower bound; leave blank for no lower bound.')}
        </p>
        {error('startDate')}
      </div>

      <div>
        <label htmlFor="report-param-endDate" className="mb-1 block text-xs font-medium text-gray-600">
          {label('endDate', 'End Date')}
        </label>
        <input
          id="report-param-endDate"
          type="date"
          value={values.endDate || ''}
          aria-invalid={fieldError('endDate') ? true : undefined}
          aria-describedby={fieldError('endDate') ? 'report-error-endDate' : undefined}
          onChange={(event) => onChange('endDate', event.target.value)}
          disabled={!selectedFiscalYear}
          className="w-full rounded-lg border border-stone-300 px-3 py-2 text-sm"
        />
        <p className="mt-1 text-xs text-gray-400">
          {description('endDate', 'Optional inclusive payment-date upper bound; leave blank for no upper bound.')}
        </p>
        {error('endDate')}
      </div>

      <div>
        <label htmlFor="report-param-funds" className="mb-1 block text-xs font-medium text-gray-600">
          {label('funds', 'Funds')}<span aria-hidden="true" className="ml-0.5 text-red-400">*</span>
        </label>
        <SearchableMultiSelect
          id="report-param-funds"
          label={label('funds', 'Funds')}
          value={values.funds || ''}
          options={fundOptions}
          required
          placeholder="Search funds by code or name"
          maxSelections={500}
          error={fieldError('funds')}
          errorId="report-error-funds"
          onChange={(value) => onChange('funds', value)}
        />
        {optionsQuery.isError && <p role="alert" className="mt-1 text-xs text-red-600">College options could not be loaded.</p>}
      </div>
    </div>
  );
}
