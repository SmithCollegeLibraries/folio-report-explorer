import { describe, expect, it } from 'vitest';
import * as marcFieldFinder from './marcFieldFinder';

type CombinationEvaluation = {
  fieldErrors: Record<string, string>;
  summary: string;
  valid: boolean;
};

function evaluator(): (values: Record<string, string>) => CombinationEvaluation {
  const candidate = (marcFieldFinder as unknown as Record<string, unknown>).evaluateMarcCombinationFinder;
  expect(typeof candidate).toBe('function');
  return candidate as (values: Record<string, string>) => CombinationEvaluation;
}

function values(overrides: Record<string, string> = {}): Record<string, string> {
  const result: Record<string, string> = {
    locationIds: '11111111-1111-4111-8111-111111111111',
    locationBasis: 'effective_item',
    combine: 'all',
  };
  for (let slot = 1; slot <= 5; slot++) {
    result[`c${slot}_marcTag`] = '';
    result[`c${slot}_occurrenceCondition`] = '';
    result[`c${slot}_firstIndicator`] = '';
    result[`c${slot}_secondIndicator`] = '';
    result[`c${slot}_subfieldCode`] = '';
    result[`c${slot}_contentRule`] = '';
    result[`c${slot}_searchValue`] = '';
    result[`c${slot}_caseExact`] = '';
  }
  Object.assign(result, {
    c1_marcTag: '655',
    c1_occurrenceCondition: 'has',
    c1_firstIndicator: 'any',
    c1_secondIndicator: 'any',
    c1_subfieldCode: 'a',
    c1_contentRule: 'any',
    c1_searchValue: '',
    c1_caseExact: 'false',
  });
  return { ...result, ...overrides };
}

describe('MARC field combination finder client validation', () => {
  it.each([
    ['holdings_no_items', 'Holdings with no items (Smith)'],
    ['no_holdings', 'Instances with no holdings (Smith)'],
  ])('accepts %s without locations and names the scope in its summary', (locationBasis, label) => {
    const result = evaluator()(values({ locationBasis, locationIds: 'stale-location' }));
    expect(result.valid).toBe(true);
    expect(result.fieldErrors).toEqual({});
    expect(result.summary).toContain(label);
    expect(result.summary).not.toContain('location');
  });

  it('places an unsupported location basis error on the same field as the server', () => {
    const result = evaluator()(values({ locationBasis: 'unsupported' }));
    expect(result.fieldErrors.locationBasis).toBe('Location basis must be one of the four supported scopes.');
  });

  it('hydrates record scope URLs and drops stale locations from their submission', () => {
    const parameters = [
      { name: 'locationIds', resolvedDefault: '' },
      { name: 'locationBasis', resolvedDefault: 'effective_item' },
      { name: 'c1_marcTag', resolvedDefault: '' },
    ];
    const incoming = {
      locationIds: '11111111-1111-4111-8111-111111111111',
      locationBasis: 'no_holdings',
      c1_marcTag: '650',
    };
    const hydrated = marcFieldFinder.hydrateMarcCombinationFinderParameters(parameters, incoming);
    expect(hydrated.locationBasis).toBe('no_holdings');
    expect(marcFieldFinder.normalizeMarcCombinationFinderSubmission(parameters, hydrated).locationIds).toBe('');
  });

  it('requires one active condition and places the error on c1_marcTag', () => {
    const result = evaluator()({ ...values(), c1_marcTag: '' });
    expect(result.valid).toBe(false);
    expect(result.fieldErrors.c1_marcTag).toBe('Add at least one MARC condition.');
  });

  it('reports the first empty slot before a later active slot', () => {
    const result = evaluator()(values({ c2_marcTag: '', c3_marcTag: '563' }));
    expect(result.valid).toBe(false);
    expect(result.fieldErrors.c2_marcTag).toBe('Conditions must be contiguous.');
  });

  it('validates active slot fields with cN_ error keys', () => {
    const result = evaluator()(values({ c2_marcTag: '563', c2_contentRule: 'contains', c2_searchValue: '' }));
    expect(result.valid).toBe(false);
    expect(result.fieldErrors.c2_searchValue).toBe('Search text is required for this content rule.');
  });

  it('ignores invalid values in inactive slots and keeps them empty', () => {
    const result = evaluator()(values({
      c2_marcTag: '', c2_firstIndicator: 'bad-indicator', c2_searchValue: 'stale',
      c5_caseExact: 'true',
    }));
    expect(result.valid).toBe(true);
    expect(Object.keys(result.fieldErrors)).toEqual([]);
  });

  it('produces the all/AND and any/OR summaries from active conditions only', () => {
    const ids = Array.from({ length: 95 }, (_, index) => {
      const value = String(index + 1).padStart(8, '0');
      return `${value}-0000-4000-8000-${value}0000`;
    }).join(',');
    const input = values({
      locationIds: ids,
      c1_firstIndicator: 'char:7',
      c1_subfieldCode: 'a',
      c2_marcTag: '563',
      c2_occurrenceCondition: 'missing',
      c2_firstIndicator: 'any',
      c2_secondIndicator: 'any',
      c2_subfieldCode: 'a',
      c2_contentRule: 'any',
      c2_searchValue: '',
      c2_caseExact: 'false',
      c3_marcTag: '',
      c3_firstIndicator: 'bad-inactive-value',
    });
    const all = evaluator()(input);
    expect(all.valid).toBe(true);
    expect(all.summary).toBe('Records in 95 locations that have 655 7? $a AND are missing 563 $a');

    const any = evaluator()({ ...input, combine: 'any' });
    expect(any.summary).toBe('Records in 95 locations that have 655 7? $a OR are missing 563 $a');
  });
});
