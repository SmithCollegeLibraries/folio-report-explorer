import { describe, expect, it } from 'vitest';
import type { AcrlEntryQuestion, AcrlEntryResponse, AcrlEntrySection } from '../types/acrl';
import { serializeCsv } from '../utils/csv';
import { acrlBenchmarkColumns, buildAcrlBenchmarkRows } from './acrlBenchmarkExport';

function question(overrides: Partial<AcrlEntryQuestion> = {}): AcrlEntryQuestion {
  return {
    sheet: 'survey', questionId: '1', part: '', section: 'Circulation', prompt: 'Survey question', answerType: 'text',
    required: 'no', calculatedFrom: null, surveyOrder: 1, hasDetailsNotes: false,
    answer: { value_text: null, value_number: null, note: null, source: null, source_label: null, updated_by: null, updated_at: null },
    previous: { value: null, label: 'No previous value', isSum: false, linkedValues: [] },
    history: [], suggestion: null,
    comparison: { previousValue: null, previousLabel: 'No previous value', changePercent: null, highlighted: false, addNote: false },
    calculated: null,
    ...overrides,
  };
}

function section(name: string, questions: AcrlEntryQuestion[]): AcrlEntrySection {
  return { name, status: 'open', setAt: null, reopenedAt: null, filled: 0, total: questions.length, assignees: [], assignedToCurrentUser: false, questions };
}

function entry(sections: AcrlEntrySection[], previousYear = 2025): AcrlEntryResponse {
  return { selectedYear: 2026, previousYear, years: [2026, 2025], thresholdPercent: 10, sections };
}

