import { describe, expect, it } from 'vitest';
import { parseCsvRecords, serializeCsv } from './csv';

describe('serializeCsv', () => {
  it('adds a UTF-8 BOM only when requested', () => {
    expect(serializeCsv(['answer'], [{ answer: 'Yes' }])).toBe('answer\r\nYes');
    expect(serializeCsv(['answer'], [{ answer: 'Yes' }], { bom: true })).toBe('\uFEFFanswer\r\nYes');
  });
});

describe('parseCsvRecords', () => {
  it('parses UTF-8 CSV with escaped quotes, embedded newlines, and uneven rows', () => {
    const rows = parseCsvRecords('\uFEFFquestion_id,part,note,source\r\n40,,"Two titles, called ""sets""\nand continued",FY2024 notes\r\n60,A,physical use,FY2026 notes,extra\r\n');
    expect(rows).toEqual([
      { question_id: '40', part: '', note: 'Two titles, called "sets"\nand continued', source: 'FY2024 notes' },
      { question_id: '60', part: 'A', note: 'physical use', source: 'FY2026 notes' },
    ]);
  });

  it('rejects malformed quotes and duplicate or empty headers', () => {
    expect(() => parseCsvRecords('a,b\n1,"unfinished')).toThrow(/header and at least one data row/);
    expect(() => parseCsvRecords('a,a\n1,2')).toThrow(/headers/);
  });
});
