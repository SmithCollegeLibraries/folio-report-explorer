import { describe, expect, it } from 'vitest';

type TestQuestion = { sheet: 'survey'; question_id: string; part: string; section: string };

function question(question_id: string, part = '', section = 'Library collections'): TestQuestion {
  return { sheet: 'survey', question_id, part, section };
}

describe('parseInstructionPages', () => {
  it('preserves question, shared-group, and definition blocks across page breaks', async () => {
    const parser = Object.values(import.meta.glob('./acrlInstructionParser.ts', { eager: true }))[0] as {
      parseInstructionPages: (pages: string[], questions: TestQuestion[]) => {
        coverage: { individual: number; grouped: number; missing: number };
        missingQuestionKeys: string[];
        blocks: Array<{ blockKey: string; heading: string; text: string; kind: string }>;
        assignments: Array<{ question_id: string; part: string; blocks: Array<{ blockKey: string; heading: string; kind: string }> }>;
        links: Array<{ question_id: string; part: string; blockKey: string }>;
      };
    } | undefined;
    expect(parser).toBeDefined();
    if (!parser) return;

    const questions: TestQuestion[] = [
      question('40'), question('41'), question('60', '', 'Library services'), question('81', '', 'Library services'),
    ];
    for (let id = 1; id <= 6; id += 1) {
      questions.push(question(String(id), 'A', 'Staffing'), question(String(id), 'B', 'Staffing expenses'));
    }
    questions.push(question('30', '', 'Expenses'), question('31', '', 'Expenses'));
    for (const id of ['70', '71']) {
      for (const part of ['A', 'B', 'C']) questions.push(question(id, part, 'Library services'));
    }
    questions.push(
      question('91', '', 'Local characteristics and enrollment'),
      question('91a', '', 'Local characteristics and enrollment'),
      question('91b', '', 'Local characteristics and enrollment'),
      question('91c', '', 'Local characteristics and enrollment'),
      question('91d', '', 'Local characteristics and enrollment'),
      question('91e', '', 'Local characteristics and enrollment'),
      question('6', 'C', 'Staffing expenses'),
      question('99', '', 'Comments'),
    );

    const result = parser.parseInstructionPages([
      `Collections\nTitle\nA title is the intellectual unit of a work.\nDiscovery System\nThe public system makes holdings searchable.\nQ40. Physical book titles\nCount owned and leased titles searchable through the catalog,`,
      `including accessible leased titles.\nQ41. Physical book volumes\nCount individual physical volumes.\n1A–6A\nUse the first staffing breakdown for each category.\n1B–6B\nUse the salary breakdown for each category.\n30 & 31\nReport the paired expense measures.\n70A–C\nReport the three service parts.\n71A–C\nReport the three delivery parts.\n91/91A–91D\nReport total headcount or its four components.\n91e. Total headcount\nSum of 91a through 91d.\n6C. Total salaries\nUse only when the breakdown is unavailable.`,
      `Q60. Physical circulation\nCount checkouts.\nQ81. Loans supplied\nCount loans supplied to other libraries.`,
    ], questions);

    expect(result.missingQuestionKeys).toEqual(['99']);
    expect(result.coverage).toEqual({ individual: 8, grouped: 23, missing: 1 });
    expect(result.missingQuestionKeys).toEqual(['99']);
    expect(result.blocks.find((block) => block.blockKey === 'question:30-31')?.kind).toBe('question');
    expect(result.links.filter((link) => link.blockKey === 'question:30-31')).toHaveLength(2);
    expect(result.blocks.find((block) => block.blockKey === 'question:40')?.text)
      .toContain('including accessible leased titles.');

    const q40Blocks = result.assignments.find((item) => item.question_id === '40')?.blocks || [];
    expect(q40Blocks.map((block) => block.kind)).toEqual(['question', 'definition', 'definition']);
    expect(q40Blocks[0].heading).toContain('Q40');
    expect(q40Blocks.slice(1).map((block) => block.heading)).toEqual(['Title', 'Discovery System']);

    const groupedKeys = result.links.filter((link) => link.blockKey === 'group:1a-6a');
    expect(groupedKeys).toHaveLength(6);
    expect(groupedKeys.map(({ question_id, part }) => `${question_id}${part}`)).toEqual(['1A', '2A', '3A', '4A', '5A', '6A']);
    for (const key of ['40', '60', '81', '91e', '6C']) {
      const id = key.replace(/[A-C]$/i, '');
      const part = key.match(/[A-C]$/i)?.[0] || '';
      const assignment = result.assignments.find((item) => item.question_id.toLowerCase() === id.toLowerCase() && item.part.toLowerCase() === part.toLowerCase());
      expect(assignment?.blocks.some((block) => block.kind === 'question')).toBe(true);
    }
  });

  it('handles starred headings, skips contents, follows ranges and scopes definitions', async () => {
    const parser = Object.values(import.meta.glob('./acrlInstructionParser.ts', { eager: true }))[0] as {
      parseInstructionPages: (pages: string[], questions: TestQuestion[]) => {
        blocks: Array<{ blockKey: string; heading: string; kind: string }>;
        links: Array<{ question_id: string; part: string; blockKey: string }>;
        duplicateBlockKeys: string[];
        missingQuestionKeys: string[];
        coverage: { individual: number; grouped: number; missing: number };
      };
    } | undefined;
    expect(parser).toBeDefined();
    if (!parser) return;

    const questions: TestQuestion[] = [question('0A', '', 'Library characteristics'), question('68', '', 'Library characteristics'), question('24', '', 'Library characteristics'), question('40a')];
    questions.push(question('30', '', 'Expenses'), question('31', '', 'Expenses'));
    for (let id = 1; id <= 6; id += 1) questions.push(question(String(id), 'A', 'Staffing'), question(String(id), 'B', 'Staffing expenses'));
    questions.push(question('6', 'C', 'Staffing expenses'));
    for (const id of ['40', '41', '42', '43', '44']) {
      questions.push(question(id, 'A', 'Library collections'), question(id, 'B', 'Library collections'));
    }
    questions.push(question('60', 'A', 'Library services'), question('60', 'B', 'Library services'));
    questions.push(question('81', '', 'Library services'), question('82', '', 'Library services'));
    for (const letter of ['a', 'b', 'c']) questions.push(question(`81${letter}`, '', 'Library services'));
    for (const letter of ['a', 'b', 'c', 'd']) questions.push(question(`82${letter}`, '', 'Library services'));
    questions.push(question('91', '', 'Local characteristics and enrollment'));
    for (const letter of ['a', 'b', 'c', 'd', 'e']) questions.push(question(`91${letter}`, '', 'Local characteristics and enrollment'));

    const result = parser.parseInstructionPages([
      `Contents\n*1A–6A. Staffing FTEs ........ 7\n*1B–6B. Staffing salaries ........ 8\n91A-D. Headcounts ........ 30`,
      `*0A. Table of contents row ..................................................................................................... 9\n*68. Another contents row ........................................................................................................... 9`,
      `Library characteristics\n*0A. Does the data include a law library?\nReport whether the library has a law library.\n*68. Number of branch libraries`,
      `*24. Preservation services`,
      `ACRL Trends & Statistics Survey IPEDS Academic Library Component\nSurvey section Question\n*30. Contents row`,
      `*31. Contents row continuation`,
      `Expenses\n*30. & 31. Total expenses with and without fringe benefits\nReport the paired expense measures.`,
      `Collections\nTitle\nCount titles, not individual copies.\n*40A. Physical book titles\nCount titles available through the catalogue.\n*40B. Electronic book titles\nList electronic titles separately.\n40a. Physical book volumes\nReport physical volumes separately.\n*41A. Physical book volumes\n*41B. Electronic book volumes\n*42A. Physical media\nCount physical media titles. Do not duplicate question 40.\n*42B. Electronic media\n*43A. Physical serials\n*43B. Electronic serials\n*44A. Physical total\n*44B. Electronic total`,
      `Staffing\n*1A–6A. Staffing Types and FTEs\nReport FTE values.\n*1B–6B. Staffing Expenses\nQuestion 6B Total salaries and wages.\n5.1.1) Parser version note.\nThis section covers questions 1B–6C.`,
      `Library Services\n*60. Physical circulation\nCount checkouts.\n*81. Loans supplied\nInclude questions 81a-c.\n*82. Loans received\nInclude questions 82a-d.`,
      `Local characteristics and enrollment\n*91/91A-D. Headcounts\nTotal is calculated on line 91e.`,
    ], questions);

    const linksFor = (blockKey: string) => result.links.filter((link) => link.blockKey === blockKey)
      .map(({ question_id, part }) => `${question_id}${part}`);
    expect(result.duplicateBlockKeys).toEqual([]);
    expect(result.blocks.filter((block) => block.blockKey === 'group:1a-6a')).toHaveLength(1);
    expect(result.blocks.some((block) => block.heading === '91A-D. Headcounts')).toBe(false);
    expect(linksFor('group:1a-6a')).toEqual(['1A', '2A', '3A', '4A', '5A', '6A']);
    expect(linksFor('group:1b-6b')).toEqual(['1B', '2B', '3B', '4B', '5B', '6B', '6C']);
    expect(linksFor('question:30-31')).toEqual(['30', '31']);
    expect(result.blocks.find((block) => block.blockKey === 'question:30-31')?.kind).toBe('question');
    expect(linksFor('group:91a-d')).toEqual(['91', '91a', '91b', '91c', '91d', '91e']);
    expect(linksFor('question:40a')).toEqual(['40a']);
    expect(linksFor('question:42-a')).toEqual(['42A']);
    expect(result.blocks.some((block) => block.blockKey === 'question:6b')).toBe(false);
    expect(result.blocks.some((block) => block.blockKey === 'question:5')).toBe(false);
    expect(result.blocks.some((block) => block.heading === '0A. Does the data include a law library?')).toBe(true);
    expect(linksFor('question:81')).toEqual(['81', '81a', '81b', '81c']);
    expect(linksFor('question:82')).toEqual(['82', '82a', '82b', '82c', '82d']);
    const titleLinks = linksFor('definition:title');
    expect(titleLinks).toContain('40A');
    expect(titleLinks).toContain('44B');
    expect(linksFor('question:40-a')).toEqual(['40A']);
    expect(linksFor('question:40-b')).toEqual(['40B']);
    expect(titleLinks).not.toContain('60A');
    expect(result.blocks.some((block) => block.heading === 'Library Services' && block.kind === 'definition')).toBe(false);
    expect(result.missingQuestionKeys).toEqual(['68', '24', '41A', '41B', '42B', '43A', '43B', '44A', '44B']);
    expect(result.coverage.missing).toBe(9);
  });

  it('reports repeated body block keys while emitting one unique block for preview to reject', async () => {
    const parser = Object.values(import.meta.glob('./acrlInstructionParser.ts', { eager: true }))[0] as {
      parseInstructionPages: (pages: string[], questions: TestQuestion[]) => {
        blocks: Array<{ blockKey: string }>;
        duplicateBlockKeys: string[];
      };
    } | undefined;
    expect(parser).toBeDefined();
    if (!parser) return;
    const result = parser.parseInstructionPages([
      '*1A–2A. FTEs\nReport staffing FTE values.\n*1A–2A. Repeated body heading\nReport the same group details.',
    ], [question('1', 'A', 'Staffing'), question('2', 'A', 'Staffing')]);
    expect(result.blocks.map((block) => block.blockKey)).toEqual(['group:1a-2a']);
    expect(result.duplicateBlockKeys).toEqual(['group:1a-2a']);
  });

  it('keeps body lines beginning with a question key inside the current question block', async () => {
    const parser = Object.values(import.meta.glob('./acrlInstructionParser.ts', { eager: true }))[0] as {
      parseInstructionPages: (pages: string[], questions: TestQuestion[]) => {
        blocks: Array<{ blockKey: string; text: string }>;
        duplicateBlockKeys: string[];
      };
    } | undefined;
    expect(parser).toBeDefined();
    if (!parser) return;

    const result = parser.parseInstructionPages([
      `70A. Example A\nFirst body text.\n70A explanatory wording stays in this block.\n71A. Example B\nSecond body text.\n71A supplemental wording remains here.`,
      `72B. Example C\nThird body text.\n74. Example D\nFirst sentence.\n72B explanatory material is displayed here.\nThis line remains with question 74.\nFinal sentence of question 74.\n75. Example E\nReport additional details.`,
    ], [
      question('70', 'A'), question('71', 'A'), question('72', 'B'), question('74'), question('75'),
    ]);

    expect(result.duplicateBlockKeys).toEqual([]);
    expect(result.blocks.find((block) => block.blockKey === 'question:70-a')?.text)
      .toContain('70A explanatory wording stays in this block.');
    expect(result.blocks.find((block) => block.blockKey === 'question:71-a')?.text)
      .toContain('71A supplemental wording remains here.');
    expect(result.blocks.find((block) => block.blockKey === 'question:74')?.text)
      .toContain('72B explanatory material is displayed here.');
    expect(result.blocks.find((block) => block.blockKey === 'question:74')?.text)
      .toContain('Final sentence of question 74.');
    expect(result.blocks.find((block) => block.blockKey === 'question:75')?.text)
      .toContain('Report additional details.');
  });

  it('links bodyless questions to their subsection introduction without absorbing the next subsection', async () => {
    const parser = Object.values(import.meta.glob('./acrlInstructionParser.ts', { eager: true }))[0] as {
      parseInstructionPages: (pages: string[], questions: TestQuestion[]) => {
        blocks: Array<{ blockKey: string; heading: string; text: string; kind: string }>;
        links: Array<{ question_id: string; part: string; blockKey: string }>;
        missingQuestionKeys: string[];
      };
    } | undefined;
    expect(parser).toBeDefined();
    if (!parser) return;

    const result = parser.parseInstructionPages([
      `Reporting Structure\nReport the administrative structure of the library.\n0A. Library administration\n0B. Reporting relationship\nBranch/Independent Libraries\nDescribe branch locations.\n1. Branch count\nCount all branches.`,
    ], [question('0', 'A'), question('0', 'B'), question('1')]);

    const reporting = result.blocks.find((block) => block.blockKey === 'group:reporting-structure');
    expect(reporting).toEqual({
      blockKey: 'group:reporting-structure',
      heading: 'Reporting Structure',
      text: 'Report the administrative structure of the library.',
      kind: 'group',
    });
    expect(result.links.filter((link) => link.blockKey === 'group:reporting-structure')
      .map(({ question_id, part }) => `${question_id}${part}`)).toEqual(['0A', '0B']);
    expect(result.blocks.some((block) => block.blockKey === 'question:0-a' || block.blockKey === 'question:0-b')).toBe(false);
    expect(result.blocks.find((block) => block.blockKey === 'question:1')?.text).toBe('Count all branches.');
    expect(result.blocks.find((block) => block.blockKey === 'group:reporting-structure')?.text)
      .not.toContain('Branch/Independent Libraries');
    expect(result.blocks.every((block) => block.text.trim().length > 0)).toBe(true);
    expect(result.missingQuestionKeys).toEqual([]);
  });

  it('counts bodyless questions without a subsection introduction as missing and emits no empty blocks', async () => {
    const parser = Object.values(import.meta.glob('./acrlInstructionParser.ts', { eager: true }))[0] as {
      parseInstructionPages: (pages: string[], questions: TestQuestion[]) => {
        blocks: Array<{ blockKey: string; text: string }>;
        missingQuestionKeys: string[];
      };
    } | undefined;
    expect(parser).toBeDefined();
    if (!parser) return;

    const result = parser.parseInstructionPages([
      `Reporting Structure\n0A. Library administration\n0B. Reporting relationship`,
    ], [question('0', 'A'), question('0', 'B')]);

    expect(result.blocks).toEqual([]);
    expect(result.missingQuestionKeys).toEqual(['0A', '0B']);
  });
});
