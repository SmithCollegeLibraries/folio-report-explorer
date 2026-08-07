import type { ReportFieldErrors, ReportParameterValues } from '../types';

export const MARC_FIELD_FINDER_SLUG = 'marc-field-indicator-content-finder';

export const CONTENT_RULES = [
  'any',
  'contains',
  'not_contains',
  'equals',
  'not_equals',
  'begins',
  'not_begins',
  'blank',
  'not_blank',
  'has_lowercase',
  'has_non_alphanumeric',
] as const;

export type MarcContentRule = (typeof CONTENT_RULES)[number];
export type MarcFieldFinderValues = ReportParameterValues;

const TEXT_RULES = new Set<MarcContentRule>([
  'contains',
  'not_contains',
  'equals',
  'not_equals',
  'begins',
  'not_begins',
]);

const UUID_PATTERN = /^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;
const PHP_TRIM_BLANK = /^[\t\n\r\0\x0B ]$/;

export interface MarcFieldFinderEvaluation {
  fieldErrors: ReportFieldErrors;
  interpretation: string;
  textRule: MarcContentRule | null;
  valid: boolean;
}

export function consumesSearchText(rule: string | undefined): rule is MarcContentRule {
  return typeof rule === 'string' && TEXT_RULES.has(rule as MarcContentRule);
}

export function normalizeIndicator(value: string | undefined): string {
  if (!value) return 'any';
  if (value === 'any' || value === 'blank') return value;
  if (value.startsWith('char:')) {
    const character = value.slice(5);
    if (character === '\\' || PHP_TRIM_BLANK.test(character)) return 'blank';
  }
  return value;
}

function indicatorDisplay(value: string | undefined): string | null {
  const normalized = normalizeIndicator(value);
  if (normalized === 'any') return null;
  if (normalized === 'blank') return '#';
  return normalized.startsWith('char:') ? normalized.slice(5) : normalized;
}

function values(value: string | undefined): string {
  return typeof value === 'string' ? value : '';
}

function error(fieldErrors: ReportFieldErrors, field: string, message: string): void {
  if (!fieldErrors[field]) fieldErrors[field] = message;
}

function validateIndicator(fieldErrors: ReportFieldErrors, field: string, value: string): void {
  if (!value) {
    error(fieldErrors, field, 'Choose an indicator value.');
    return;
  }
  if (value === 'any' || value === 'blank') return;
  if (!value.startsWith('char:') || [...value.slice(5)].length !== 1) {
    error(fieldErrors, field, 'Indicator must be Any, Blank, or one custom character.');
  }
}

function contentDescription(rule: MarcContentRule, searchValue: string, caseExact: boolean): string | null {
  const quoted = `“${searchValue}”`;
  const capitalization = caseExact ? ', matching capitalization exactly' : ', ignoring capitalization';
  switch (rule) {
    case 'contains': return `content contains ${quoted}${capitalization}`;
    case 'not_contains': return `content does not contain ${quoted}${capitalization}`;
    case 'equals': return `content equals ${quoted}${capitalization}`;
    case 'not_equals': return `content does not equal ${quoted}${capitalization}`;
    case 'begins': return `content begins with ${quoted}${capitalization}`;
    case 'not_begins': return `content does not begin with ${quoted}${capitalization}`;
    case 'blank': return 'content is blank';
    case 'not_blank': return 'content is not blank';
    case 'has_lowercase': return 'content contains lowercase characters';
    case 'has_non_alphanumeric': return 'content contains non-alphanumeric characters';
    default: return null;
  }
}

export function evaluateMarcFieldFinder(valuesInput: MarcFieldFinderValues): MarcFieldFinderEvaluation {
  const input = valuesInput || {};
  const fieldErrors: ReportFieldErrors = {};
  const rawLocationIds = values(input.locationIds);
  const locationIds = rawLocationIds.trim() === ''
    ? []
    : rawLocationIds.split(',').map((id) => id.trim());
  if (locationIds.length === 0) error(fieldErrors, 'locationIds', 'Select at least one location.');
  if (locationIds.length > 100) error(fieldErrors, 'locationIds', 'Select no more than 100 locations.');
  if (locationIds.some((id) => !UUID_PATTERN.test(id))) {
    error(fieldErrors, 'locationIds', 'Every selected location must be a valid UUID.');
  }
  if (new Set(locationIds.map((id) => id.toLowerCase())).size !== locationIds.length) {
    error(fieldErrors, 'locationIds', 'Selected locations must be unique.');
  }

  const locationBasis = values(input.locationBasis);
  if (!['effective_item', 'permanent_item'].includes(locationBasis)) {
    error(fieldErrors, 'locationBasis', 'Choose effective item or permanent item.');
  }

  const marcTag = values(input.marcTag);
  if (!/^(?:00[1-9]|0[1-9][0-9]|[1-9][0-9]{2})$/.test(marcTag)) {
    error(fieldErrors, 'marcTag', 'MARC tag must be exactly three digits from 001 through 999.');
  }

  const occurrenceCondition = values(input.occurrenceCondition);
  if (!['has', 'missing'].includes(occurrenceCondition)) {
    error(fieldErrors, 'occurrenceCondition', 'Choose whether matching occurrences are present or missing.');
  }

  const firstIndicator = values(input.firstIndicator);
  const secondIndicator = values(input.secondIndicator);
  validateIndicator(fieldErrors, 'firstIndicator', firstIndicator);
  validateIndicator(fieldErrors, 'secondIndicator', secondIndicator);

  const subfieldCode = values(input.subfieldCode);
  if (subfieldCode !== '' && !/^[A-Za-z0-9]$/.test(subfieldCode)) {
    error(fieldErrors, 'subfieldCode', 'Subfield code must be blank or one alphanumeric character.');
  }

  const contentRuleValue = values(input.contentRule);
  const contentRule = CONTENT_RULES.includes(contentRuleValue as MarcContentRule)
    ? contentRuleValue as MarcContentRule
    : null;
  if (!contentRule) {
    error(fieldErrors, 'contentRule', 'Choose a supported content rule.');
  }

  const searchValue = values(input.searchValue);
  const caseExactValue = values(input.caseExact);
  if (!['true', 'false'].includes(caseExactValue)) {
    error(fieldErrors, 'caseExact', 'Choose whether capitalization must match exactly.');
  }
  if (contentRule && consumesSearchText(contentRule)) {
    if (searchValue === '') error(fieldErrors, 'searchValue', 'Search text is required for this content rule.');
  } else if (searchValue !== '') {
    error(fieldErrors, 'searchValue', 'Search text is only used with a text comparison rule.');
  }

  const textRule = contentRule && consumesSearchText(contentRule) ? contentRule : null;
  const criteria: string[] = [`tag ${marcTag || '—'}`];
  const first = indicatorDisplay(firstIndicator);
  const second = indicatorDisplay(secondIndicator);
  if (first !== null) criteria.push(`first indicator ${first}`);
  if (second !== null) criteria.push(`second indicator ${second}`);
  criteria.push(subfieldCode ? `subfield ${subfieldCode}` : 'any subfield');
  const content = contentRule ? contentDescription(contentRule, searchValue, caseExactValue === 'true') : null;
  if (content) criteria.push(content);
  const criteriaText = criteria.join(', ');
  const conditionText = occurrenceCondition === 'missing'
    ? `no field row matches: ${criteriaText}`
    : `at least one field row matches: ${criteriaText}`;
  const interpretation = `Return MARC records where ${conditionText}.`;

  return { fieldErrors, interpretation, textRule, valid: Object.keys(fieldErrors).length === 0 };
}
