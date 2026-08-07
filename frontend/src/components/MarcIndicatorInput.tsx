import { useEffect, useMemo, useState } from 'react';

const DIGITS = Array.from({ length: 10 }, (_, index) => String(index));

export interface MarcIndicatorInputProps {
  name: string;
  label: string;
  value: string;
  onChange: (value: string) => void;
  error?: string;
}

function customCharacter(value: string): string {
  return value.startsWith('char:') ? [...value.slice(5)][0] || 'X' : 'X';
}

function normalizeCustom(value: string): string {
  const character = [...value][0] || '';
  if (character === '\\' || character.trim() === '') return 'blank';
  return character ? `char:${character}` : 'char:X';
}

export default function MarcIndicatorInput({
  name,
  label,
  value,
  onChange,
  error,
}: MarcIndicatorInputProps) {
  const [customValue, setCustomValue] = useState(() => customCharacter(value));
  const propIsCustom = value.startsWith('char:') && !/^char:[0-9]$/.test(value);
  const [customMode, setCustomMode] = useState(propIsCustom);
  const isCustom = customMode || propIsCustom;
  const selectedValue = isCustom ? 'custom' : value || 'any';
  const inputId = `marc-indicator-${name}`;
  const customInputId = `${inputId}-custom`;

  useEffect(() => {
    if (propIsCustom) {
      setCustomMode(true);
      setCustomValue(customCharacter(value));
    } else if (value === 'any' || value === 'blank' || /^char:[0-9]$/.test(value)) {
      setCustomMode(false);
    }
  }, [propIsCustom, value]);

  const options = useMemo(() => [
    { value: 'any', label: 'Any' },
    { value: 'blank', label: 'Blank (#)' },
    ...DIGITS.map((digit) => ({ value: `char:${digit}`, label: digit })),
    { value: 'custom', label: 'Custom character' },
  ], []);

  const handleSelect = (next: string) => {
    if (next === 'custom') {
      const custom = customCharacter(value);
      setCustomMode(true);
      setCustomValue(custom);
      onChange(`char:${custom}`);
      return;
    }
    setCustomMode(false);
    onChange(next);
  };

  const handleCustomChange = (next: string) => {
    const character = [...next][0] || '';
    setCustomValue(character);
    onChange(normalizeCustom(next));
  };

  return (
    <div>
      <label htmlFor={inputId} className="mb-1 block text-xs font-medium text-gray-600">
        {label}
      </label>
      <select
        id={inputId}
        aria-label={label}
        value={selectedValue}
        onChange={(event) => handleSelect(event.target.value)}
        aria-invalid={Boolean(error)}
        className="w-full rounded-lg border border-stone-300 bg-white px-3 py-2 text-sm outline-none transition focus:border-folio-500 focus:ring-2 focus:ring-folio-200"
      >
        {options.map((option) => (
          <option key={option.value} value={option.value}>{option.label}</option>
        ))}
      </select>
      {isCustom && (
        <div className="mt-2">
          <label htmlFor={customInputId} className="mb-1 block text-xs text-gray-500">
            Custom character
          </label>
          <input
            id={customInputId}
            aria-label={`${label} custom character`}
            type="text"
            value={customValue}
            maxLength={1}
            onChange={(event) => handleCustomChange(event.target.value)}
            className="w-full rounded-lg border border-stone-300 px-3 py-2 text-sm outline-none transition focus:border-folio-500 focus:ring-2 focus:ring-folio-200"
          />
        </div>
      )}
      {error && <p role="alert" className="mt-1 text-xs text-red-600">{error}</p>}
    </div>
  );
}
