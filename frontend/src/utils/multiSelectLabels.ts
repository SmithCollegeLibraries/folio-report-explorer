export function selectionLabels(label: string): { singular: string; plural: string } {
  const normalized = label.trim();
  const lower = normalized.toLocaleLowerCase();
  if (lower.endsWith('statuses')) {
    return { singular: normalized.slice(0, -2), plural: normalized };
  }
  if (lower.endsWith('status')) {
    return { singular: normalized, plural: `${normalized}es` };
  }
  if (/(?:sses|ches|shes|xes|zes)$/i.test(normalized)) {
    return { singular: normalized.slice(0, -2), plural: normalized };
  }
  if (lower.endsWith('ies')) {
    return { singular: `${normalized.slice(0, -3)}y`, plural: normalized };
  }
  if (lower.endsWith('s') && !/(?:us|ss)$/i.test(normalized)) {
    return { singular: normalized.slice(0, -1), plural: normalized };
  }
  if (/(?:s|x|z|ch|sh)$/i.test(normalized)) {
    return { singular: normalized, plural: `${normalized}es` };
  }
  return { singular: normalized, plural: `${normalized}s` };
}

export function selectionCountLabel(count: number, singularLabel: string, pluralLabel: string): string {
  if (count === 0) return `No ${pluralLabel.toLocaleLowerCase()} selected`;
  const label = count === 1 ? singularLabel : pluralLabel;
  return `${count} ${label.toLocaleLowerCase()} selected`;
}
