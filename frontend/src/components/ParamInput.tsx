import type { ReportParam } from '../types';

export default function ParamInput({
  param,
  value,
  options,
  onChange,
}: {
  param: ReportParam;
  value: string;
  options?: { value: string; label: string }[];
  onChange: (value: string) => void;
}) {
  return (
    <div>
      <label className="mb-1 block text-xs font-medium text-gray-600">
        {param.label}
        {param.required && <span className="ml-0.5 text-red-400">*</span>}
      </label>

      {param.type === 'select' && options ? (
        <select
          value={value}
          onChange={(event) => onChange(event.target.value)}
          className="w-full rounded-lg border border-stone-300 bg-white px-3 py-2 text-sm outline-none transition focus:border-folio-500 focus:ring-2 focus:ring-folio-200"
        >
          <option value="">-- Any --</option>
          {options.map((option) => (
            <option key={option.value} value={option.value}>
              {option.label}
            </option>
          ))}
        </select>
      ) : param.type === 'boolean' ? (
        <select
          value={value}
          onChange={(event) => onChange(event.target.value)}
          className="w-full rounded-lg border border-stone-300 bg-white px-3 py-2 text-sm outline-none transition focus:border-folio-500 focus:ring-2 focus:ring-folio-200"
        >
          <option value="">-- Any --</option>
          <option value="true">Yes</option>
          <option value="false">No</option>
        </select>
      ) : param.type === 'list' ? (
        <textarea
          value={value}
          onChange={(event) => onChange(event.target.value)}
          placeholder={param.placeholder || 'One value per line'}
          className="h-24 w-full resize-none rounded-lg border border-stone-300 px-3 py-2 font-mono text-sm outline-none transition focus:border-folio-500 focus:ring-2 focus:ring-folio-200"
        />
      ) : (
        <input
          type={param.type === 'date' ? 'date' : param.type === 'number' ? 'number' : 'text'}
          value={value}
          onChange={(event) => onChange(event.target.value)}
          placeholder={param.placeholder}
          className="w-full rounded-lg border border-stone-300 px-3 py-2 text-sm outline-none transition focus:border-folio-500 focus:ring-2 focus:ring-folio-200"
        />
      )}

      {param.description && <p className="mt-1 text-xs text-gray-400">{param.description}</p>}
    </div>
  );
}