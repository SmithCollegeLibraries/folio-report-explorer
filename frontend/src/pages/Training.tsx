import { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import {
  listTrainingHints,
  createTrainingHint,
  updateTrainingHint,
  deleteTrainingHint,
} from '../api/client';
import type { TrainingHint, TrainingHintType } from '../types';
import {
  Brain, Plus, Pencil, Trash2, Save, X, ToggleLeft,
  ToggleRight, Search, BookOpen, MessageSquare, FileCode2,
  AlertCircle, Check, ChevronDown, ChevronRight,
} from 'lucide-react';

type TabKey = 'table_description' | 'vocabulary' | 'examples';

const TABS: { key: TabKey; label: string; icon: typeof BookOpen }[] = [
  { key: 'table_description', label: 'Table Descriptions', icon: BookOpen },
  { key: 'vocabulary', label: 'Vocabulary', icon: MessageSquare },
  { key: 'examples', label: 'Examples & Corrections', icon: FileCode2 },
];

export default function Training() {
  const [activeTab, setActiveTab] = useState<TabKey>('table_description');
  const [search, setSearch] = useState('');
  const [editingId, setEditingId] = useState<number | null>(null);
  const [addingType, setAddingType] = useState<TrainingHintType | null>(null);
  const [toast, setToast] = useState<string | null>(null);

  const queryClient = useQueryClient();

  const { data: hints = [], isLoading, error } = useQuery({
    queryKey: ['training-hints'],
    queryFn: () => listTrainingHints(),
  });

  const showToast = (msg: string) => {
    setToast(msg);
    setTimeout(() => setToast(null), 3000);
  };

  const createMut = useMutation({
    mutationFn: createTrainingHint,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['training-hints'] });
      setAddingType(null);
      showToast('Hint created');
    },
  });

  const updateMut = useMutation({
    mutationFn: ({ id, ...data }: { id: number } & Record<string, unknown>) =>
      updateTrainingHint(id, data as any),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['training-hints'] });
      setEditingId(null);
      showToast('Hint updated');
    },
  });

  const deleteMut = useMutation({
    mutationFn: deleteTrainingHint,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['training-hints'] });
      showToast('Hint deleted');
    },
  });

  const toggleMut = useMutation({
    mutationFn: (hint: TrainingHint) =>
      updateTrainingHint(hint.id, { isActive: hint.is_active ? 0 : 1 }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['training-hints'] });
    },
  });

  // Filter hints by tab
  const filteredHints = hints.filter((h) => {
    if (activeTab === 'examples') {
      return h.type === 'example' || h.type === 'correction';
    }
    return h.type === activeTab;
  });

  // Apply search
  const searchLower = search.toLowerCase();
  const displayHints = search
    ? filteredHints.filter(
        (h) =>
          h.hint_key?.toLowerCase().includes(searchLower) ||
          h.hint_value?.toLowerCase().includes(searchLower) ||
          h.example_question?.toLowerCase().includes(searchLower) ||
          h.notes?.toLowerCase().includes(searchLower),
      )
    : filteredHints;

  // Stats
  const stats = {
    table_description: hints.filter((h) => h.type === 'table_description').length,
    vocabulary: hints.filter((h) => h.type === 'vocabulary').length,
    example: hints.filter((h) => h.type === 'example' || h.type === 'correction').length,
    active: hints.filter((h) => h.is_active).length,
    total: hints.length,
  };

  return (
    <div className="flex flex-col h-[calc(100vh-56px)]">
      {/* Header */}
      <div className="p-6 bg-white border-b">
        <div className="max-w-6xl mx-auto">
          <div className="flex items-center gap-2 mb-2">
            <Brain size={20} className="text-folio-600" />
            <h2 className="text-lg font-semibold">AI Training</h2>
          </div>
          <p className="text-sm text-gray-500 mb-4">
            Manage the knowledge base that powers AI query generation. Edit table descriptions,
            vocabulary mappings, and few-shot examples. Corrections from Ask AI appear here automatically.
          </p>

          {/* Stats */}
          <div className="flex gap-4 text-xs text-gray-500">
            <span>{stats.table_description} descriptions</span>
            <span>•</span>
            <span>{stats.vocabulary} vocabulary terms</span>
            <span>•</span>
            <span>{stats.example} examples</span>
            <span>•</span>
            <span className="text-green-600">{stats.active} active</span>
            <span className="text-gray-400">/ {stats.total} total</span>
          </div>
        </div>
      </div>

      {/* Toast */}
      {toast && (
        <div className="max-w-6xl mx-auto mt-2 px-6">
          <div className="flex items-center gap-2 bg-green-50 border border-green-200 rounded-lg px-4 py-2 text-sm text-green-700">
            <Check size={14} /> {toast}
          </div>
        </div>
      )}

      {/* Body */}
      <div className="flex-1 overflow-hidden flex flex-col max-w-6xl mx-auto w-full px-6 py-4">
        {/* Tabs + Search */}
        <div className="flex items-center justify-between mb-4">
          <div className="flex gap-1">
            {TABS.map(({ key, label, icon: Icon }) => (
              <button
                key={key}
                onClick={() => { setActiveTab(key); setSearch(''); setEditingId(null); setAddingType(null); }}
                className={`flex items-center gap-1.5 px-4 py-2 rounded-lg text-sm font-medium transition-colors ${
                  activeTab === key
                    ? 'bg-folio-600 text-white'
                    : 'text-gray-600 hover:bg-gray-100'
                }`}
              >
                <Icon size={14} />
                {label}
              </button>
            ))}
          </div>
          <div className="flex gap-2">
            <div className="relative">
              <Search size={14} className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" />
              <input
                value={search}
                onChange={(e) => setSearch(e.target.value)}
                placeholder="Search…"
                className="pl-8 pr-3 py-1.5 text-sm border rounded-lg w-56 focus:ring-2 focus:ring-folio-300 outline-none"
              />
            </div>
            <button
              onClick={() => {
                if (activeTab === 'examples') setAddingType('example');
                else setAddingType(activeTab as TrainingHintType);
              }}
              className="flex items-center gap-1 bg-folio-600 text-white text-sm px-3 py-1.5 rounded-lg hover:bg-folio-700"
            >
              <Plus size={14} /> Add
            </button>
          </div>
        </div>

        {/* Loading/Error */}
        {isLoading && (
          <div className="text-sm text-gray-400 text-center py-16">Loading training data…</div>
        )}
        {error && (
          <div className="flex items-center gap-2 text-sm text-red-600 bg-red-50 border border-red-200 rounded-lg p-3 mb-4">
            <AlertCircle size={14} /> Failed to load: {String(error)}
          </div>
        )}

        {/* Add form */}
        {addingType && (
          <AddForm
            type={addingType}
            onSave={(data) => createMut.mutate(data)}
            onCancel={() => setAddingType(null)}
            isPending={createMut.isPending}
          />
        )}

        {/* Hint list */}
        <div className="flex-1 overflow-y-auto space-y-2">
          {displayHints.length === 0 && !isLoading && (
            <div className="text-sm text-gray-400 text-center py-16">
              {search ? 'No matching hints found.' : 'No hints in this category yet.'}
            </div>
          )}

          {displayHints.map((hint) => (
            <HintRow
              key={hint.id}
              hint={hint}
              isEditing={editingId === hint.id}
              onEdit={() => setEditingId(hint.id)}
              onCancelEdit={() => setEditingId(null)}
              onSave={(data) => updateMut.mutate({ id: hint.id, ...data })}
              onDelete={() => {
                if (confirm('Delete this training hint?')) deleteMut.mutate(hint.id);
              }}
              onToggle={() => toggleMut.mutate(hint)}
              isSaving={updateMut.isPending}
            />
          ))}
        </div>
      </div>
    </div>
  );
}

