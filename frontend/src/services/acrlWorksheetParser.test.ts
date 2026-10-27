import { describe, expect, it } from 'vitest';
import { parseWorksheetPages } from './acrlWorksheetParser';

describe('parseWorksheetPages', () => {
  it('normalizes PDF part labels while preserving standalone question identifiers', () => {
    const parsed = parseWorksheetPages([
      `2026 ACRL SURVEY WORKSHEET
Library characteristics
*0A Does the data you are reporting include a
law library?
Staffing
*1A Librarians # of FTEs
1B Librarians salaries and wages (excluding fringe)
*6C Total salaries and wages if unable to report separately
Library collections
*40 Physical Books (Title Count)
*40B Books Digital/Electronic (Title Count)
40a Physical Books (Volume Count)
*61A Total E-book & E-media Usage
Library services
72B Number of Asynchronous Presentations
Comments
101 Use this space to include any additional, non-measure-specific information.`
    ]);

    expect(parsed.unresolved).toEqual([]);
    expect(parsed.items).toHaveLength(10);
    expect(parsed.items[0]).toMatchObject({
      sheet: 'survey', question_id: '0A', part: '', section: 'Library characteristics',
      prompt: 'Does the data you are reporting include a law library?', required: 'yes',
    });
    expect(parsed.items[1]).toMatchObject({ question_id: '1', part: 'A', required: 'yes', answer_type: 'number' });
    expect(parsed.items[2]).toMatchObject({ question_id: '1', part: 'B', required: 'no', answer_type: 'currency' });
    expect(parsed.items[3]).toMatchObject({ question_id: '6', part: 'C', required: 'yes', answer_type: 'currency' });
    expect(parsed.items[4]).toMatchObject({ question_id: '40', part: 'A' });
    expect(parsed.items[5]).toMatchObject({ question_id: '40', part: 'B' });
    expect(parsed.items[6]).toMatchObject({ question_id: '40a', part: '' });
    expect(parsed.items[7]).toMatchObject({ question_id: '61A', part: '' });
    expect(parsed.items[8]).toMatchObject({ question_id: '72', part: 'B' });
    expect(parsed.items[9]).toMatchObject({ question_id: '101', part: '', answer_type: 'text' });
  });

  it('keeps calculated-from text and marks Trends prompts as topical', () => {
    const parsed = parseWorksheetPages([
      `Staffing
3A Total professional staff # of FTEs
Calculated sum of 1A + 2A
Trends
T1 Compared to three years ago, concerns about academic freedom are: Much higher / Same / Much lower
T2 Please describe how those concerns have changed.`
    ]);

    expect(parsed.items[0].calculated_from).toBe('sum of 1A, 2A');
    expect(parsed.items[1]).toMatchObject({ sheet: 'trends', question_id: 'T1', section: 'Trends', answer_type: 'choice' });
    expect(parsed.items[2]).toMatchObject({ sheet: 'trends', question_id: 'T2', answer_type: 'text' });
  });

  it('normalizes worksheet sums, resolves same-part numeric references, and flags missing IDs', () => {
    const parsed = parseWorksheetPages([
      `Staffing
1A Librarians FTE
2A Other professional FTE
3A Total professional staff FTE
Calculated sum of questions 1A and 2A.
40A Physical count
42A Other count
43A Other count
44A Total count
Sum of questions 40, 42A, and 43A.
50 Total staff
Sum of 1A, 99 and 2A.`
    ]);

    expect(parsed.items.find((item) => item.question_id === '3' && item.part === 'A')?.calculated_from).toBe('sum of 1A, 2A');
    expect(parsed.items.find((item) => item.question_id === '44' && item.part === 'A')?.calculated_from).toBe('sum of 40A, 42A, 43A');
    expect(parsed.items.find((item) => item.question_id === '50')?.calculated_from).toBe('sum of 1A, 99, 2A');
    expect(parsed.unresolvedCalculations).toEqual(['50: 99']);
  });

  it('resolves a bare calculation reference to the only part when the total has no part', () => {
    const parsed = parseWorksheetPages([
      `Library services
*60 Total Physical Circulation
62 Total use
Sum of questions 60.`
    ]);

    expect(parsed.items.find((item) => item.question_id === '62')?.calculated_from).toBe('sum of 60A');
    expect(parsed.unresolvedCalculations).toEqual([]);
  });

  it('keeps a bare calculation reference unresolved when several parts are possible', () => {
    const parsed = parseWorksheetPages([
      `Library services
60 Physical circulation
60 Digital circulation
62 Total use
Sum of questions 60.`
    ]);

    expect(parsed.items.find((item) => item.question_id === '62')?.calculated_from).toBe('sum of 60');
    expect(parsed.unresolvedCalculations).toEqual(['62: 60']);
  });

  it('does not interpret explanatory calculation prose as a rule', () => {
    const parsed = parseWorksheetPages([
      `Expenses
20 Materials cost
This sum total is reported after review.
21 Other cost
Calculated by IPEDS form.`
    ]);

    expect(parsed.items[0].calculated_from).toBeNull();
    expect(parsed.items[1].calculated_from).toBeNull();
    expect(parsed.items[1].prompt).toContain('Calculated by IPEDS form.');
  });

  it('flags a malformed sum rule for review instead of treating it as prompt text', () => {
    const parsed = parseWorksheetPages(['Staffing\n3A Total professional staff FTE\nSum of 1A; 2A']);

    expect(parsed.items[0].calculated_from).toBeNull();
    expect(parsed.unresolvedCalculations).toEqual(['3A: unparsed sum rule']);
  });

  it('ignores dated page furniture, keeps hyphenated prompt text, and recognizes titled Trends sections', () => {
    const parsed = parseWorksheetPages([
      `2026 Academic Library Trends and Statistics Survey
1
Library services
90 Student Full-Time Equivalents (FTE)
12-Month Enrollment survey component.
3
2026 Trends: Academic and Intellectual Freedom The 2026 Trends are designed to create a snapshot.
1 Compared to three years ago, concerns are:
2 Please describe how those concerns have changed.`
    ]);

    expect(parsed.unresolved).toEqual([]);
    expect(parsed.items).toHaveLength(3);
    expect(parsed.items[0]).toMatchObject({
      sheet: 'survey', question_id: '90', prompt: 'Student Full-Time Equivalents (FTE) 12-Month Enrollment survey component.',
    });
    expect(parsed.items.slice(1)).toEqual(expect.arrayContaining([
      expect.objectContaining({ sheet: 'trends', question_id: 'T1', section: 'Trends' }),
      expect.objectContaining({ sheet: 'trends', question_id: 'T2', section: 'Trends' }),
    ]));
  });

  it('stops a prompt at notes and subsection headings', () => {
    const parsed = parseWorksheetPages([
      `Staffing
6A Total Staff FTEs
Report all full-time-equivalent staff.
Notes – Staffing Expenses
Add significant measure-specific notes here.
Staffing Expenses
Expenses (Exclude Staff)
Report expenses for the most recent fiscal year.
Materials Expenses
20 One-time purchase of books and other materials`
    ]);

    expect(parsed.items).toHaveLength(2);
    expect(parsed.items[0].prompt).toBe('Total Staff FTEs Report all full-time-equivalent staff.');
    expect(parsed.items[1].prompt).toBe('One-time purchase of books and other materials');
  });

  it('maps worksheet heading variants to their canonical sections', () => {
    const parsed = parseWorksheetPages([
      `Library characteristics
0B Does the data include a medical library?
Branch/Independent Libraries
68 Indicate the number of branch and independent libraries.
Staffing Types, FTEs, and Expenses
1A Librarians # of FTEs
Local Characteristics & Enrollment
91e Total Headcount`
    ]);

    expect(parsed.items.map(({ question_id, section }) => ({ question_id, section }))).toEqual([
      { question_id: '0B', section: 'Library characteristics' },
      { question_id: '68', section: 'Library characteristics' },
      { question_id: '1', section: 'Staffing' },
      { question_id: '91e', section: 'Local characteristics and enrollment' },
    ]);
  });

  it('reports malformed numbered lines that cannot be placed', () => {
    const parsed = parseWorksheetPages(['Staffing\n*?? no question number\n*2A Other professional staff # of FTEs']);

    expect(parsed.unresolved).toEqual(['*?? no question number']);
    expect(parsed.items).toHaveLength(1);
    expect(parsed.items[0]).toMatchObject({ question_id: '2', part: 'A' });
  });
});
