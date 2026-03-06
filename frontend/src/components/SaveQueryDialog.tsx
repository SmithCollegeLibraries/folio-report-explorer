import { useState, useEffect, useRef, useCallback } from 'react';
import { Bookmark, LayoutDashboard, X, Loader2, CheckCircle2 } from 'lucide-react';
import { saveQuery } from '../api/client';
import { useEscapeKey } from '../hooks/useEscapeKey';
import type { HistoryItem, SavedQuery } from '../types';

export interface SaveQueryDialogProps {
  item: HistoryItem;
  initialPinned: boolean;
  onClose: () => void;
  onSaved: (sq: SavedQuery, pinned: boolean) => void;
}

export default function SaveQueryDialog({
  item, initialPinned, onClose, onSaved,
}: SaveQueryDialogProps) {
  const [name, setName] = useState(item.name || '');
  const [description, setDescription] = useState('');
  const [isPinned, setIsPinned] = useState(initialPinned);
  const [saving, setSaving] = useState(false);
  const [savedOk, setSavedOk] = useState(false);
  const [err, setErr] = useState<string | null>(null);
  const nameRef = useRef<HTMLInputElement>(null);

  useEffect(() => { nameRef.current?.focus(); }, []);
  useEscapeKey(useCallback(() => onClose(), [onClose]));

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!name.trim()) { setErr('Name is required'); return; }
    setSaving(true);
    setErr(null);
    try {
      const source = (item.source === 'nl' || item.source === 'builder') ? item.source : 'builder';
      const sq = await saveQuery({
        name: name.trim(),
        description: description.trim() || undefined,
        generatedSql: item.sql,
        queryDefinition: { sql: item.sql },
        source,
        isPinned,
      });
      setSavedOk(true);
      setTimeout(() => { onSaved(sq, isPinned); onClose(); }, 900);
    } catch (e: any) {
      setErr(e.response?.data?.error || 'Failed to save');
    } finally {
      setSaving(false);
    }
  };

  return (
    <div
      className="fixed inset-0 z-[60] bg-black/40 flex items-center justify-center p-4"
      onClick={(e) => { if (e.target === e.currentTarget) onClose(); }}
    >
      <div className="bg-white rounded-xl shadow-2xl w-full max-w-md">
        <div className="px-6 py-4 border-b flex items-center justify-between">
          <h2 className="font-semibold text-gray-800 flex items-center gap-2">
            {isPinned
              ? <><LayoutDashboard size={16} className="text-folio-600" /> Add to Dashboard</>
              : <><Bookmark size={16} className="text-folio-600" /> Save to Library</>}
          </h2>
          <button onClick={onClose} className="p-1 text-gray-400 hover:text-gray-600 rounded"><X size={16} /></button>
        </div>
        <form onSubmit={handleSubmit} className="px-6 py-5 space-y-4">
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">
              Name <span className="text-red-500">*</span>
            </label>
            <input
              ref={nameRef}
              value={name}
              onChange={(e) => setName(e.target.value)}
              placeholder="My recurring query…"
              className="w-full px-3 py-2 text-sm border rounded-lg focus:ring-2 focus:ring-folio-300 focus:border-folio-500 outline-none"
            />
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-700 mb-1">
              Description <span className="text-xs text-gray-400 ml-1">optional</span>
            </label>
            <textarea
              value={description}
              onChange={(e) => setDescription(e.target.value)}
              rows={2}
              placeholder="What does this query do?"
              className="w-full px-3 py-2 text-sm border rounded-lg focus:ring-2 focus:ring-folio-300 focus:border-folio-500 outline-none resize-none"
            />
          </div>
          <label className="flex items-center gap-2.5 cursor-pointer select-none">
            <input
              type="checkbox"
              checked={isPinned}
              onChange={(e) => setIsPinned(e.target.checked)}
              className="w-4 h-4 rounded text-folio-600 focus:ring-folio-400"
            />
            <span className="text-sm text-gray-700 flex items-center gap-1.5">
              <LayoutDashboard size={14} className="text-folio-500" /> Pin to Dashboard
            </span>
          </label>
          {err && <p className="text-red-600 text-xs">{err}</p>}
          <div className="flex items-center justify-end gap-2 pt-1">
            <button type="button" onClick={onClose} className="px-4 py-2 text-sm border rounded-lg hover:bg-gray-50">
              Cancel
            </button>
            <button
              type="submit"
              disabled={saving || savedOk}
              className="px-4 py-2 text-sm bg-folio-600 text-white rounded-lg hover:bg-folio-700 disabled:opacity-60 flex items-center gap-1.5 transition-colors"
            >
              {savedOk
                ? <><CheckCircle2 size={14} className="text-green-300" /> Saved!</>
                : saving ? <><Loader2 size={14} className="animate-spin" /> Saving…</> : 'Save'}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}
