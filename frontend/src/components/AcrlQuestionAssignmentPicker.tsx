import type { AcrlAssignmentUser, AcrlEntryQuestion, AcrlQuestionAssignment } from '../types/acrl';
import { acrlQuestionAssignmentKey, acrlQuestionLabel } from '../utils/acrlAssignments';

type AcrlQuestionAssignmentPickerProps = {
  questions: AcrlEntryQuestion[];
  selectedKey: string;
  sectionAssignees: AcrlAssignmentUser[];
  assignment?: AcrlQuestionAssignment;
  onChange: (key: string) => void;
};

function truncatePrompt(prompt: string): string {
  return prompt.length > 60 ? `${prompt.slice(0, 59)}…` : prompt;
}

export default function AcrlQuestionAssignmentPicker({ questions, selectedKey, sectionAssignees, assignment, onChange }: AcrlQuestionAssignmentPickerProps) {
  const sectionNames = sectionAssignees.map((user) => user.displayName).join(', ') || 'none';
  const questionNames = assignment?.assignees.map((user) => user.displayName).join(', ') || 'none';

  return <>
    <label className="block text-sm font-medium text-gray-700">Question
      <select className="mt-1 block w-full rounded border border-gray-300 bg-white px-3 py-2" value={selectedKey} onChange={(event) => onChange(event.target.value)}>
        <option value="">Whole section</option>
        {questions.map((question) => {
          const key = acrlQuestionAssignmentKey(question.sheet, question.questionId, question.part);
          return <option key={key} value={key}>{acrlQuestionLabel(question.questionId, question.part)} - {truncatePrompt(question.prompt)}</option>;
        })}
      </select>
    </label>
    {selectedKey && <p className="text-sm text-gray-600">Section: {sectionNames} · This question: {questionNames}</p>}
  </>;
}
