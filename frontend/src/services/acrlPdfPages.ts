import { getDocument, GlobalWorkerOptions } from 'pdfjs-dist';
import pdfWorkerUrl from 'pdfjs-dist/build/pdf.worker.min.mjs?url';
import { pdfItemsToText, type PdfTextItem } from './acrlPdfText';

export async function extractPdfTextPages(file: File): Promise<string[]> {
  GlobalWorkerOptions.workerSrc = pdfWorkerUrl;
  const bytes = new Uint8Array(await file.arrayBuffer());
  const loadingTask = getDocument({ data: bytes });
  const document = await loadingTask.promise;
  const pages: string[] = [];
  try {
    for (let pageNumber = 1; pageNumber <= document.numPages; pageNumber += 1) {
      const page = await document.getPage(pageNumber);
      const text = await page.getTextContent();
      pages.push(pdfItemsToText(text.items as PdfTextItem[]));
      page.cleanup();
    }
  } finally {
    await loadingTask.destroy();
  }
  return pages;
}
