import type { SemanticValidation } from '../types';

interface ExploratorySemanticValidationPanelProps {
  validation: SemanticValidation;
}

export function ExploratorySemanticValidationPanel({ validation }: ExploratorySemanticValidationPanelProps) {
  if (validation.status !== 'validated' || validation.checkedRequirements.length === 0) return null;

  return (
    <section
      className="rounded-lg border border-green-200 bg-green-50 p-4"
      aria-labelledby="semantic-validation-title"
    >
      <h3 id="semantic-validation-title" className="text-sm font-semibold text-green-950">
        Validated against your request
      </h3>
      <ul className="mt-2 space-y-1 text-sm text-green-900">
        {validation.checkedRequirements.map((requirement) => (
          <li key={requirement.key} className="flex gap-2">
            <span aria-hidden="true">✓</span>
            <span>{requirement.label}</span>
          </li>
        ))}
      </ul>
    </section>
  );
}
