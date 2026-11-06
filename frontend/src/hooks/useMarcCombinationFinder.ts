import { useCallback, useMemo } from 'react';
import type { ReportParam } from '../types';
import {
  MARC_COMBINATION_FINDER_SLUG,
  evaluateMarcCombinationFinder,
  normalizeMarcCombinationFinderSubmission,
} from '../utils/marcFieldFinder';

export default function useMarcCombinationFinder(slug: string | undefined, values: Record<string, string>) {
  const isCombinationFinder = slug === MARC_COMBINATION_FINDER_SLUG;
  const evaluation = useMemo(
    () => (isCombinationFinder ? evaluateMarcCombinationFinder(values) : null),
    [isCombinationFinder, values],
  );
  const submission = useCallback((parameters: ReportParam[], input: Record<string, string>) => (
    isCombinationFinder ? normalizeMarcCombinationFinderSubmission(parameters, input) : null
  ), [isCombinationFinder]);
  return {
    isCombinationFinder,
    valid: evaluation?.valid === true,
    submission,
  };
}
