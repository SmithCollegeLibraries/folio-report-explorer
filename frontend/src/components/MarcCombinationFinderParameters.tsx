import { useMemo } from 'react';
import ParamInput from './ParamInput';
import MarcIndicatorInput from './MarcIndicatorInput';
import {
  CONTENT_RULES,
  MARC_COMBINATION_RECORD_SCOPES,
  consumesSearchText,
  evaluateMarcCombinationFinder,
  findMarcSubfieldCodes,
  type MarcFieldFinderValues,
} from '../utils/marcFieldFinder';
import type { ReportFieldErrors, ReportParam } from '../types';

type SelectOption = { value: string; label: string };

export interface MarcCombinationFinderParametersProps {
  values: MarcFieldFinderValues;
  parameters: ReportParam[];
  selectOptions?: Record<string, SelectOption[]>;
  serverFieldErrors?: ReportFieldErrors;
  onChange: (name: string, value: string) => void;
}

const SLOT_FIELDS = [
  'marcTag', 'occurrenceCondition', 'firstIndicator', 'secondIndicator',
  'subfieldCode', 'contentRule', 'searchValue', 'caseExact',
];

const FALLBACK_OPTIONS: Record<string, SelectOption[]> = {
  locationBasis: [
    { value: 'effective_item', label: 'Effective item' },
    { value: 'permanent_item', label: 'Permanent item' },
  ],
  combine: [
    { value: 'all', label: 'All conditions (AND)' },
    { value: 'any', label: 'Any condition (OR)' },
  ],
  occurrenceCondition: [
    { value: 'has', label: 'Has matching occurrence' },
    { value: 'missing', label: 'Missing matching occurrence' },
  ],
  contentRule: CONTENT_RULES.map((value) => ({
    value,
    label: ({
      any: 'Any', contains: 'Contains', not_contains: 'Does not contain', equals: 'Equals',
      not_equals: 'Does not equal', begins: 'Begins with', not_begins: 'Does not begin with',
      blank: 'Blank', not_blank: 'Not blank', has_lowercase: 'Has lowercase',
      has_non_alphanumeric: 'Has non-alphanumeric',
    } as Record<string, string>)[value],
  })),
  caseExact: [
    { value: 'false', label: 'Case-insensitive' },
    { value: 'true', label: 'Case-exact' },
  ],
};

const PARAM_DEFAULTS: Record<string, { type: ReportParam['type']; label: string; default: string }> = {
  locationIds: { type: 'multiselect', label: 'Locations', default: '' },
  locationBasis: { type: 'select', label: 'Location basis', default: 'effective_item' },
  combine: { type: 'select', label: 'Combine conditions', default: 'all' },
  marcTag: { type: 'text', label: 'MARC tag', default: '' },
  occurrenceCondition: { type: 'select', label: 'Occurrence condition', default: 'has' },
  subfieldCode: { type: 'text', label: 'Subfield code', default: '' },
  contentRule: { type: 'select', label: 'Content rule', default: 'any' },
  searchValue: { type: 'text', label: 'Search text', default: '' },
  caseExact: { type: 'select', label: 'Case matching', default: 'false' },
};

function defaultValue(name: string): string {
  if (name === 'firstIndicator' || name === 'secondIndicator') return 'any';
  return PARAM_DEFAULTS[name]?.default ?? '';
}

