import type { ReportParam } from '../types';
import ParamInput from './ParamInput';
import MarcFieldFinderParameters from './MarcFieldFinderParameters';
import BudgetYearExpenseClassParameters from './BudgetYearExpenseClassParameters';
import { MARC_FIELD_FINDER_SLUG } from '../utils/marcFieldFinder';

const BUDGET_YEAR_EXPENSE_CLASS_SLUG = 'budget-year-expense-class';

interface Props {
  slug: string;
  parameters: ReportParam[];
  values: Record<string, string>;
  selectOptions?: Record<string, Array<{ value: string; label: string }>>;
  serverFieldErrors: Record<string, string>;
  onChange: (name: string, value: string) => void;
}

export default function ReportParameterFields({
  slug,
  parameters,
  values,
  selectOptions,
  serverFieldErrors,
  onChange,
}: Props) {
  if (slug === BUDGET_YEAR_EXPENSE_CLASS_SLUG) {
    return (
      <BudgetYearExpenseClassParameters
        parameters={parameters}
        values={values}
        selectOptions={selectOptions}
        serverFieldErrors={serverFieldErrors}
        onChange={onChange}
      />
    );
  }

  if (slug === MARC_FIELD_FINDER_SLUG) {
    return (
      <MarcFieldFinderParameters
        values={values}
        parameters={parameters}
        selectOptions={selectOptions}
        serverFieldErrors={serverFieldErrors}
        onChange={onChange}
      />
    );
  }

  return (
    <div className="grid grid-cols-1 items-start gap-4 md:grid-cols-2 xl:grid-cols-3">
      {parameters.map((parameter) => (
        <div key={parameter.name}>
          <ParamInput
            param={parameter}
            value={values[parameter.name] || ''}
            options={selectOptions?.[parameter.name]}
            error={serverFieldErrors[parameter.name]}
            errorId={'report-error-' + parameter.name}
            onChange={(value) => onChange(parameter.name, value)}
          />
          {serverFieldErrors[parameter.name] && parameter.type !== 'multiselect' && (
            <p id={'report-error-' + parameter.name} role="alert" className="mt-1 text-xs text-red-600">
              {serverFieldErrors[parameter.name]}
            </p>
          )}
        </div>
      ))}
    </div>
  );
}
