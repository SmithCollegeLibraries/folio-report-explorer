import { beforeEach, describe, expect, it, vi } from 'vitest';
import { extractPdfItemPages, extractPdfTextPages } from './acrlPdfPages';

const mocks = vi.hoisted(() => ({ getDocument: vi.fn() }));

vi.mock('pdfjs-dist', () => ({
  getDocument: mocks.getDocument,
  GlobalWorkerOptions: { workerSrc: '' },
}));
vi.mock('pdfjs-dist/build/pdf.worker.min.mjs?url', () => ({ default: 'worker.js' }));

describe('ACRL PDF page extraction', () => {
  const items = [
    { str: 'Second', transform: [1, 0, 0, 1, 120, 700] },
    { str: 'First', transform: [1, 0, 0, 1, 40, 700] },
  ];
  const page = { getTextContent: vi.fn(), cleanup: vi.fn() };
  const document = { numPages: 1, getPage: vi.fn() };
  const loadingTask = { promise: Promise.resolve(document), destroy: vi.fn() };

  beforeEach(() => {
    vi.clearAllMocks();
    document.numPages = 1;
    page.getTextContent.mockResolvedValue({ items });
    document.getPage.mockResolvedValue(page);
    mocks.getDocument.mockReturnValue(loadingTask);
  });

  it('keeps the text-only extraction API used by instruction parsing', async () => {
    const onPageProgress = vi.fn();
    const pages = await extractPdfTextPages(new File(['pdf'], 'instructions.pdf', { type: 'application/pdf' }), onPageProgress);

    expect(pages).toEqual(['First Second']);
    expect(onPageProgress).toHaveBeenCalledWith(1, 1);
    expect(page.cleanup).toHaveBeenCalledOnce();
    expect(loadingTask.destroy).toHaveBeenCalledOnce();
  });

  it('returns positioned PDF.js items for worksheet layout parsing', async () => {
    const pages = await extractPdfItemPages(new File(['pdf'], 'worksheet.pdf', { type: 'application/pdf' }));

    expect(pages).toEqual([items]);
    expect(loadingTask.destroy).toHaveBeenCalledOnce();
  });

  it('reports progress after each page is read', async () => {
    document.numPages = 3;
    const onPageProgress = vi.fn();

    await extractPdfItemPages(new File(['pdf'], 'worksheet.pdf', { type: 'application/pdf' }), onPageProgress);

    expect(onPageProgress.mock.calls).toEqual([[1, 3], [2, 3], [3, 3]]);
  });
});
