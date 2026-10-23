import { ChevronDown, Search, X } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { selectionCountLabel, selectionLabels } from '../utils/multiSelectLabels';

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
  required?: boolean;
  onChange: (value: string) => void;
  error?: string;
  errorId?: string;
  buttonPlaceholder?: string;
  selectedSummary?: string;
  disabled?: boolean;
}

const MAX_VISIBLE_CHIPS = 3;
const MAX_SHORT_SUMMARY_LENGTH = 32;

export default function SearchableMultiSelect({
  id,
  label,
  value,
  options,
  placeholder = 'Search options',
  maxSelections = 100,
  required = false,
  onChange,
  error,
  errorId,
  buttonPlaceholder,
  selectedSummary,
  disabled = false,
}: SearchableMultiSelectProps) {
  const [isOpen, setIsOpen] = useState(false);
  const [search, setSearch] = useState('');
  useEffect(() => {
    if (disabled) setIsOpen(false);
  }, [disabled]);
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
  const selectionName = selectionLabels(label);
  const singularLabel = selectionName.singular;
  const pluralLabel = selectionName.plural;
  const selectedLabels = selectedValues
    .map((selectedValue) => optionByValue.get(selectedValue)?.label)
    .filter((optionLabel): optionLabel is string => Boolean(optionLabel));
  const selectedNames = selectedLabels.join(', ');
  const isAllSelected = options.length > 0
    && selectedValues.length === options.length
    && options.every((option) => selectedSet.has(option.value));

  const emit = (next: string[]) => onChange(next.join(','));
  const toggleOption = (optionValue: string) => {
    if (selectedSet.has(optionValue)) {
      emit(selectedValues.filter((selectedValue) => selectedValue !== optionValue));
      return;
    }
    if (!atLimit) {
      emit([...selectedValues, optionValue]);
      setIsOpen(false);
    }
  };

  const countLabel = selectionCountLabel(selectedValues.length, singularLabel, pluralLabel);
  const selectorLabel = `Select ${label.toLocaleLowerCase()}`;
  const buttonText = disabled && buttonPlaceholder
    ? buttonPlaceholder
    : selectedValues.length === 0
      ? buttonPlaceholder || selectorLabel
      : isAllSelected
        ? `All ${pluralLabel.toLocaleLowerCase()} (${options.length})`
        : selectedLabels.length === selectedValues.length && selectedNames.length <= MAX_SHORT_SUMMARY_LENGTH
          ? selectedNames
          : options.length > 0
            ? `${selectedValues.length} of ${options.length} ${pluralLabel.toLocaleLowerCase()}`
            : countLabel;
  const accessibleButtonLabel = disabled && buttonPlaceholder
    ? buttonPlaceholder
    : selectedValues.length === 0
      ? buttonPlaceholder || selectorLabel
      : selectedSummary || countLabel;
  const limitLabel = (maxSelections === 1 ? singularLabel : pluralLabel).toLocaleLowerCase();
  const visibleSelectedValues = isAllSelected ? [] : selectedValues.slice(0, MAX_VISIBLE_CHIPS);
  const hiddenSelectionCount = selectedValues.length - visibleSelectedValues.length;

  return (
    <div className="relative" onKeyDown={(event) => {
      if (event.key === 'Escape') setIsOpen(false);
    }}>
      <button
        id={id}
        type="button"
        aria-label={accessibleButtonLabel}
        aria-haspopup="listbox"
        aria-expanded={isOpen}
        aria-controls={listboxId}
        aria-required={required ? true : undefined}
        aria-invalid={error ? true : undefined}
        aria-describedby={error ? errorId : undefined}
        onClick={() => setIsOpen((current) => !current)}
        disabled={disabled}
        title={selectedLabels.length > 0 ? selectedNames : undefined}
        className="flex h-10 w-full min-w-0 items-center justify-between rounded-lg border border-stone-300 bg-white px-3 text-left text-sm outline-none transition focus:border-folio-500 focus:ring-2 focus:ring-folio-200"
      >
        <span className={`min-w-0 flex-1 truncate ${selectedValues.length === 0 ? 'text-stone-500' : 'text-gray-800'}`}>
          {buttonText}
        </span>
        <ChevronDown size={16} className={`ml-2 shrink-0 text-stone-400 transition ${isOpen ? 'rotate-180' : ''}`} />
      </button>

      {visibleSelectedValues.length > 0 && (
        <div className="mt-2 flex min-w-0 items-center gap-1.5 overflow-hidden">
          {visibleSelectedValues.map((selectedValue) => {
            const option = optionByValue.get(selectedValue);
            if (!option) return null;
            return (
              <span
                key={selectedValue}
                className={`inline-flex h-6 ${hiddenSelectionCount > 0 ? 'max-w-[24%]' : 'max-w-[32%]'} min-w-0 shrink-0 items-center gap-1 rounded-full bg-folio-50 px-2 text-xs font-medium text-folio-800`}
              >
                <span className="min-w-0 truncate">{option.label}</span>
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
          {hiddenSelectionCount > 0 && (
            <button
              type="button"
              aria-haspopup="listbox"
              aria-expanded={isOpen}
              aria-controls={listboxId}
              title={`Show ${hiddenSelectionCount} more selected ${pluralLabel.toLocaleLowerCase()}`}
              onClick={() => setIsOpen(true)}
              className="h-6 shrink-0 whitespace-nowrap rounded-full px-2 text-xs font-medium text-folio-700 hover:bg-folio-50"
            >
              +{hiddenSelectionCount} more
            </button>
          )}
        </div>
      )}

      {isOpen && (
        <div className="absolute z-30 mt-2 w-full min-w-[22rem] rounded-xl border border-stone-200 bg-white p-2 shadow-xl">
          <div className="mb-2 flex items-center justify-end gap-2">
            <button
              type="button"
              aria-label={`Select all ${label.toLocaleLowerCase()}`}
              onClick={() => emit(options.slice(0, maxSelections).map((option) => option.value))}
              disabled={isAllSelected || atLimit || options.length === 0 || options.length > maxSelections}
              className="rounded-md px-2 py-1 text-xs font-medium text-folio-700 hover:bg-folio-50 disabled:cursor-not-allowed disabled:text-stone-400"
            >
              Select all
            </button>
            <button
              type="button"
              aria-label={`Clear all ${label.toLocaleLowerCase()}`}
              onClick={() => emit([])}
              disabled={selectedValues.length === 0}
              className="rounded-md px-2 py-1 text-xs font-medium text-folio-700 hover:bg-folio-50 disabled:cursor-not-allowed disabled:text-stone-400"
            >
              Clear all
            </button>
          </div>
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
            aria-label={`${singularLabel} options`}
            aria-multiselectable="true"
            className="mt-2 max-h-72 overflow-y-auto rounded-lg border border-stone-100"
          >
            {filteredOptions.length === 0 ? (
              <p className="px-3 py-6 text-center text-sm text-stone-500">No matching {pluralLabel.toLocaleLowerCase()}.</p>
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
              Maximum of {maxSelections} {limitLabel} selected.
            </p>
          )}
        </div>
      )}
      {error && <p id={errorId} role="alert" className="mt-1 text-xs text-red-600">{error}</p>}
    </div>
  );
}
