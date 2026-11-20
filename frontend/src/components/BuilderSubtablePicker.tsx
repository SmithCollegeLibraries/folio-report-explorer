import { useEffect, useRef, useState } from 'react';
import { Handle, Panel, Position } from '@xyflow/react';
import type { SubtableGroup } from './builderGraphSubtableGroups';

interface Props {
  group: SubtableGroup | undefined;
  onAddTable: (name: string) => void;
  onClose: () => void;
}

export function BuilderSubtableGroupNode({ data }: { data: Record<string, unknown> }) {
  return (
    <>
      <Handle type="target" position={Position.Left} />
      <button
        type="button"
        className="nodrag nopan w-full cursor-pointer border-0 bg-transparent p-0 text-center font-mono text-xs text-inherit focus-visible:outline focus-visible:outline-2 focus-visible:outline-blue-600"
      >
        {typeof data.label === 'string' ? data.label : ''}
      </button>
    </>
  );
}

export default function BuilderSubtablePicker({ group, onAddTable, onClose }: Props) {
  const [search, setSearch] = useState('');
  const searchRef = useRef<HTMLInputElement>(null);

  useEffect(() => {
    if (!group) return;
    setSearch('');
    searchRef.current?.focus();
  }, [group?.parentName]);

  if (!group) return null;

  const needle = search.trim().toLocaleLowerCase();
  const subtables = needle
    ? group.subtables.filter((table) => table.shortName.toLocaleLowerCase().includes(needle))
    : group.subtables;

  return (
    <Panel position="top-center">
      <div
        role="dialog"
        aria-label={`Subtables of ${group.parentShortName}`}
        onKeyDown={(event) => {
          if (event.key !== 'Escape') return;
          event.preventDefault();
          onClose();
        }}
        className="w-80 rounded-lg border border-gray-200 bg-white p-3 shadow-xl"
      >
        <div className="mb-2 flex items-start justify-between gap-3">
          <h4 className="text-sm font-semibold text-gray-800">
            Subtables of {group.parentShortName}
          </h4>
          <button
            type="button"
            aria-label="Close subtable picker"
            onClick={onClose}
            className="rounded px-2 py-1 text-xs text-gray-500 hover:bg-gray-100"
          >
            Close
          </button>
        </div>
        <input
          ref={searchRef}
          type="search"
          aria-label="Search subtables"
          value={search}
          onChange={(event) => setSearch(event.target.value)}
          className="mb-2 w-full rounded border border-gray-300 px-3 py-2 text-sm"
        />
        <ul className="max-h-64 space-y-1 overflow-auto">
          {subtables.map((table) => (
            <li key={table.name}>
              <button
                type="button"
                title={table.name}
                onClick={() => {
                  onAddTable(table.name);
                  onClose();
                }}
                className="w-full rounded border border-gray-200 bg-white px-3 py-2 text-left font-mono text-xs text-gray-700 hover:bg-gray-50"
              >
                {table.shortName}
              </button>
            </li>
          ))}
        </ul>
      </div>
    </Panel>
  );
}
