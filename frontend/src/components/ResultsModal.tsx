import { useCallback } from 'react';
import { X } from 'lucide-react';
import ResultsTable from './ResultsTable';
import { useEscapeKey } from '../hooks/useEscapeKey';
import type { ExecuteResponse } from '../types';

interface Props {
  data: ExecuteResponse;
  onClose: () => void;
  title?: string;
}

export default function ResultsModal({ data, onClose, title }: Props) {
  useEscapeKey(useCallback(() => onClose(), [onClose]));

  return (
    <div
      className="fixed inset-0 bg-black/40 flex items-center justify-center z-50 p-4"
      onClick={(e) => {
        if (e.target === e.currentTarget) onClose();
      }}
    >
      <div className="bg-white rounded-xl shadow-2xl flex flex-col w-full max-w-7xl max-h-[90vh]">
        {/* Header */}
        <div className="flex items-center justify-between px-6 py-4 border-b flex-shrink-0">
          <div>
            <h2 className="text-lg font-semibold">
              {title || 'Query Results'}
            </h2>
            <p className="text-xs text-gray-500 mt-0.5">
              {data.rowCount} row{data.rowCount !== 1 ? 's' : ''} &middot;{' '}
              {data.columns.length} column{data.columns.length !== 1 ? 's' : ''} &middot;{' '}
              {data.executionTimeMs}ms
            </p>
          </div>
          <button
            onClick={onClose}
            className="p-2 hover:bg-gray-100 rounded-lg transition-colors"
            title="Close (Esc)"
          >
            <X size={20} className="text-gray-500" />
          </button>
        </div>

        {/* Results */}
        <div className="flex-1 overflow-auto p-4">
          <ResultsTable data={data} />
        </div>
      </div>
    </div>
  );
}
