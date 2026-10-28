import type { AcrlInstructionBlock, AcrlInstructionLink, AcrlInstructionQuestionRef } from '../types/acrl';

const BLOCK_KEY_PATTERN = /^[a-z0-9][a-z0-9:_-]{0,79}$/;
const SUPPORTED_KINDS = new Set(['question', 'group', 'definition']);

export function validateInstructionSaveRules(
  blocks: AcrlInstructionBlock[],
  links: AcrlInstructionLink[],
  questions: AcrlInstructionQuestionRef[],
): string[] {
  const problems: string[] = [];
  const blockKeys = new Set<string>();
  const questionKeys = new Set(questions.map((question) => `${question.sheet}:${question.question_id}:${question.part}`));

  blocks.forEach((block, index) => {
    const label = block.blockKey || `Block ${index + 1}`;
    if (!BLOCK_KEY_PATTERN.test(block.blockKey)) problems.push(`${label}: block key must match the server format.`);
    if (!block.heading.trim()) problems.push(`${label}: heading is required.`);
    if (new TextEncoder().encode(block.heading).length > 255) problems.push(`${label}: heading exceeds 255 UTF-8 bytes.`);
    if (!block.text.trim()) problems.push(`${label}: text is required.`);
    if (new TextEncoder().encode(block.text).length > 50000) problems.push(`${label}: text exceeds 50,000 UTF-8 bytes.`);
    if (!SUPPORTED_KINDS.has(block.kind)) problems.push(`${label}: kind is not supported.`);
    if (blockKeys.has(block.blockKey)) problems.push(`${label}: block key is duplicated.`);
    blockKeys.add(block.blockKey);
  });

  links.forEach((link) => {
    const label = `Link ${link.sheet}:${link.question_id}${link.part}`;
    if (!blockKeys.has(link.blockKey)) problems.push(`${label}: references unknown block "${link.blockKey}".`);
    if (!questionKeys.has(`${link.sheet}:${link.question_id}:${link.part}`)) {
      problems.push(`${label}: references an unknown question part.`);
    }
  });

  return problems;
}
