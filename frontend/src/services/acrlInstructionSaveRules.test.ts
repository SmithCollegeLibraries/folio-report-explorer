import { describe, expect, it } from 'vitest';
import type { AcrlInstructionBlock, AcrlInstructionLink, AcrlInstructionQuestionRef } from '../types/acrl';
import { validateInstructionSaveRules } from './acrlInstructionSaveRules';

const questions: AcrlInstructionQuestionRef[] = [{ sheet: 'survey', question_id: '60', part: 'A', section: 'Library services' }];

function block(blockKey: string, changes: Partial<AcrlInstructionBlock> = {}): AcrlInstructionBlock {
  return { blockKey, heading: 'Synthetic heading', text: 'Synthetic body', kind: 'question', ...changes };
}

describe('validateInstructionSaveRules', () => {
  it('accepts blocks and links that satisfy the API contract', () => {
    const links: AcrlInstructionLink[] = [{ sheet: 'survey', question_id: '60', part: 'A', blockKey: 'question:60-a' }];
    const maximumLengthBlock = block('a'.repeat(80), {
      heading: 'h'.repeat(255),
      text: 't'.repeat(50000),
    });

    expect(validateInstructionSaveRules([maximumLengthBlock], [
      { ...links[0], blockKey: 'a'.repeat(80) },
    ], questions)).toEqual([]);
  });

  it('reports every server rule violation with the affected block or link', () => {
    const duplicate = block('question:duplicate');
    const blocks = [
      block('Bad key', { heading: '  ', text: '\n' }),
      block('question:long', { heading: 'é'.repeat(128), text: 'é'.repeat(25001), kind: 'unsupported' as AcrlInstructionBlock['kind'] }),
      duplicate,
      duplicate,
    ];
    const links: AcrlInstructionLink[] = [
      { sheet: 'survey', question_id: '999', part: '', blockKey: 'missing-block' },
      { sheet: 'survey', question_id: '60', part: 'A', blockKey: 'question:duplicate' },
    ];

    const problems = validateInstructionSaveRules(blocks, links, questions);

    expect(problems).toContain('Bad key: block key must match the server format.');
    expect(problems).toContain('Bad key: heading is required.');
    expect(problems).toContain('Bad key: text is required.');
    expect(problems).toContain('question:long: heading exceeds 255 UTF-8 bytes.');
    expect(problems).toContain('question:long: text exceeds 50,000 UTF-8 bytes.');
    expect(problems).toContain('question:long: kind is not supported.');
    expect(problems).toContain('question:duplicate: block key is duplicated.');
    expect(problems).toContain('Link survey:999: references unknown block "missing-block".');
    expect(problems).toContain('Link survey:999: references an unknown question part.');
  });
});
