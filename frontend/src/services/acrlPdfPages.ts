import { getDocument, GlobalWorkerOptions } from 'pdfjs-dist';
import pdfWorkerUrl from 'pdfjs-dist/build/pdf.worker.min.mjs?url';
import { type PdfTextItem } from './acrlPdfText';
import { pdfItemsToText } from './acrlPdfText';

export async function extractPdfItemPages(file: File): Promise<PdfTextItem[][]> {
  GlobalWorkerOptions.workerSrc = pdfWorkerUrl;
  const bytes = new Uint8Array(await file.arrayBuffer());
  const loadingTask = getDocument({ data: bytes });
  const document = await loadingTask.promise;
  const pages: PdfTextItem[][] = [];
  try {
    for (let pageNumber = 1; pageNumber <= document.numPages; pageNumber += 1) {
      const page = await document.getPage(pageNumber);
      const text = await page.getTextContent();
      pages.push(text.items as PdfTextItem[]);
      page.cleanup();
    }
  } finally {
    await loadingTask.destroy();
  }
  return pages;
}

export async function extractPdfTextPages(file: File): Promise<string[]> {
  return (await extractPdfItemPages(file)).map((items) => pdfItemsToText(items));
}
