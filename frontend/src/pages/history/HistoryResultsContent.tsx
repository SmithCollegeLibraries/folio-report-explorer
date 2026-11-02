import { forwardRef, useImperativeHandle, useState } from 'react';
import { Download } from 'lucide-react';
import { downloadExportCsv } from '../../api/client';
import { downloadCsv } from '../../utils/csv';
import type { HistoryItem, JobStatusResponse } from '../../types';

export interface HistoryResultsContentHandle { download: () => void; }

interface Props { item: HistoryItem; job: JobStatusResponse | null; loading: boolean; }

const HistoryResultsContent = forwardRef<HistoryResultsContentHandle, Props>(({ item, job, loading }, ref) => {
  const [unavailable, setUnavailable] = useState(false);
  const columns = Array.isArray(job?.columns) ? job.columns : [];
  const rows = Array.isArray(job?.rows) ? job.rows : [];
  const isFile = job?.outputMode === 'file' || !!job?.downloadUrl;
  const hasFilePreview = isFile && columns.length > 0 && rows.length > 0;
  const invalidRows = !!job && ((job.rows != null && !Array.isArray(job.rows))
    || (job.columns != null && !Array.isArray(job.columns))
    || (!isFile && (job.rowCount ?? 0) > 0 && (!Array.isArray(job.rows) || !Array.isArray(job.columns))));

  const download = async () => {
    if (isFile) {
      try { await downloadExportCsv(item.jobId); } catch (error: any) {
        if (error.response?.status === 404) setUnavailable(true);
      }
    } else if (Array.isArray(job?.columns) && Array.isArray(job?.rows)) {
      downloadCsv(job.columns, job.rows, item.name || 'query');
    } else setUnavailable(true);
  };

  useImperativeHandle(ref, () => ({ download: () => { void download(); } }));

  if (loading) return <div className="flex items-center justify-center h-48 gap-3 text-gray-400">Loading results…</div>;
  if (unavailable || invalidRows) return <div className="flex items-center justify-center h-48 text-gray-500 text-sm">Results are no longer available</div>;
  if (hasFilePreview) return <>
    <div className="px-4 py-3 border-b bg-blue-50 text-xs text-blue-800 flex items-center justify-between gap-3">
      <span>Preview shown below. Download CSV for the full exported result set.</span>
      <button onClick={() => { void download(); }} className="inline-flex items-center gap-1 px-2 py-1 rounded bg-blue-600 text-white"><Download size={12} /> Download full CSV</button>
    </div>
    <table className="w-full text-xs border-separate border-spacing-0"><thead className="bg-gray-50 sticky top-0 z-10"><tr>
      {columns.map((column) => <th key={column} className="text-left px-3 py-2 font-semibold text-gray-600 border-b whitespace-nowrap">{column}</th>)}
    </tr></thead><tbody>{rows.map((row, index) => <tr key={index} className={index % 2 ? 'bg-gray-50/50' : 'bg-white'}>
      {columns.map((column) => <td key={column} className="px-3 py-1.5 text-gray-700 whitespace-nowrap max-w-xs truncate border-b" title={row[column] == null ? '' : String(row[column])}>
        {row[column] == null ? <span className="text-gray-300 italic">null</span> : String(row[column])}</td>)}
    </tr>)}</tbody></table>
  </>;
  if (isFile) return <div className="flex h-48 items-center justify-center"><div className="text-center">
    <div className="text-sm text-gray-600 mb-3">This result is stored as a CSV export file. Download to view data.</div>
    <button onClick={() => { void download(); }} className="inline-flex items-center gap-1.5 px-3 py-1.5 text-sm bg-folio-600 text-white rounded"><Download size={14} /> Download CSV</button>
  </div></div>;
  if (columns.length && Array.isArray(job?.rows)) return <>
    <table className="w-full text-xs border-separate border-spacing-0"><thead className="bg-gray-50 sticky top-0 z-10"><tr>
      {columns.map((column) => <th key={column} className="text-left px-3 py-2 font-semibold text-gray-600 border-b whitespace-nowrap">{column}</th>)}
    </tr></thead><tbody>{rows.map((row, index) => <tr key={index} className={index % 2 ? 'bg-gray-50/50' : 'bg-white'}>
      {columns.map((column) => <td key={column} className="px-3 py-1.5 text-gray-700 whitespace-nowrap max-w-xs truncate border-b" title={row[column] == null ? '' : String(row[column])}>
        {row[column] == null ? <span className="text-gray-300 italic">null</span> : String(row[column])}</td>)}
    </tr>)}</tbody></table>
    {rows.length >= 200 && <div className="px-4 py-3 text-xs text-gray-400 text-center border-t bg-gray-50">Showing first {rows.length.toLocaleString()} rows — download CSV for full dataset</div>}
  </>;
  return <div className="flex items-center justify-center h-48 text-gray-400 text-sm">No result data available.</div>;
});

HistoryResultsContent.displayName = 'HistoryResultsContent';
export default HistoryResultsContent;