// ─── Add Form Component ──────────────────────────────────────────

function AddForm({
  type,
  onSave,
  onCancel,
  isPending,
}: {
  type: TrainingHintType;
  onSave: (data: any) => void;
  onCancel: () => void;
  isPending: boolean;
}) {
  const [key, setKey] = useState('');
  const [value, setValue] = useState('');
  const [question, setQuestion] = useState('');
  const [sql, setSql] = useState('');

  const handleSubmit = () => {
    if (type === 'table_description' || type === 'vocabulary') {
      if (!key || !value) return;
      onSave({ type, hintKey: key, hintValue: value });
    } else {
      if (!question || !sql) return;
      onSave({ type, exampleQuestion: question, exampleSql: sql });
    }
  };

  return (
    <div className="border border-folio-200 bg-folio-50 rounded-lg p-4 mb-4">
      <h4 className="text-sm font-semibold mb-3">
        Add {type === 'table_description' ? 'Table Description' : type === 'vocabulary' ? 'Vocabulary Term' : 'Example Query'}
      </h4>

      {(type === 'table_description' || type === 'vocabulary') ? (
        <div className="space-y-2">
          <input
            value={key}
            onChange={(e) => setKey(e.target.value)}
            placeholder={type === 'table_description' ? 'Table name (e.g. inventory.item__t)' : 'Business term (e.g. borrower)'}
            className="w-full border rounded px-3 py-2 text-sm"
            autoFocus
          />
          <textarea
            value={value}
            onChange={(e) => setValue(e.target.value)}
            placeholder={type === 'table_description' ? 'Description of what this table contains…' : 'Mapping to table/column (e.g. users.users__t — same as patron)'}
            className="w-full border rounded px-3 py-2 text-sm h-20 resize-none"
          />
        </div>
      ) : (
        <div className="space-y-2">
          <input
            value={question}
            onChange={(e) => setQuestion(e.target.value)}
            placeholder="Natural language question…"
            className="w-full border rounded px-3 py-2 text-sm"
            autoFocus
          />
          <textarea
            value={sql}
            onChange={(e) => setSql(e.target.value)}
            placeholder="Correct SQL query…"
            className="w-full border rounded px-3 py-2 text-sm h-32 resize-none font-mono text-xs"
          />
        </div>
      )}

      <div className="flex justify-end gap-2 mt-3">
        <button onClick={onCancel} className="px-3 py-1.5 text-sm border rounded hover:bg-gray-50">
          Cancel
        </button>
        <button
          onClick={handleSubmit}
          disabled={isPending}
          className="flex items-center gap-1 px-3 py-1.5 text-sm bg-folio-600 text-white rounded hover:bg-folio-700 disabled:opacity-50"
        >
          <Save size={12} /> {isPending ? 'Saving…' : 'Save'}
        </button>
      </div>
    </div>
  );
}

