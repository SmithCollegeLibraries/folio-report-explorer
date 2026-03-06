export const SOURCE_STYLES: Record<string, string> = {
  nl:      'bg-purple-100 text-purple-700',
  builder: 'bg-blue-100 text-blue-700',
  manual:  'bg-gray-100 text-gray-600',
  report:  'bg-amber-100 text-amber-700',
};

const SOURCE_LABELS: Record<string, string> = {
  nl:      'Ask AI',
  builder: 'Builder',
};

interface Props {
  source: string;
}

export default function SourceBadge({ source }: Props) {
  const label = SOURCE_LABELS[source] ?? source;
  return (
    <span className={`inline-flex items-center text-xs font-medium px-2 py-0.5 rounded ${SOURCE_STYLES[source] ?? 'bg-gray-100 text-gray-600'}`}>
      {label}
    </span>
  );
}
