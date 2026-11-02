import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import {
  deleteVisualizationHistory, fetchVisualizationHistory, saveVisualizationHistory,
  type VisualizationHistoryInput, type VisualizationHistoryRow,
} from '../api/visualizations';

export interface HistoryDraft {
  fiscalYearEnd: string;
  kind: VisualizationHistoryInput['kind'];
  totalAmount: string;
  electronicAmount: string;
  physicalAmount: string;
  note: string;
}

function fieldErrors(error: unknown): Record<string, string> {
  const errors = (error as { response?: { status?: number; data?: { errors?: Record<string, string | string[]> } } })?.response;
  if (errors?.status !== 422) return {};
  return Object.fromEntries(Object.entries(errors.data?.errors ?? {}).map(([key, value]) => [key, Array.isArray(value) ? value.join(' ') : value]));
}

export function useVisualizationHistory(acqUnitCode: string, currentYear: number | null) {
  const client = useQueryClient();
  const [draft, setDraft] = useState<HistoryDraft | null>(null);
  const [editing, setEditing] = useState(false);
  const history = useQuery({
    queryKey: ['visualization-history', acqUnitCode],
    queryFn: () => fetchVisualizationHistory(acqUnitCode),
  });
  const refresh = async () => {
    await Promise.all([
      client.invalidateQueries({ queryKey: ['visualization-history', acqUnitCode] }),
      client.invalidateQueries({ queryKey: ['visualizations'] }),
    ]);
  };
  const save = useMutation({
    mutationFn: (input: VisualizationHistoryInput) => saveVisualizationHistory(input),
    onSuccess: async () => {
      setDraft(null);
      await refresh();
    },
  });
  const remove = useMutation({ mutationFn: (id: number) => deleteVisualizationHistory(id), onSuccess: refresh });
  const open = (row?: VisualizationHistoryRow) => {
    save.reset();
    setEditing(Boolean(row));
    setDraft(row ? {
      fiscalYearEnd: String(row.fiscalYearEnd), kind: row.kind,
      totalAmount: String(row.totalAmount), electronicAmount: String(row.electronicAmount ?? ''),
      physicalAmount: String(row.physicalAmount ?? ''), note: row.note ?? '',
    } : {
      fiscalYearEnd: currentYear === null ? '' : String(currentYear), kind: 'purchases',
      totalAmount: '', electronicAmount: '', physicalAmount: '', note: '',
    });
  };
  const submit = () => {
    if (!draft) return;
    save.mutate({
      acqUnitCode, kind: draft.kind, fiscalYearEnd: Number(draft.fiscalYearEnd),
      totalAmount: Number(draft.totalAmount),
      electronicAmount: draft.kind === 'purchases' ? Number(draft.electronicAmount) : null,
      physicalAmount: draft.kind === 'purchases' ? Number(draft.physicalAmount) : null,
      note: draft.note || null,
    });
  };
  const confirmDelete = (row: VisualizationHistoryRow) => {
    if (window.confirm(`Delete ${row.fiscalYearEnd} ${row.kind} historical figure for ${acqUnitCode}?`)) remove.mutate(row.id);
  };
  const update = (field: keyof HistoryDraft, value: string) => setDraft((previous) => {
    if (!previous || (editing && (field === 'fiscalYearEnd' || field === 'kind'))) return previous;
    return { ...previous, [field]: value };
  });
  return { history, draft, editing, open, submit, save, remove, confirmDelete,
    errors: fieldErrors(save.error), close: () => setDraft(null),
    update,
  };
}
