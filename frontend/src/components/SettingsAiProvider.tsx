import { Sparkles } from 'lucide-react';

export default function SettingsAiProvider({
  value,
  onChange,
}: {
  value: string;
  onChange: (value: string) => void;
}) {
  return (
    <section className="mb-8">
      <div className="flex items-center gap-2 mb-4">
        <Sparkles size={18} className="text-purple-600" />
        <h3 className="text-lg font-semibold">AI Provider</h3>
      </div>
      <div className="bg-white border rounded-lg p-5 space-y-4">
        <div>
          <label className="block text-xs font-medium text-gray-600 mb-1">Active Provider</label>
          <select
            value={value}
            onChange={(event) => onChange(event.target.value)}
            className="w-full border rounded px-3 py-2 text-sm bg-white"
          >
            <option value="gemini">Gemini</option>
            <option value="openai">OpenAI</option>
          </select>
          <p className="text-xs text-gray-400 mt-1">
            Ask AI uses this provider after you save settings.
          </p>
        </div>
      </div>
    </section>
  );
}
