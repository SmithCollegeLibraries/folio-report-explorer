// @vitest-environment node
import { existsSync, readFileSync } from 'node:fs';
import { join } from 'node:path';
import { getDocument } from 'pdfjs-dist/legacy/build/pdf.mjs';
import { describe, expect, it } from 'vitest';
import { parseWorksheetPositionedPages } from './acrlWorksheetParser';

const realPdfDirectory = process.env.ACRL_REAL_PDF_DIR || '';
const worksheetPath = join(realPdfDirectory, '2026_ACRL_Survey_Worksheet.pdf');

describe('2026 ACRL worksheet layouts (local PDF only)', () => {
  it.skipIf(!realPdfDirectory)('detects the real Trends question layouts and answer counts', async () => {
    if (!existsSync(worksheetPath)) throw new Error('ACRL_REAL_PDF_DIR does not contain the worksheet PDF.');
    const loadingTask = getDocument({ data: new Uint8Array(readFileSync(worksheetPath)) });
    const document = await loadingTask.promise;
    const pages: Array<Array<{ str?: string; transform?: number[] }>> = [];
    try {
      for (let pageNumber = 1; pageNumber <= document.numPages; pageNumber += 1) {
        const page = await document.getPage(pageNumber);
        const content = await page.getTextContent();
        pages.push(content.items as Array<{ str?: string; transform?: number[] }>);
        page.cleanup();
      }
    } finally {
      await loadingTask.destroy();
    }

    const parsed = parseWorksheetPositionedPages(pages);
    const questions = new Map(parsed.items.filter((item) => item.sheet === 'trends').map((item) => [item.question_id, item]));
    const summary = Array.from({ length: 9 }, (_, index) => {
      const id = `T${index + 1}`;
      const options = questions.get(id)?.answer_options;
      return { id, kind: options?.kind, choices: options?.choices.length || 0, rows: options?.rows.length || 0, allowOther: options?.allowOther || false };
    });
    console.log(`ACRL worksheet layout counts: ${JSON.stringify(summary)}`);

    expect(parsed.layoutWarnings).toEqual([]);
    expect(questions.size).toBe(9);
    expect(summary).toEqual([
      { id: 'T1', kind: 'rating', choices: 5, rows: 0, allowOther: false },
      { id: 'T2', kind: 'text', choices: 0, rows: 0, allowOther: false },
      { id: 'T3', kind: 'checkbox', choices: 5, rows: 0, allowOther: false },
      { id: 'T4', kind: 'radio', choices: 5, rows: 0, allowOther: false },
      { id: 'T5', kind: 'grid', choices: 3, rows: 7, allowOther: true },
      { id: 'T6', kind: 'grid', choices: 3, rows: 6, allowOther: true },
      { id: 'T7', kind: 'text', choices: 0, rows: 0, allowOther: false },
      { id: 'T8', kind: 'grid', choices: 2, rows: 8, allowOther: false },
      { id: 'T9', kind: 'text', choices: 0, rows: 0, allowOther: false },
    ]);
  });
});
