// @vitest-environment node
import { readFile } from 'node:fs/promises';
import { join } from 'node:path';
import { getDocument } from 'pdfjs-dist/legacy/build/pdf.mjs';
import { describe, expect, it } from 'vitest';
import { parseInstructionPages } from './acrlInstructionParser';
import { pdfItemsToText, type PdfTextItem } from './acrlPdfText';
import { parseWorksheetPages } from './acrlWorksheetParser';

const pdfDirectory = process.env.ACRL_REAL_PDF_DIR;
const realPdfTest = pdfDirectory ? it : it.skip;

describe('real 2026 ACRL PDFs (local only)', () => {
  realPdfTest('covers every survey worksheet part with the instructions PDF', async () => {
    const worksheetPages = await readPdfPages(join(pdfDirectory as string, '2026_ACRL_Survey_Worksheet.pdf'));
    const instructionPages = await readPdfPages(join(pdfDirectory as string, '2026_ACRL_Survey_Instructions.pdf'));
    const worksheet = parseWorksheetPages(worksheetPages);
    const surveyQuestions = worksheet.items.filter((question) => question.sheet === 'survey');
    const parsed = parseInstructionPages(instructionPages, surveyQuestions);
    const selectedKeys = [
      { key: '40', questionId: '40', part: 'A' },
      { key: '60', questionId: '60', part: 'A' },
      { key: '81', questionId: '81', part: '' },
      { key: '91e', questionId: '91e', part: '' },
      { key: '6C', questionId: '6', part: 'C' },
    ];
    const selectedBlocks = selectedKeys.map(({ key, questionId, part }) => {
      const assignment = parsed.assignments.find((item) => item.question_id.toLowerCase() === questionId.toLowerCase()
        && item.part.toLowerCase() === part.toLowerCase());
      return { key, blocks: (assignment?.blocks || []).map(({ blockKey, kind, heading, text }) => ({ blockKey, kind, heading, text })) };
    });
    const selectedBlockKeys = selectedBlocks.map(({ key, blocks }) => ({
      key,
      blocks: blocks.map(({ blockKey, kind, text }) => ({ blockKey, kind, hasText: text.trim().length > 0 })),
    }));
    const bodyKeyLines = findBodyKeyLines(instructionPages, surveyQuestions);
    const affectedLengths = ['74', '75'].map((questionId) => {
      const assignment = parsed.assignments.find((item) => item.question_id === questionId && item.part === '');
      const block = assignment?.blocks.find((item) => item.kind === 'question');
      return { questionId, blockKey: block?.blockKey || null, textLength: block?.text.length || 0 };
    });

    console.info('ACRL 2026 local PDF parse', JSON.stringify({
      surveyParts: surveyQuestions.length,
      coverage: parsed.coverage,
      missingQuestionKeys: parsed.missingQuestionKeys,
      duplicateBlockKeys: parsed.duplicateBlockKeys,
      selectedBlocks: selectedBlockKeys,
      affectedLengths,
      bodyKeyLines,
    }, null, 2));

    expect(surveyQuestions).toHaveLength(73);
    expect(parsed.coverage).toEqual({ individual: 48, grouped: 25, missing: 0 });
    expect(parsed.missingQuestionKeys).toEqual([]);
    expect(parsed.duplicateBlockKeys).toEqual([]);
    expect(bodyKeyLines.map(({ key }) => key)).toEqual(['72B']);
    expect(affectedLengths).toEqual([
      { questionId: '74', blockKey: 'question:74', textLength: 332 },
      { questionId: '75', blockKey: 'question:75', textLength: 384 },
    ]);
    expect(new Set(parsed.blocks.map((block) => block.blockKey)).size).toBe(parsed.blocks.length);
    expect(selectedBlockKeys).toEqual([
      { key: '40', blocks: [
        { blockKey: 'question:40', kind: 'question', hasText: true },
        { blockKey: 'definition:title', kind: 'definition', hasText: true },
      ] },
      { key: '60', blocks: [{ blockKey: 'question:60-a', kind: 'question', hasText: true }] },
      { key: '81', blocks: [
        { blockKey: 'question:81', kind: 'question', hasText: true },
        { blockKey: 'definition:interlibrary-loan-service', kind: 'definition', hasText: true },
      ] },
      { key: '91e', blocks: [{ blockKey: 'group:91a-d', kind: 'group', hasText: true }] },
      { key: '6C', blocks: [{ blockKey: 'group:1b-6b', kind: 'group', hasText: true }] },
    ]);
  });
});

function findBodyKeyLines(pages: string[], questions: Array<{ question_id: string; part: string }>): Array<{ key: string; page: number; line: number }> {
  const validKeys = new Set(questions.map((question) => `${question.question_id}${question.part}`.toUpperCase()));
  const matches: Array<{ key: string; page: number; line: number }> = [];
  pages.forEach((page, pageIndex) => {
    page.split(/\r?\n/).forEach((line, lineIndex) => {
      const match = line.match(/^\s*\*?\s*(\d{1,3}[A-Za-z]?)\s+([a-z][a-z-]*)\b/);
      if (match && validKeys.has(match[1].toUpperCase())) {
        matches.push({ key: match[1], page: pageIndex + 1, line: lineIndex + 1 });
      }
    });
  });
  return matches;
}

async function readPdfPages(filePath: string): Promise<string[]> {
  const data = new Uint8Array(await readFile(filePath));
  const loadingTask = getDocument({ data });
  const document = await loadingTask.promise;
  const pages: string[] = [];
  try {
    for (let pageNumber = 1; pageNumber <= document.numPages; pageNumber += 1) {
      const page = await document.getPage(pageNumber);
      const content = await page.getTextContent();
      pages.push(pdfItemsToText(content.items as PdfTextItem[]));
      page.cleanup();
    }
  } finally {
    await loadingTask.destroy();
  }
  return pages;
}
