import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { copyAcrlAssignmentsFromPrevious, getAcrlSectionAssignments, saveAcrlSectionAssignments, searchAcrlApprovedUsers } from '../api/acrl';
import type { AcrlAssignmentCopyResponse, AcrlEntrySection } from '../types/acrl';

type AcrlSectionAssignmentPanelProps = {
  year: number;
  sections: AcrlEntrySection[];
  hasPreviousYear?: boolean;
};

export default function AcrlSectionAssignmentPanel({ year, sections, hasPreviousYear = true }: AcrlSectionAssignmentPanelProps) {
  const queryClient = useQueryClient();
  const [section, setSection] = useState(sections[0]?.name || '');
  const [search, setSearch] = useState('');
  const [drafts, setDrafts] = useState<Record<string, number[]>>({});
  const [copyResult, setCopyResult] = useState<AcrlAssignmentCopyResponse | null>(null);
  const assignmentsQuery = useQuery({
    queryKey: ['acrl-assignments', year],
    queryFn: () => getAcrlSectionAssignments(year),
  });
  const usersQuery = useQuery({
    queryKey: ['acrl-approved-users', search.trim()],
    queryFn: () => searchAcrlApprovedUsers(search.trim()),
    enabled: search.trim().length >= 2,
  });
  const savedAssignment = assignmentsQuery.data?.sections.find((item) => item.section === section);
  const selectedIds = drafts[section] ?? savedAssignment?.assignees.map((user) => user.id) ?? [];
  const refreshAssignments = () => Promise.all([
    queryClient.invalidateQueries({ queryKey: ['acrl-assignments', year] }),
    queryClient.invalidateQueries({ queryKey: ['acrl-entry', String(year)] }),
  ]);
  const saveMutation = useMutation({
    mutationFn: ({ targetSection, userIds }: { targetSection: string; userIds: number[] }) => saveAcrlSectionAssignments(year, targetSection, userIds),
    onSuccess: async (result, variables) => {
      setDrafts((current) => ({ ...current, [variables.targetSection]: result.assignees.map((user) => user.id) }));
      setSearch('');
      await refreshAssignments();
    },
  });
  const copyMutation = useMutation({
    mutationFn: () => copyAcrlAssignmentsFromPrevious(year),
    onSuccess: async (result) => {
      setCopyResult(result);
      setDrafts({});
      await refreshAssignments();
    },
  });

  function toggleUser(userId: number, checked: boolean) {
    setDrafts((current) => ({
      ...current,
      [section]: checked ? [...selectedIds, userId] : selectedIds.filter((id) => id !== userId),
    }));
  }

  return (
    <section id="section-assignments" aria-label="Section assignments" className="space-y-4 rounded-xl border border-folio-200 bg-folio-50/50 p-4 sm:p-5">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h2 className="text-lg font-semibold text-gray-900">Section assignments</h2>
          <p className="text-sm text-gray-600">Assignments help organize the work. Every signed-in user can still edit any section.</p>
        </div>
        {hasPreviousYear && <button className="rounded border border-folio-700 bg-white px-3 py-2 text-sm font-semibold text-folio-800 hover:bg-folio-50 disabled:opacity-50" disabled={copyMutation.isPending} onClick={() => copyMutation.mutate()}>
          {copyMutation.isPending ? 'Copying…' : `Copy from FY ${year - 1}`}
        </button>}
      </div>
      {sections.length > 0 && <>
        <label className="block text-sm font-medium text-gray-700">Section
          <select className="mt-1 block w-full rounded border border-gray-300 bg-white px-3 py-2" value={section} onChange={(event) => setSection(event.target.value)}>
            {sections.map((item) => <option key={item.name} value={item.name}>{item.name}</option>)}
          </select>
        </label>
        <div className="space-y-2">
          <label className="block text-sm font-medium text-gray-700">Search approved users
            <input className="mt-1 block w-full rounded border border-gray-300 bg-white px-3 py-2" type="search" value={search} onChange={(event) => setSearch(event.target.value)} />
          </label>
          {search.trim().length < 2 && <p className="text-xs text-gray-500">Type at least two characters to search.</p>}
          {usersQuery.isFetching && <p role="status" className="text-sm text-gray-500">Searching approved users…</p>}
          {usersQuery.error && <p role="alert" className="text-sm text-rose-700">Could not search approved users.</p>}
          {usersQuery.data && <fieldset className="space-y-2 rounded border border-gray-200 bg-white p-3">
            <legend className="px-1 text-xs font-semibold uppercase tracking-wide text-gray-500">Approved users</legend>
            {usersQuery.data.users.length === 0 && <p className="text-sm text-gray-600">No approved users found.</p>}
            {usersQuery.data.users.map((user) => <label key={user.id} className="flex items-center gap-2 text-sm text-gray-800">
              <input type="checkbox" checked={selectedIds.includes(user.id)} onChange={(event) => toggleUser(user.id, event.target.checked)} />
              {user.displayName}
            </label>)}
          </fieldset>}
        </div>
        <div className="flex flex-wrap items-center gap-3">
          <button className="rounded bg-folio-700 px-4 py-2 text-sm font-semibold text-white hover:bg-folio-800 disabled:opacity-50" disabled={saveMutation.isPending || assignmentsQuery.isFetching || assignmentsQuery.isError || !savedAssignment} onClick={() => saveMutation.mutate({ targetSection: section, userIds: selectedIds })}>
            {saveMutation.isPending ? 'Saving…' : 'Save section assignments'}
          </button>
          <p className="text-sm text-gray-600">{savedAssignment?.assignees.length ? `Assigned: ${savedAssignment.assignees.map((user) => user.displayName).join(', ')}` : 'No users assigned to this section.'}</p>
        </div>
      </>}
      {assignmentsQuery.error && <p role="alert" className="text-sm text-rose-700">Could not load section assignments.</p>}
      {saveMutation.error && <p role="alert" className="text-sm text-rose-700">Could not save section assignments.</p>}
      {copyMutation.error && <p role="alert" className="text-sm text-rose-700">Could not copy the previous year assignments.</p>}
      {copyResult && <div role="status" className="space-y-1 rounded border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-900">
        <p>Copied assignments for: {copyResult.copiedSections.length ? copyResult.copiedSections.join(', ') : 'none'}.</p>
        <p>Already assigned, skipped: {copyResult.skippedSections.length ? copyResult.skippedSections.join(', ') : 'none'}.</p>
        <p>No matching section from FY{copyResult.sourceYear}: {copyResult.unmatchedSections.length ? copyResult.unmatchedSections.join(', ') : 'none'}.</p>
      </div>}
    </section>
  );
}
