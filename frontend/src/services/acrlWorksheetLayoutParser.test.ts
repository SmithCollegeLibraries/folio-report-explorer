import { describe, expect, it } from 'vitest';
import { parseWorksheetPositionedPages } from './acrlWorksheetParser';

function item(str: string, x: number, y: number) {
  return { str, transform: [1, 0, 0, 1, x, y] };
}

function trendsPages() {
  return [
    [
      item('2026 Trends: Academic and Intellectual Freedom', 54, 700),
      item('1. Compared to three years ago, concerns are:', 54, 650),
      item('o', 72, 620), item('Much higher', 90, 620),
      item('o', 72, 600), item('Higher', 90, 600),
      item('o', 72, 580), item('About the same', 90, 580),
      item('o', 72, 560), item('Lower', 90, 560),
      item('o', 72, 540), item('Much lower', 90, 540),
      item('2. Please describe what changed:', 54, 500),
      item('3. Which policies apply? (check all that apply)', 54, 450),
      item('☐', 72, 420), item('Institution has a policy', 90, 420),
      item('☐', 72, 400), item('Library has a policy', 90, 400),
      item('☐', 72, 380), item('A shared policy applies', 90, 380),
      item('☐', 72, 360), item('No policy', 90, 360),
      item('☐', 72, 340), item('Other (please specify)', 90, 340),
      item('4. What is the impact?', 54, 310),
      item('o', 72, 280), item('High', 90, 280),
      item('o', 72, 260), item('Moderate', 90, 260),
      item('o', 72, 240), item('Some', 90, 240),
      item('o', 72, 220), item('None', 90, 220),
      item('o', 72, 200), item('Don’t know', 90, 200),
      item('5. Which areas were affected?', 54, 170),
      item('Yes', 375, 150), item('No', 442, 150), item('Don’t know', 488, 150),
      item('〇', 379, 120), item('〇', 444, 120), item('〇', 510, 120), item('Grants', 82, 126),
      item('〇', 379, 90), item('〇', 444, 90), item('〇', 510, 90), item('Instructional materials', 82, 96),
      item('〇', 379, 60), item('〇', 444, 60), item('〇', 510, 60), item('Other (please specify):', 82, 66),
      item('6. Which strategies were used?', 54, 30),
      item('Yes', 375, 12), item('No', 442, 12), item('Don’t know', 488, 12),
      item('〇', 379, -18), item('〇', 444, -18), item('〇', 510, -18), item('Policy changes', 82, -12),
    ],
    [
      item('〇', 379, 680), item('〇', 444, 680), item('〇', 510, 680), item('Training', 82, 686),
      item('Other (please specify):', 82, 650),
      item('7. Please share anything else:', 54, 600),
      item('8. Which sources applied?', 54, 550),
      item('Yes', 424, 520), item('No', 509, 520),
      item('〇', 428, 494), item('〇', 511, 494), item('Community groups', 77, 500),
      item('〇', 428, 468), item('〇', 511, 468), item('Donors', 77, 474),
      item('9. What resources or support would be most beneficial?', 54, 420),
    ],
  ];
}

describe('parseWorksheetPositionedPages', () => {
  it('detects rating, text, checkbox, radio, and row-by-row grid layouts by position', () => {
    const parsed = parseWorksheetPositionedPages(trendsPages());
    const trends = new Map(parsed.items.filter((item) => item.sheet === 'trends').map((item) => [item.question_id, item]));

    expect(trends.get('T1')).toMatchObject({ answer_type: 'choice', answer_options: { kind: 'rating', choices: ['Much higher', 'Higher', 'About the same', 'Lower', 'Much lower'] } });
    expect(trends.get('T2')).toMatchObject({ answer_type: 'text', answer_options: { kind: 'text', rows: [], choices: [], allowOther: false } });
    expect(trends.get('T3')).toMatchObject({ answer_type: 'choice', answer_options: { kind: 'checkbox', choices: ['Institution has a policy', 'Library has a policy', 'A shared policy applies', 'No policy', 'Other (please specify)'], allowOther: true } });
    expect(trends.get('T4')).toMatchObject({ answer_type: 'choice', answer_options: { kind: 'radio', choices: ['High', 'Moderate', 'Some', 'None', 'Don’t know'] } });
    expect(trends.get('T5')?.answer_options).toMatchObject({ kind: 'grid', rows: ['Grants', 'Instructional materials', 'Other (please specify):'], choices: ['Yes', 'No', 'Don’t know'], allowOther: true });
    expect(trends.get('T6')?.answer_options).toMatchObject({ kind: 'grid', rows: ['Policy changes', 'Training', 'Other (please specify):'], choices: ['Yes', 'No', 'Don’t know'], allowOther: true });
    expect(trends.get('T7')).toMatchObject({ answer_type: 'text', answer_options: { kind: 'text' } });
    expect(trends.get('T8')?.answer_options).toMatchObject({ kind: 'grid', rows: ['Community groups', 'Donors'], choices: ['Yes', 'No'], allowOther: false });
    expect(trends.get('T9')).toMatchObject({ answer_type: 'text', answer_options: { kind: 'text' } });
    expect(parsed.layoutWarnings).toEqual([]);
  });

  it('falls back to free text and reports Trends questions whose controls are unknown', () => {
    const parsed = parseWorksheetPositionedPages([[
      item('2026 Trends: Example', 54, 700),
      item('1. Choose a response:', 54, 650),
      item('Unexpected mark', 90, 620),
    ]]);

    expect(parsed.items[0]).toMatchObject({
      answer_type: 'text',
      answer_options: { kind: 'text', rows: [], choices: [], allowOther: false },
    });
    expect(parsed.layoutWarnings).toEqual(['T1: answer layout not recognized; using free text.']);
  });
});