// ─── Hint Row Component ──────────────────────────────────────────

function HintRow({
  hint,
  isEditing,
  onEdit,
  onCancelEdit,
  onSave,
  onDelete,
  onToggle,
  isSaving,
}: {
  hint: TrainingHint;
  isEditing: boolean;
  onEdit: () => void;
  onCancelEdit: () => void;
  onSave: (data: Record<string, unknown>) => void;
  onDelete: () => void;
  onToggle: () => void;
  isSaving: boolean;
}) {
  const [editKey, setEditKey] = useState(hint.hint_key ?? '');
  const [editValue, setEditValue] = useState(hint.hint_value ?? '');
  const [editQuestion, setEditQuestion] = useState(hint.example_question ?? '');
  const [editSql, setEditSql] = useState(hint.example_sql ?? '');
  const [editNotes, setEditNotes] = useState(hint.notes ?? '');
  const [expanded, setExpanded] = useState(false);

  const isDesc = hint.type === 'table_description';
  const isVocab = hint.type === 'vocabulary';
  const isCorrection = hint.type === 'correction';

  const handleSave = () => {
    if (isDesc || isVocab) {
      onSave({ hintKey: editKey, hintValue: editValue });
    } else {
      onSave({ exampleQuestion: editQuestion, exampleSql: editSql, notes: editNotes });
    }
  };

  if (isEditing) {
    return (
      <div className="border border-blue-200 bg-blue-50 rounded-lg p-4">
        {(isDesc || isVocab) ? (
          <div className="space-y-2">
            <input
              value={editKey}
              onChange={(e) => setEditKey(e.target.value)}
              className="w-full border rounded px-3 py-2 text-sm"
              placeholder={isDesc ? 'Table name' : 'Term'}
            />
            <textarea
              value={editValue}
              onChange={(e) => setEditValue(e.target.value)}
              className="w-full border rounded px-3 py-2 text-sm h-20 resize-none"
              placeholder={isDesc ? 'Description' : 'Mapping'}
            />
          </div>
        ) : (
          <div className="space-y-2">
            <input
              value={editQuestion}
              onChange={(e) => setEditQuestion(e.target.value)}
              className="w-full border rounded px-3 py-2 text-sm"
              placeholder="Question"
            />
            <textarea
              value={editSql}
              onChange={(e) => setEditSql(e.target.value)}
              className="w-full border rounded px-3 py-2 text-sm h-32 resize-none font-mono text-xs"
              placeholder="SQL"
            />
            <input
              value={editNotes}
              onChange={(e) => setEditNotes(e.target.value)}
              className="w-full border rounded px-3 py-2 text-sm"
              placeholder="Notes (optional)"
            />
          </div>
        )}
        <div className="flex justify-end gap-2 mt-3">
          <button onClick={onCancelEdit} className="px-3 py-1.5 text-sm border rounded hover:bg-gray-50">
            <X size={12} />
          </button>
          <button
            onClick={handleSave}
            disabled={isSaving}
            className="flex items-center gap-1 px-3 py-1.5 text-sm bg-blue-600 text-white rounded hover:bg-blue-700 disabled:opacity-50"
          >
            <Save size={12} /> {isSaving ? 'Saving…' : 'Save'}
          </button>
        </div>
      </div>
    );
  }

  return (
    <div
      className={`border rounded-lg p-3 transition-colors ${
        hint.is_active ? 'bg-white border-gray-200' : 'bg-gray-50 border-gray-100 opacity-60'
      }`}
    >
      <div className="flex items-start justify-between gap-4">
        {/* Content */}
        <div className="flex-1 min-w-0">
          {(isDesc || isVocab) ? (
            <>
              <div className="text-sm font-medium text-gray-800 font-mono">{hint.hint_key}</div>
              <div className="text-sm text-gray-600 mt-0.5 line-clamp-2">{hint.hint_value}</div>
            </>
          ) : (
            <>
              <div className="flex items-center gap-2">
                {isCorrection && (
                  <span className="text-xs bg-amber-100 text-amber-700 px-2 py-0.5 rounded-full font-medium">
                    Correction
                  </span>
                )}
                <div className="text-sm text-gray-800">{hint.example_question}</div>
              </div>

              {/* Expandable SQL */}
              <button
                onClick={() => setExpanded(!expanded)}
                className="flex items-center gap-1 text-xs text-gray-400 hover:text-gray-600 mt-1"
              >
                {expanded ? <ChevronDown size={12} /> : <ChevronRight size={12} />}
                {expanded ? 'Hide SQL' : 'Show SQL'}
              </button>
              {expanded && (
                <div className="mt-2 space-y-2">
                  <pre className="text-xs font-mono bg-gray-50 border rounded p-2 overflow-x-auto whitespace-pre-wrap text-gray-700">
                    {hint.example_sql}
                  </pre>
                  {isCorrection && hint.original_sql && (
                    <div>
                      <div className="text-xs text-red-500 font-medium mb-1">Original (incorrect) SQL:</div>
                      <pre className="text-xs font-mono bg-red-50 border border-red-200 rounded p-2 overflow-x-auto whitespace-pre-wrap text-red-700">
                        {hint.original_sql}
                      </pre>
                    </div>
                  )}
                  {hint.notes && (
                    <div className="text-xs text-gray-500 italic">Note: {hint.notes}</div>
                  )}
                </div>
              )}
            </>
          )}
        </div>

        {/* Actions */}
        <div className="flex items-center gap-1 shrink-0">
          <button
            onClick={onToggle}
            title={hint.is_active ? 'Deactivate' : 'Activate'}
            className={`p-1.5 rounded hover:bg-gray-100 ${
              hint.is_active ? 'text-green-600' : 'text-gray-400'
            }`}
          >
            {hint.is_active ? <ToggleRight size={16} /> : <ToggleLeft size={16} />}
          </button>
          <button
            onClick={onEdit}
            className="p-1.5 rounded hover:bg-gray-100 text-gray-400 hover:text-blue-600"
            title="Edit"
          >
            <Pencil size={14} />
          </button>
          <button
            onClick={onDelete}
            className="p-1.5 rounded hover:bg-gray-100 text-gray-400 hover:text-red-600"
            title="Delete"
          >
            <Trash2 size={14} />
          </button>
        </div>
      </div>
    </div>
  );
}
