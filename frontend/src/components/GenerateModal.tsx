import { useState } from 'react';
import { useMutation } from '@tanstack/react-query';
import {
  convertReportFromPhp,
  createReport,
  generateReportTemplate,
} from '../api/client';
import type { ReportCategory, ReportGenerateResponse } from '../types';
import SqlPreview from './SqlPreview';
import { Loader2, Plus, Send, Sparkles, X } from 'lucide-react';
import { REPORT_CATEGORIES } from '../utils/reports';

export default function GenerateModal({
  onClose,
  onCreated,
}: {
  onClose: () => void;
  onCreated: () => void;
}) {
  const [mode, setMode] = useState<'describe' | 'convert'>('describe');
  const [description, setDescription] = useState('');
  const [phpCode, setPhpCode] = useState('');
  const [preview, setPreview] = useState<ReportGenerateResponse | null>(null);

  const generateMut = useMutation({
    mutationFn: (text: string) => generateReportTemplate(text),
    onSuccess: (data) => setPreview(data),
  });

  const convertMut = useMutation({
    mutationFn: (code: string) => convertReportFromPhp(code),
    onSuccess: (data) => setPreview(data),
  });

  const saveMut = useMutation({
    mutationFn: (template: ReportGenerateResponse) => createReport(template),
    onSuccess: () => onCreated(),
  });

  return (
    <div className="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/40 p-4">
      <div className="my-12 w-full max-w-2xl rounded-xl bg-white shadow-2xl">
        <div className="flex items-center gap-3 border-b px-6 py-4">
          <Sparkles size={20} className="text-purple-600" />
          <h3 className="text-lg font-semibold">Create Report with AI</h3>
          <button onClick={onClose} className="ml-auto text-gray-400 hover:text-gray-600">
            <X size={20} />
          </button>
        </div>

        <div className="space-y-4 px-6 py-4">
          {!preview && (
            <div className="flex border-b">
              <button
                onClick={() => setMode('describe')}
                className={`border-b-2 px-4 py-2 text-sm font-medium transition-colors ${
                  mode === 'describe'
                    ? 'border-purple-600 text-purple-600'
                    : 'border-transparent text-gray-500 hover:text-gray-700'
                }`}
              >
                Describe
              </button>
              <button
                onClick={() => setMode('convert')}
                className={`border-b-2 px-4 py-2 text-sm font-medium transition-colors ${
                  mode === 'convert'
                    ? 'border-purple-600 text-purple-600'
                    : 'border-transparent text-gray-500 hover:text-gray-700'
                }`}
              >
                Convert from PHP
              </button>
            </div>
          )}

          {!preview && mode === 'describe' && (
            <>
              <p className="text-sm text-gray-500">
                Describe the report you need and AI will generate a parameterized template with filters you can customize.
              </p>
              <textarea
                value={description}
                onChange={(event) => setDescription(event.target.value)}
                placeholder="e.g., Show all overdue loans grouped by patron group with borrower name, item title, due date, and days overdue. Let me filter by date range and location."
                className="h-28 w-full resize-none rounded-lg border px-4 py-3 text-sm outline-none focus:border-purple-500 focus:ring-2 focus:ring-purple-300"
              />
              <div className="flex justify-end gap-2">
                <button
                  onClick={onClose}
                  className="rounded-lg border px-4 py-2 text-sm hover:bg-gray-50"
                >
                  Cancel
                </button>
                <button
                  onClick={() => generateMut.mutate(description)}
                  disabled={!description.trim() || generateMut.isPending}
                  className="flex items-center gap-2 rounded-lg bg-purple-600 px-4 py-2 text-sm text-white transition-colors hover:bg-purple-700 disabled:opacity-50"
                >
                  {generateMut.isPending ? (
                    <Loader2 size={14} className="animate-spin" />
                  ) : (
                    <Send size={14} />
                  )}
                  {generateMut.isPending ? 'Generating...' : 'Generate'}
                </button>
              </div>
              {generateMut.isError && (
                <div className="rounded border border-red-200 bg-red-50 p-3 text-sm text-red-700">
                  {String(generateMut.error)}
                </div>
              )}
            </>
          )}

          {!preview && mode === 'convert' && (
            <>
              <p className="text-sm text-gray-500">
                Paste a Yii2 report model and AI will convert it into a parameterized report template.
              </p>
              <textarea
                value={phpCode}
                onChange={(event) => setPhpCode(event.target.value)}
                placeholder="Paste your Yii2 PHP report model code here..."
                className="h-48 w-full resize-none rounded-lg border px-4 py-3 font-mono text-xs outline-none focus:border-purple-500 focus:ring-2 focus:ring-purple-300"
              />
              <div className="flex justify-end gap-2">
                <button
                  onClick={onClose}
                  className="rounded-lg border px-4 py-2 text-sm hover:bg-gray-50"
                >
                  Cancel
                </button>
                <button
                  onClick={() => convertMut.mutate(phpCode)}
                  disabled={!phpCode.trim() || convertMut.isPending}
                  className="flex items-center gap-2 rounded-lg bg-purple-600 px-4 py-2 text-sm text-white transition-colors hover:bg-purple-700 disabled:opacity-50"
                >
                  {convertMut.isPending ? (
                    <Loader2 size={14} className="animate-spin" />
                  ) : (
                    <Send size={14} />
                  )}
                  {convertMut.isPending ? 'Converting...' : 'Convert'}
                </button>
              </div>
              {convertMut.isError && (
                <div className="rounded border border-red-200 bg-red-50 p-3 text-sm text-red-700">
                  {String(convertMut.error)}
                </div>
              )}
            </>
          )}

          {preview && (
            <div className="space-y-4">
              <div>
                <label className="mb-1 block text-xs font-medium text-gray-600">Name</label>
                <input
                  value={preview.name}
                  onChange={(event) => setPreview({ ...preview, name: event.target.value })}
                  className="w-full rounded border px-3 py-2 text-sm"
                />
              </div>
              <div>
                <label className="mb-1 block text-xs font-medium text-gray-600">Description</label>
                <textarea
                  value={preview.description}
                  onChange={(event) => setPreview({ ...preview, description: event.target.value })}
                  className="h-16 w-full resize-none rounded border px-3 py-2 text-sm"
                />
              </div>
              <div className="grid grid-cols-2 gap-3">
                <div>
                  <label className="mb-1 block text-xs font-medium text-gray-600">Category</label>
                  <select
                    value={preview.category}
                    onChange={(event) =>
                      setPreview({
                        ...preview,
                        category: event.target.value as ReportCategory,
                      })
                    }
                    className="w-full rounded border bg-white px-3 py-2 text-sm"
                  >
                    {REPORT_CATEGORIES.map((category) => (
                      <option key={category.key} value={category.key}>
                        {category.label}
                      </option>
                    ))}
                  </select>
                </div>
                <div>
                  <label className="mb-1 block text-xs font-medium text-gray-600">Row Limit</label>
                  <input
                    type="number"
                    value={preview.defaultLimit}
                    onChange={(event) =>
                      setPreview({
                        ...preview,
                        defaultLimit: Number(event.target.value),
                      })
                    }
                    className="w-full rounded border px-3 py-2 text-sm"
                  />
                </div>
              </div>

              <div>
                <label className="mb-1 block text-xs font-medium text-gray-600">
                  Parameters ({preview.parameters.length})
                </label>
                <div className="space-y-2 rounded border bg-gray-50 p-3">
                  {preview.parameters.map((parameter, index) => (
                    <div key={index} className="flex items-center gap-3 text-xs">
                      <span className="font-mono text-folio-600">:{parameter.name}</span>
                      <span className="rounded border bg-white px-2 py-0.5">{parameter.type}</span>
                      <span className="text-gray-500">{parameter.label}</span>
                      {parameter.required && <span className="text-red-400">required</span>}
                    </div>
                  ))}
                </div>
              </div>

              <div>
                <label className="mb-1 block text-xs font-medium text-gray-600">Generated SQL</label>
                <SqlPreview sql={preview.sqlTemplate} height="200px" />
              </div>

              <div className="flex justify-end gap-2 border-t pt-2">
                <button
                  onClick={() => setPreview(null)}
                  className="rounded-lg border px-4 py-2 text-sm hover:bg-gray-50"
                >
                  Back
                </button>
                <button
                  onClick={() => saveMut.mutate(preview)}
                  disabled={saveMut.isPending}
                  className="flex items-center gap-2 rounded-lg bg-folio-600 px-4 py-2 text-sm text-white transition-colors hover:bg-folio-700 disabled:opacity-50"
                >
                  {saveMut.isPending ? (
                    <Loader2 size={14} className="animate-spin" />
                  ) : (
                    <Plus size={14} />
                  )}
                  {saveMut.isPending ? 'Saving...' : 'Save Report'}
                </button>
              </div>
              {saveMut.isError && (
                <div className="rounded border border-red-200 bg-red-50 p-3 text-sm text-red-700">
                  {String(saveMut.error)}
                </div>
              )}
            </div>
          )}
        </div>
      </div>
    </div>
  );
}