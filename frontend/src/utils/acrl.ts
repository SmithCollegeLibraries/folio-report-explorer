import type { AcrlSurveyYear } from '../types/acrl';

export function getPublishedAcrlYears(years: AcrlSurveyYear[]): number[] {
  return years.filter((year) => year.status === 'published').map((year) => year.surveyYear);
}
