import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { getAcrlSectionAssignments } from '../api/acrl';
import type { AcrlSurveyYear } from '../types/acrl';
import { getPublishedAcrlYears } from '../utils/acrl';

export default function AcrlSectionAssignmentsCard({ years }: { years: AcrlSurveyYear[] }) {
  const [year, setYear] = useState<number | ''>('');
  const publishedYears = getPublishedAcrlYears(years);
  const assignmentsQuery = useQuery({
    queryKey: ['acrl-assignments', year],
    queryFn: () => getAcrlSectionAssignments(Number(year)),
    enabled: year !== '',
  });
  const assignedCount = assignmentsQuery.data?.sections.filter((section) => section.assignees.length > 0).length;

  return (
    <section aria-labelledby="acrl-section-assignments-heading" className="space-y-4 rounded-xl border border-gray-200 bg-white p-4 shadow-sm sm:p-5">
      <div>
        <p className="text-xs font-semibold uppercase tracking-[0.16em] text-folio-600">Administrator tools</p>
        <h2 id="acrl-section-assignments-heading" className="mt-1 text-lg font-semibold text-gray-900">Section assignments</h2>
        <p className="mt-1 text-sm text-gray-600">Choose a published year to review section assignments.</p>
      </div>
      <div className="grid gap-3 md:grid-cols-[minmax(12rem,20rem)_1fr] md:items-end">
        <label className="text-sm font-medium text-gray-700">Published survey year
          <select aria-label="Published year for section assignments" className="mt-1 block w-full rounded border border-gray-300 bg-white px-3 py-2" value={year} onChange={(event) => setYear(event.target.value ? Number(event.target.value) : '')}>
            <option value="">Choose a year…</option>
            {publishedYears.map((publishedYear) => <option key={publishedYear} value={publishedYear}>{publishedYear}</option>)}
          </select>
        </label>
        {year !== '' && <div className="flex flex-wrap items-center justify-between gap-3">
          {assignmentsQuery.error
            ? <p role="alert" className="text-sm text-rose-700">Could not load section assignments.</p>
            : assignmentsQuery.data
              ? <p role="status" className="text-sm text-gray-600">{assignedCount} of {assignmentsQuery.data.sections.length} sections have someone assigned</p>
              : <p role="status" className="text-sm text-gray-500">Loading section assignments…</p>}
          <a className="rounded border border-folio-700 px-3 py-2 text-sm font-semibold text-folio-800 hover:bg-folio-50" href={`/acrl/entry/${year}#section-assignments`}>Assign sections for FY {year}</a>
        </div>}
        {publishedYears.length === 0 && <p className="text-sm text-gray-600">No published survey years yet.</p>}
      </div>
    </section>
  );
}
