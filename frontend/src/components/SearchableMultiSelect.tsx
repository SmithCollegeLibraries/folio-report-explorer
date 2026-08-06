import { ChevronDown, Search, X } from 'lucide-react';
import { useMemo, useState } from 'react';

interface SelectOption {
  value: string;
  label: string;
}

interface SearchableMultiSelectProps {
  id: string;
  label: string;
  value: string;
  options: SelectOption[];
  placeholder?: string;
  maxSelections?: number;
  onChange: (value: string) => void;
}

function selectionCountLabel(count: number, singularLabel: string): string {
  if (count === 0) return `No ${singularLabel}s selected`;
  return `${count} ${singularLabel}${count === 1 ? '' : 's'} selected`;
}

export default function SearchableMultiSelect({
  id,
  label,
  value,
  options,
  placeholder = 'Search options',
  maxSelections = 100,
  onChange,
}: SearchableMultiSelectProps) {
  const [isOpen, setIsOpen] = useState(false);
  const [search, setSearch] = useState('');
  const selectedValues = useMemo(
    () => value.split(',').map((item) => item.trim()).filter(Boolean),
    [value],
  );
  const selectedSet = useMemo(() => new Set(selectedValues), [selectedValues]);
  const optionByValue = useMemo(
    () => new Map(options.map((option) => [option.value, option])),
    [options],
  );
  const filteredOptions = useMemo(() => {
    const needle = search.trim().toLocaleLowerCase();
    if (!needle) return options;
    return options.filter((option) => option.label.toLocaleLowerCase().includes(needle));
  }, [options, search]);
  const atLimit = selectedValues.length >= maxSelections;
  const listboxId = `${id}-options`;
  const singularLabel = label.replace(/s$/i, '').toLocaleLowerCase();

  const emit = (next: string[]) => onChange(next.join(','));
  const toggleOption = (optionValue: string) => {
    if (selectedSet.has(optionValue)) {
      emit(selectedValues.filter((selectedValue) => selectedValue !== optionValue));
      return;
    }
    if (!atLimit) emit([...selectedValues, optionValue]);
  };

  const countLabel = selectionCountLabel(selectedValues.length, singularLabel);

  return (
    <div className="relative" onKeyDown={(event) => {
      if (event.key === 'Escape') setIsOpen(false);
    }}>
      <button
        id={id}
        type="button"
        aria-label={selectedValues.length === 0 ? 'Select locations' : countLabel}
        aria-haspopup="listbox"
        aria-expanded={isOpen}
        aria-controls={listboxId}
        onClick={() => setIsOpen((current) => !current)}
        className="flex w-full items-center justify-between rounded-lg border border-stone-300 bg-white px-3 py-2 text-left text-sm outline-none transition focus:border-folio-500 focus:ring-2 focus:ring-folio-200"
      >
        <span className={selectedValues.length === 0 ? 'text-stone-500' : 'text-gray-800'}>
          {selectedValues.length === 0 ? 'Select locations' : countLabel}
        </span>
        <ChevronDown size={16} className={`text-stone-400 transition ${isOpen ? 'rotate-180' : ''}`} />
      </button>

      {selectedValues.length > 0 && (
        <div className="mt-2 flex flex-wrap gap-1.5">
          {selectedValues.map((selectedValue) => {
            const option = optionByValue.get(selectedValue);
            if (!option) return null;
            return (
              <span
                key={selectedValue}
                className="inline-flex max-w-full items-center gap-1 rounded-full bg-folio-50 px-2.5 py-1 text-xs font-medium text-folio-800"
              >
                <span className="truncate">{option.label}</span>
                <button
                  type="button"
                  aria-label={`Remove ${option.label}`}
                  onClick={() => toggleOption(selectedValue)}
                  className="shrink-0 rounded-full p-0.5 text-folio-600 hover:bg-folio-100 hover:text-folio-900"
                >
                  <X size={12} />
                </button>
              </span>
            );
          })}
        </div>
      )}

      <div className="mt-1 flex items-center justify-between gap-2 text-xs text-stone-500">
        <span>{countLabel}</span>
        {selectedValues.length > 0 && (
          <button
            type="button"
            aria-label={`Clear all ${label.toLocaleLowerCase()}`}
            onClick={() => emit([])}
            className="font-medium text-folio-700 hover:text-folio-900"
          >
            Clear all
          </button>
        )}
      </div>

      {isOpen && (
        <div className="absolute z-30 mt-2 w-full min-w-[22rem] rounded-xl border border-stone-200 bg-white p-2 shadow-xl">
          <div className="relative">
            <Search size={15} className="pointer-events-none absolute left-3 top-2.5 text-stone-400" />
            <input
              type="search"
              aria-label={`Search ${label.toLocaleLowerCase()}`}
              value={search}
              onChange={(event) => setSearch(event.target.value)}
              placeholder={placeholder}
              autoFocus
              className="w-full rounded-lg border border-stone-300 py-2 pl-9 pr-3 text-sm outline-none focus:border-folio-500 focus:ring-2 focus:ring-folio-200"
            />
          </div>

          <div
            id={listboxId}
            role="listbox"
            aria-label={`${label.replace(/s$/i, '')} options`}
            aria-multiselectable="true"
            className="mt-2 max-h-72 overflow-y-auto rounded-lg border border-stone-100"
          >
            {filteredOptions.length === 0 ? (
              <p className="px-3 py-6 text-center text-sm text-stone-500">No matching locations.</p>
            ) : filteredOptions.map((option) => {
              const selected = selectedSet.has(option.value);
              return (
                <label
                  key={option.value}
                  role="option"
                  aria-selected={selected}
                  className="flex cursor-pointer items-start gap-2 border-b border-stone-100 px-3 py-2.5 text-sm text-gray-700 last:border-b-0 hover:bg-stone-50"
                >
                  <input
                    type="checkbox"
                    checked={selected}
                    disabled={!selected && atLimit}
                    onChange={() => toggleOption(option.value)}
                    className="mt-0.5 h-4 w-4 rounded border-stone-300 text-folio-700 focus:ring-folio-300"
                  />
                  <span>{option.label}</span>
                </label>
              );
            })}
          </div>

          {atLimit && (
            <p className="mt-2 text-xs font-medium text-amber-700">
              Maximum of {maxSelections} {singularLabel}{maxSelections === 1 ? '' : 's'} selected.
            </p>
          )}
        </div>
      )}
    </div>
  );
}
