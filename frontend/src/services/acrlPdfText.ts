export interface PdfTextItem {
  str?: string;
  transform?: number[];
}

export interface PositionedPdfText {
  text: string;
  x: number;
  y: number;
  page?: number;
}

export function pdfItemsToPositionedText(items: PdfTextItem[]): PositionedPdfText[] {
  return items
    .filter((item) => typeof item.str === 'string' && item.str.trim())
    .map((item) => ({ text: item.str as string, x: item.transform?.[4] || 0, y: item.transform?.[5] || 0 }))
    .sort((left, right) => right.y - left.y || left.x - right.x);
}

export function pdfItemsToText(items: PdfTextItem[]): string {
  const positioned = pdfItemsToPositionedText(items);
  const lines: Array<{ y: number; parts: string[] }> = [];
  positioned.forEach((item) => {
    let line = lines[lines.length - 1];
    if (!line || Math.abs(line.y - item.y) > 3) {
      line = { y: item.y, parts: [] };
      lines.push(line);
    }
    line.parts.push(item.text);
  });
  return lines.map((line) => line.parts.join(' ').replace(/\s+/g, ' ').trim()).join('\n');
}
