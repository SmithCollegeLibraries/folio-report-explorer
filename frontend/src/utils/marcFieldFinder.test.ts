import { describe, expect, it } from 'vitest';
import { evaluateMarcFieldFinder, normalizeIndicator } from './marcFieldFinder';

const validValues = {
  locationIds: '11111111-1111-4111-8111-111111111111',
  locationBasis: 'effective_item',
  marcTag: '245',
  occurrenceCondition: 'has',
  firstIndicator: 'any',
  secondIndicator: 'any',
  subfieldCode: '',
  contentRule: 'any',
  searchValue: '',
  caseExact: 'false',
};

describe('evaluateMarcFieldFinder', () => {
  it('describes a missing occurrence with normalized indicators', () => {
    const result = evaluateMarcFieldFinder({
      ...validValues,
      marcTag: '035',
      occurrenceCondition: 'missing',
      firstIndicator: 'blank',
      secondIndicator: 'char:9',
      subfieldCode: 'a',
    });

    expect(result.valid).toBe(true);
    expect(result.interpretation).toContain(
      'no field row matches: tag 035, first indicator #, second indicator 9, subfield a',
    );
  });

  it('describes literal text rules and exact capitalization', () => {
    const result = evaluateMarcFieldFinder({
      ...validValues,
      contentRule: 'not_begins',
      searchValue: '(SCTFEBA)',
      caseExact: 'true',
    });

    expect(result.textRule).toBe('not_begins');
    expect(result.interpretation).toContain(
      'content does not begin with “(SCTFEBA)”, matching capitalization exactly',
    );
  });

  it('accepts literal wildcard and punctuation characters as search text', () => {
    const result = evaluateMarcFieldFinder({
      ...validValues,
      contentRule: 'contains',
      searchValue: `%_\\'`,
    });

    expect(result.valid).toBe(true);
    expect(result.fieldErrors).toEqual({});
  });

  it('enforces the same required field rules as the backend', () => {
    const result = evaluateMarcFieldFinder({
      ...validValues,
      locationIds: '',
      locationBasis: 'holdings',
      marcTag: '000',
      occurrenceCondition: 'maybe',
      firstIndicator: 'char:',
      secondIndicator: 'char:too-long',
      subfieldCode: 'ab',
      contentRule: 'contains',
      searchValue: '',
      caseExact: 'sometimes',
    });

    expect(result.valid).toBe(false);
    expect(Object.keys(result.fieldErrors)).toEqual(expect.arrayContaining([
      'locationIds', 'locationBasis', 'marcTag', 'occurrenceCondition',
      'firstIndicator', 'secondIndicator', 'subfieldCode', 'searchValue', 'caseExact',
    ]));
  });

  it('treats a typed backslash as a blank indicator', () => {
    const result = evaluateMarcFieldFinder({
      ...validValues,
      firstIndicator: 'char:\\',
    });

    expect(result.valid).toBe(true);
    expect(result.interpretation).toContain('first indicator #');
  });

  it('only treats the explicit MARC backslash and ASCII space as blank', () => {
    expect(normalizeIndicator('char:\\')).toBe('blank');
    expect(normalizeIndicator('char: ')).toBe('blank');
    expect(normalizeIndicator('char:\u00a0')).toBe('char:\u00a0');
    expect(normalizeIndicator('char:\n')).toBe('blank');
    expect(normalizeIndicator('char:\t')).toBe('blank');
  });

  it('rejects empty comma-separated location segments', () => {
    const result = evaluateMarcFieldFinder({
      ...validValues,
      locationIds: `${validValues.locationIds},`,
    });
    expect(result.valid).toBe(false);
    expect(result.fieldErrors.locationIds).toContain('valid UUID');
  });
});
