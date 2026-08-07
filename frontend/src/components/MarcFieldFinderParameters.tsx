import { useEffect, useMemo } from 'react';
import ParamInput from './ParamInput';
import MarcIndicatorInput from './MarcIndicatorInput';
import {
  CONTENT_RULES,
  consumesSearchText,
  evaluateMarcFieldFinder,
  type MarcFieldFinderValues,
} from '../utils/marcFieldFinder';
import type { ReportFieldErrors, ReportParam } from '../types';

type SelectOption = { value: string; label: string };

export interface MarcFieldFinderParametersProps {
  values: MarcFieldFinderValues;
  parameters: ReportParam[];
  selectOptions?: Record<string, SelectOption[]>;
  serverFieldErrors?: ReportFieldErrors;
  onChange: (name: string, value: string) => void;
}

const FALLBACK_OPTIONS: Record<string, SelectOption[]> = {
  locationBasis: [
    { value: 'effective_item', label: 'Effective item' },
    { value: 'permanent_item', label: 'Permanent item' },
  ],
  occurrenceCondition: [
    { value: 'has', label: 'Has matching occurrence' },
    { value: 'missing', label: 'Missing matching occurrence' },
  ],
  contentRule: [
    { value: 'any', label: 'Any' },
    { value: 'contains', label: 'Contains' },
    { value: 'not_contains', label: 'Does not contain' },
    { value: 'equals', label: 'Equals' },
    { value: 'not_equals', label: 'Does not equal' },
    { value: 'begins', label: 'Begins with' },
    { value: 'not_begins', label: 'Does not begin with' },
    { value: 'blank', label: 'Blank' },
    { value: 'not_blank', label: 'Not blank' },
    { value: 'has_lowercase', label: 'Has lowercase' },
    { value: 'has_non_alphanumeric', label: 'Has non-alphanumeric' },
  ],
  caseExact: [
    { value: 'false', label: 'Case-insensitive' },
    { value: 'true', label: 'Case-exact' },
  ],
};

const FALLBACK_PARAMS: Record<string, ReportParam> = {
  locationIds: { name: 'locationIds', type: 'multiselect', label: 'Locations', required: true, default: '', resolvedDefault: '', max_selections: 100 },
  locationBasis: { name: 'locationBasis', type: 'select', label: 'Location basis', required: true, default: 'effective_item', resolvedDefault: 'effective_item' },
  marcTag: { name: 'marcTag', type: 'text', label: 'MARC tag', required: true, default: '', resolvedDefault: '', input_mode: 'numeric', pattern: '[0-9]{3}', max_length: 3 },
  occurrenceCondition: { name: 'occurrenceCondition', type: 'select', label: 'Occurrence condition', required: true, default: 'has', resolvedDefault: 'has' },
  subfieldCode: { name: 'subfieldCode', type: 'text', label: 'Subfield code', required: false, default: '', resolvedDefault: '', max_length: 1 },
  contentRule: { name: 'contentRule', type: 'select', label: 'Content rule', required: true, default: 'any', resolvedDefault: 'any' },
  searchValue: { name: 'searchValue', type: 'text', label: 'Search text', required: false, default: '', resolvedDefault: '' },
  caseExact: { name: 'caseExact', type: 'select', label: 'Case matching', required: true, default: 'false', resolvedDefault: 'false' },
};

const getParam = (parameters: ReportParam[], name: string): ReportParam => (
  parameters.find((parameter) => parameter.name === name) || FALLBACK_PARAMS[name]
);

export default function MarcFieldFinderParameters({
  values,
  parameters,
  selectOptions,
  serverFieldErrors = {},
  onChange,
}: MarcFieldFinderParametersProps) {
  const evaluation = useMemo(() => evaluateMarcFieldFinder(values), [values]);
  const contentRule = values.contentRule || '';
  const showTextFields = consumesSearchText(contentRule);

  useEffect(() => {
    if (showTextFields) return;
    if ((values.searchValue || '') !== '') onChange('searchValue', '');
    if ((values.caseExact || 'false') !== 'false') onChange('caseExact', 'false');
  }, [onChange, showTextFields, values.caseExact, values.searchValue]);

  const optionsFor = (name: string): SelectOption[] => (
    selectOptions?.[name] || FALLBACK_OPTIONS[name] || []
  );

  const clientFieldError = (name: string): string | undefined => evaluation.fieldErrors[name];
  const serverFieldError = (name: string): string | undefined => serverFieldErrors[name];

  const renderParam = (name: string) => {
    const parameter = getParam(parameters, name);
    return (
      <div key={name}>
        <ParamInput
          param={parameter}
          value={values[name] || ''}
          options={optionsFor(name)}
          onChange={(value) => onChange(name, value)}
        />
        {clientFieldError(name) && <p role="alert" className="mt-1 text-xs text-red-600">{clientFieldError(name)}</p>}
        {serverFieldError(name) && serverFieldError(name) !== clientFieldError(name) && (
          <p role="alert" className="mt-1 text-xs text-red-600">{serverFieldError(name)}</p>
        )}
      </div>
    );
  };

  const indicator = (name: 'firstIndicator' | 'secondIndicator', label: string) => (
    <MarcIndicatorInput
      key={name}
      name={name}
      label={label}
      value={values[name] || 'any'}
      error={clientFieldError(name)}
      onChange={(value) => onChange(name, value)}
    />
  );

  return (
    <div className="space-y-4">
      <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
        {renderParam('locationIds')}
        {renderParam('locationBasis')}
        {renderParam('marcTag')}
        {renderParam('occurrenceCondition')}
        <div>
          {indicator('firstIndicator', 'First indicator')}
          {serverFieldError('firstIndicator') && serverFieldError('firstIndicator') !== clientFieldError('firstIndicator') && (
            <p role="alert" className="mt-1 text-xs text-red-600">{serverFieldError('firstIndicator')}</p>
          )}
        </div>
        <div>
          {indicator('secondIndicator', 'Second indicator')}
          {serverFieldError('secondIndicator') && serverFieldError('secondIndicator') !== clientFieldError('secondIndicator') && (
            <p role="alert" className="mt-1 text-xs text-red-600">{serverFieldError('secondIndicator')}</p>
          )}
        </div>
        {renderParam('subfieldCode')}
        {renderParam('contentRule')}
        {showTextFields && renderParam('searchValue')}
        {showTextFields && renderParam('caseExact')}
      </div>

      <div
        aria-label="MARC finder interpretation"
        aria-live="polite"
        className="rounded-xl border border-folio-100 bg-folio-50 px-4 py-3 text-sm text-folio-900"
      >
        <p className="text-xs font-semibold uppercase tracking-wide text-folio-700">Interpretation</p>
        <p className="mt-1">{evaluation.interpretation}</p>
      </div>
    </div>
  );
}

export { CONTENT_RULES };