export default function MarcCombinationFinderParameters({
  values,
  parameters,
  selectOptions,
  serverFieldErrors = {},
  onChange,
}: MarcCombinationFinderParametersProps) {
  const evaluation = useMemo(() => evaluateMarcCombinationFinder(values), [values]);
  const errors = (name: string): string[] => [...new Set([
    evaluation.fieldErrors[name], serverFieldErrors[name],
  ].filter((message): message is string => Boolean(message)))];
  const errorText = (name: string): string | undefined => errors(name).join(' ') || undefined;
  const parameter = (name: string, label?: string): ReportParam => {
    const found = parameters.find((item) => item.name === name);
    const field = name.replace(/^c[1-5]_/, '');
    const fallback = PARAM_DEFAULTS[field] || { type: 'text' as const, label: field, default: '' };
    return {
      name,
      type: fallback.type,
      label: label || found?.label || fallback.label,
      required: name.startsWith('c') ? false : Boolean(found?.required),
      default: found?.default ?? fallback.default,
      resolvedDefault: found?.resolvedDefault ?? fallback.default,
      placeholder: found?.placeholder,
      input_mode: found?.input_mode,
      pattern: found?.pattern,
      max_length: found?.max_length,
      max_selections: found?.max_selections,
      description: found?.description,
    };
  };
  const renderParam = (name: string, label?: string, groupSelect = false) => {
    const field = parameter(name, label);
    const availableOptions = selectOptions?.[name] || FALLBACK_OPTIONS[field.name.replace(/^c[1-5]_/, '')];
    const options = field.name === 'locationBasis'
      ? availableOptions.filter((option) => option.value === 'effective_item' || option.value === 'permanent_item')
      : availableOptions;
    return (
      <div key={name}>
        <ParamInput
          param={field}
          value={values[name] || ''}
          options={options}
          groupSelect={groupSelect}
          error={errorText(name)}
          errorId={'marc-combination-' + name + '-error'}
          onChange={(value) => change(name, value)}
        />
        {field.type !== 'multiselect' && errors(name).map((message, index) => (
          <p key={message} id={'marc-combination-' + name + '-error' + (index ? '-' + index : '')} role="alert" className="mt-1 text-xs text-red-600">{message}</p>
        ))}
      </div>
    );
  };

  const visibleCount = Math.max(1, ...Array.from({ length: 5 }, (_, index) => index + 1).filter((slot) => (
    values['c' + slot + '_marcTag'] || values['c' + slot + '_occurrenceCondition']
  )));
  const hasEmptyVisibleCondition = Array.from({ length: visibleCount }, (_, index) => index + 1)
    .some((slot) => !values['c' + slot + '_marcTag']);
  const activeCount = Array.from({ length: 5 }, (_, index) => values['c' + (index + 1) + '_marcTag'])
    .filter((tag) => Boolean(tag)).length;
  const itemScope = values.locationBasis !== 'holdings_no_items' && values.locationBasis !== 'no_holdings';

  function changeSearchScope(scope: string) {
    onChange('locationIds', '');
    onChange('locationBasis', scope === 'items' ? 'effective_item' : scope);
  }

  function change(name: string, value: string) {
    if (/^c[1-5]_marcTag$/.test(name) && value === '') {
      const slot = Number(name.match(/^c([1-5])_/)?.[1] || 1);
      const later = Array.from({ length: 5 - slot }, (_, index) => slot + index + 1)
        .some((number) => Boolean(values['c' + number + '_marcTag']));
      if (later) {
        for (let number = slot; number < 5; number++) {
          SLOT_FIELDS.forEach((field) => onChange('c' + number + '_' + field, values['c' + (number + 1) + '_' + field] || ''));
        }
        SLOT_FIELDS.forEach((field) => onChange('c5_' + field, ''));
      } else {
        SLOT_FIELDS.forEach((field) => onChange('c' + slot + '_' + field, ''));
      }
      return;
    }
    const activatedSlot = /^c([1-5])_marcTag$/.exec(name);
    if (activatedSlot && value !== '' && !values[name]) {
      const slot = Number(activatedSlot[1]);
      SLOT_FIELDS.filter((field) => field !== 'marcTag').forEach((field) => {
        const parameterName = 'c' + slot + '_' + field;
        if (values[parameterName] === '') onChange(parameterName, defaultValue(field));
      });
    }
    onChange(name, value);
    if (/^c[1-5]_contentRule$/.test(name) && !consumesSearchText(value)) {
      const prefix = name.slice(0, 3);
      onChange(prefix + 'searchValue', '');
      onChange(prefix + 'caseExact', 'false');
    }
  }

  function addCondition() {
    const slot = visibleCount + 1;
    if (slot > 5) return;
    SLOT_FIELDS.forEach((field) => onChange('c' + slot + '_' + field, defaultValue(field)));
  }

  function removeCondition(slot: number) {
    if (slot === 1 && activeCount <= 1) return;
    for (let number = slot; number < 5; number++) {
      SLOT_FIELDS.forEach((field) => onChange('c' + number + '_' + field, values['c' + (number + 1) + '_' + field] || ''));
    }
    SLOT_FIELDS.forEach((field) => onChange('c5_' + field, ''));
  }

  const indicator = (slot: number, field: 'firstIndicator' | 'secondIndicator', label: string) => {
    const name = 'c' + slot + '_' + field;
    return (
      <MarcIndicatorInput
        name={name}
        label={label}
        value={values[name] || 'any'}
        error={errorText(name)}
        errorId={'marc-combination-' + name + '-error'}
        onChange={(value) => onChange(name, value)}
      />
    );
  };

  return (
    <div className="space-y-4">
      <fieldset className="space-y-2">
        <legend className="text-sm font-semibold text-stone-700">Search</legend>
        <div className="flex flex-wrap gap-x-6 gap-y-2">
          <label className="flex items-center gap-2 text-sm text-stone-700">
            <input type="radio" name="marc-combination-search" value="items" checked={itemScope} onChange={() => changeSearchScope('items')} />
            Items at selected locations
          </label>
          {Object.entries(MARC_COMBINATION_RECORD_SCOPES).map(([basis, label]) => (
            <label key={basis} className="flex items-center gap-2 text-sm text-stone-700">
              <input type="radio" name="marc-combination-search" value={basis} checked={values.locationBasis === basis} onChange={() => changeSearchScope(basis)} />
              {label}
            </label>
          ))}
        </div>
      </fieldset>

      <div className="grid grid-cols-1 items-start gap-4 md:grid-cols-2 xl:grid-cols-3">
        {itemScope && renderParam('locationIds', 'Locations', true)}
        {itemScope && renderParam('locationBasis', 'Location basis')}
        {renderParam('combine', 'Combine conditions')}
      </div>

      <div className="space-y-3">
        {Array.from({ length: visibleCount }, (_, index) => index + 1).map((slot) => {
          const prefix = 'c' + slot + '_';
          const hasTextRule = consumesSearchText(values[prefix + 'contentRule'] || '');
          const searchSubfieldCodes = findMarcSubfieldCodes(values[prefix + 'searchValue'] || '');
          const validTag = /^(?:00[1-9]|0[1-9][0-9]|[1-9][0-9]{2})$/.test(values[prefix + 'marcTag'] || '');
          return (
            <section key={slot} role="group" aria-label={'Condition ' + slot} className="rounded-xl border border-stone-200 p-4">
              <div className="mb-3 flex items-center justify-between">
                <h3 className="text-sm font-semibold text-stone-700">Condition {slot}</h3>
                <button
                  type="button"
                  onClick={() => removeCondition(slot)}
                  disabled={slot === 1 && activeCount <= 1}
                  className="rounded-lg border border-stone-200 px-3 py-1.5 text-xs font-medium text-stone-600 disabled:cursor-not-allowed disabled:opacity-40"
                  aria-label={'Remove condition ' + slot}
                >
                  Remove
                </button>
              </div>
              <div className="grid grid-cols-1 items-start gap-4 md:grid-cols-2 xl:grid-cols-3">
                {renderParam(prefix + 'marcTag', 'Condition ' + slot + ' MARC tag')}
                {renderParam(prefix + 'occurrenceCondition', 'Condition ' + slot + ' occurrence')}
                {validTag && indicator(slot, 'firstIndicator', 'Condition ' + slot + ' first indicator')}
                {validTag && indicator(slot, 'secondIndicator', 'Condition ' + slot + ' second indicator')}
                {validTag && renderParam(prefix + 'subfieldCode', 'Condition ' + slot + ' subfield code')}
                {renderParam(prefix + 'contentRule', 'Condition ' + slot + ' content rule')}
                {hasTextRule && (
                  <div>
                    {renderParam(prefix + 'searchValue', 'Condition ' + slot + ' search text')}
                    {searchSubfieldCodes.length > 0 && (
                      <p role="status" aria-live="polite" className="mt-1 text-xs text-amber-800">
                        This looks like MARC subfield coding ({searchSubfieldCodes[0]}). Enter only the text inside one subfield, and choose the subfield in the Subfield box.
                        {searchSubfieldCodes[1] && ` To also match ${searchSubfieldCodes[1]}, add another condition on the same tag.`}
                      </p>
                    )}
                  </div>
                )}
                {hasTextRule && renderParam(prefix + 'caseExact', 'Condition ' + slot + ' case matching')}
              </div>
            </section>
          );
        })}
      </div>

      <button
        type="button"
        onClick={addCondition}
        disabled={visibleCount >= 5 || hasEmptyVisibleCondition}
        className="rounded-lg border border-folio-200 px-3 py-2 text-sm font-medium text-folio-700 disabled:cursor-not-allowed disabled:opacity-40"
      >
        Add condition
      </button>

      <div aria-label="MARC combination interpretation" aria-live="polite" className="rounded-xl border border-folio-100 bg-folio-50 px-4 py-3 text-sm text-folio-900">
        <p className="text-xs font-semibold uppercase tracking-wide text-folio-700">Interpretation</p>
        <p className="mt-1">{evaluation.summary}</p>
      </div>
    </div>
  );
}