describe('buildAcrlBenchmarkRows', () => {
  it('exports every part in Survey order followed by Trends, preserving page order for ties', () => {
    const rows = buildAcrlBenchmarkRows(entry([
      section('Circulation', [
        question({ questionId: '60', part: 'B', prompt: 'Digital circulation', surveyOrder: 2 }),
        question({ questionId: '40', prompt: 'Physical titles', surveyOrder: 1 }),
        question({ questionId: '40', part: 'A', prompt: 'Physical volumes', surveyOrder: 1 }),
        question({ sheet: 'trends', questionId: 'T1', prompt: 'AI usage', surveyOrder: 1 }),
      ]),
      section('Collections', [question({ questionId: '41', prompt: 'Collection count', surveyOrder: 1 })]),
    ]));

    expect(rows.map(({ sheet, section: sectionName, question_id, part, value }) => [sheet, sectionName, question_id, part, value])).toEqual([
      ['survey', 'Circulation', '40', '', ''],
      ['survey', 'Circulation', '40', 'A', ''],
      ['survey', 'Collections', '41', '', ''],
      ['survey', 'Circulation', '60', 'B', ''],
      ['trends', 'Circulation', 'T1', '', ''],
    ]);
  });

  it('formats number, currency, yes/no, choice, grid, and text answers for Benchmark', () => {
    const rows = buildAcrlBenchmarkRows(entry([section('Answers', [
      question({ questionId: '1', prompt: 'Annual transactions', answerType: 'number', answer: { value_text: '-25', value_number: '-25', note: null, source: 'manual', source_label: null, updated_by: null, updated_at: null } }),
      question({ questionId: '2', prompt: 'Total salaries', answerType: 'currency', answer: { value_text: '1234.50', value_number: '1234.50', note: null, source: 'manual', source_label: null, updated_by: null, updated_at: null } }),
      question({ questionId: '3', prompt: 'Uses remote service?', answerType: 'yes/no', answer: { value_text: 'no', value_number: null, note: null, source: 'manual', source_label: null, updated_by: null, updated_at: null } }),
      question({ questionId: '4', prompt: 'AI use level', answerType: 'choice', answer: { value_text: '{"value":"Higher"}', value_number: null, note: null, source: 'manual', source_label: null, updated_by: null, updated_at: null }, answerDisplay: 'Higher' }),
      question({ questionId: '5', prompt: 'Digital services', answerType: 'choice', answerOptions: { kind: 'grid', rows: ['Access', 'Training'], choices: ['Yes', 'No'], allowOther: false }, answer: { value_text: '{"Access":"Yes","Training":"No"}', value_number: null, note: null, source: 'manual', source_label: null, updated_by: null, updated_at: null }, answerDisplay: 'Access: Yes; Training: No' }),
      question({ questionId: '6', prompt: 'Other answer', answerType: 'text', answer: { value_text: 'As entered', value_number: null, note: null, source: 'manual', source_label: null, updated_by: null, updated_at: null }, answerDisplay: 'Display must not replace the text' }),
      question({ questionId: '7', answerType: 'text', answer: { value_text: '-25', value_number: null, note: null, source: 'manual', source_label: null, updated_by: null, updated_at: null } }),
    ])]));

    expect(acrlBenchmarkColumns).toEqual(['sheet', 'section', 'question_id', 'part', 'question', 'value', 'previous_year', 'previous_value', 'change_percent', 'note']);
    expect(rows.map((row) => row.value)).toEqual(['-25', '1234.5', 'No', 'Higher', 'Access: Yes; Training: No', 'As entered', "'-25"]);
    expect(serializeCsv([...acrlBenchmarkColumns], rows.slice(0, 6))).toBe([
      'sheet,section,question_id,part,question,value,previous_year,previous_value,change_percent,note',
      'survey,Answers,1,,Annual transactions,-25,2025,,,',
      'survey,Answers,2,,Total salaries,1234.5,2025,,,',
      'survey,Answers,3,,Uses remote service?,No,2025,,,',
      'survey,Answers,4,,AI use level,Higher,2025,,,',
      'survey,Answers,5,,Digital services,Access: Yes; Training: No,2025,,,',
      'survey,Answers,6,,Other answer,As entered,2025,,,',
    ].join('\r\n'));
  });

  it('removes only trailing decimal zeros from number and currency values and prior values', () => {
    const decimalExamples = [
      { raw: '4.00', expected: '4', answerType: 'number' as const },
      { raw: '296.00', expected: '296', answerType: 'number' as const },
      { raw: '3083163.00', expected: '3083163', answerType: 'number' as const },
      { raw: '33.40', expected: '33.4', answerType: 'currency' as const },
      { raw: '1234.50', expected: '1234.5', answerType: 'currency' as const },
      { raw: '-25.00', expected: '-25', answerType: 'number' as const },
      { raw: '0.00', expected: '0', answerType: 'number' as const },
    ];
    const rows = buildAcrlBenchmarkRows(entry([section('Decimals', decimalExamples.map(({ raw, answerType }, index) => question({
      questionId: String(index + 1),
      answerType,
      answer: { value_text: raw, value_number: raw, note: null, source: 'manual', source_label: null, updated_by: null, updated_at: null },
      comparison: { previousValue: raw, previousLabel: raw, changePercent: null, highlighted: false, addNote: false },
    })))]));

    expect(rows.map(({ value, previous_value }) => [value, previous_value])).toEqual(
      decimalExamples.map(({ expected }) => [expected, expected]),
    );
  });

  it('keeps non-numeric text stored for a number question unchanged', () => {
    const rows = buildAcrlBenchmarkRows(entry([section('Answers', [question({
      answerType: 'number',
      answer: { value_text: 'not numeric', value_number: null, note: null, source: 'manual', source_label: null, updated_by: null, updated_at: null },
    })])]));

    expect(rows[0].value).toBe('not numeric');
  });

  it('includes prior-year fields, rounds change to one decimal, and leaves unavailable values blank', () => {
    const rows = buildAcrlBenchmarkRows(entry([section('Answers', [
      question({ questionId: '1', answer: { value_text: '15', value_number: '15', note: 'Checked against report', source: 'manual', source_label: null, updated_by: null, updated_at: null }, comparison: { previousValue: 12, previousLabel: '12', changePercent: 12.36, highlighted: true, addNote: false } }),
      question({ questionId: '2' }),
    ])]));

    expect(rows[0]).toMatchObject({ previous_year: 2025, previous_value: 12, change_percent: 12.4, note: 'Checked against report' });
    expect(rows[1]).toMatchObject({ previous_year: 2025, previous_value: '', change_percent: '', note: '' });
    expect(buildAcrlBenchmarkRows(entry([section('First year', [question()])], 0))[0]).toMatchObject({ previous_year: '', previous_value: '', change_percent: '' });
  });

  it('prefixes formula-like text cells while preserving real negative numbers', () => {
    const rows = buildAcrlBenchmarkRows(entry([section('=SUM(A1:A2)', [
      question({ questionId: '=1+1', prompt: '@HYPERLINK("bad")', answer: { value_text: '=2+2', value_number: null, note: '+SUM(A1:A2)', source: 'manual', source_label: null, updated_by: null, updated_at: null }, comparison: { previousValue: '-5+1', previousLabel: '', changePercent: null, highlighted: false, addNote: false } }),
      question({ questionId: '2', answerType: 'number', answer: { value_text: '-25', value_number: '-25', note: null, source: 'manual', source_label: null, updated_by: null, updated_at: null }, comparison: { previousValue: -30, previousLabel: '', changePercent: null, highlighted: false, addNote: false } }),
    ])]));

    expect(rows[0]).toMatchObject({ section: "'=SUM(A1:A2)", question_id: "'=1+1", question: "'@HYPERLINK(\"bad\")", value: "'=2+2", previous_value: "'-5+1", note: "'+SUM(A1:A2)" });
    expect(rows[1]).toMatchObject({ value: '-25', previous_value: -30 });
  });
});
