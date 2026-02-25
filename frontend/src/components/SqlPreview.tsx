import Editor from '@monaco-editor/react';

interface Props {
  sql: string;
  onChange?: (value: string) => void;
  readOnly?: boolean;
  height?: string;
}

export default function SqlPreview({
  sql,
  onChange,
  readOnly = true,
  height = '200px',
}: Props) {
  return (
    <div className="border rounded-lg overflow-hidden">
      <div className="flex items-center justify-between px-3 py-1.5 bg-gray-800 text-gray-300 text-xs">
        <span>SQL Preview</span>
        {readOnly && (
          <span className="text-gray-500 italic">read-only</span>
        )}
      </div>
      <Editor
        height={height}
        defaultLanguage="sql"
        value={sql}
        onChange={(value) => onChange?.(value || '')}
        theme="vs-dark"
        options={{
          readOnly,
          minimap: { enabled: false },
          fontSize: 13,
          lineNumbers: 'on',
          scrollBeyondLastLine: false,
          wordWrap: 'on',
          automaticLayout: true,
          padding: { top: 8 },
        }}
      />
    </div>
  );
}
