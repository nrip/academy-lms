/**
 * In-lesson PDF viewing. No annotation, highlight, or edit tools.
 * The PDF is loaded from the authenticated media route, not a public file.
 */
import * as pdfjsLib from '/assets/vendor/pdfjs/pdf.min.mjs';

pdfjsLib.GlobalWorkerOptions.workerSrc = '/assets/vendor/pdfjs/pdf.worker.min.mjs';

const roots = document.querySelectorAll('[data-acad-pdf-viewer]');
roots.forEach((root) => {
  const src = root.getAttribute('data-acad-pdf-src');
  const canvas = root.querySelector('[data-acad-pdf-canvas]');
  const status = root.querySelector('[data-acad-pdf-status]');
  const error = root.querySelector('[data-acad-pdf-error]');
  const previous = root.querySelector('[data-acad-pdf-prev]');
  const next = root.querySelector('[data-acad-pdf-next]');
  if (src === null || !(canvas instanceof HTMLCanvasElement) || status === null) {
    return;
  }

  const task = pdfjsLib.getDocument({
    url: src,
    withCredentials: true,
    isEvalSupported: false,
    disableRange: true,
    disableStream: true,
    cMapUrl: '/assets/vendor/pdfjs/cmaps/',
    cMapPacked: true,
    standardFontDataUrl: '/assets/vendor/pdfjs/standard_fonts/',
  });

  task.promise.then(async (pdf) => {
    let pageNumber = 1;

    const render = async () => {
      const page = await pdf.getPage(pageNumber);
      const width = root.clientWidth > 0 ? root.clientWidth - 32 : 720;
      const unscaled = page.getViewport({ scale: 1 });
      const scale = Math.min(1.5, width / unscaled.width);
      const viewport = page.getViewport({ scale });
      canvas.width = viewport.width;
      canvas.height = viewport.height;
      const context = canvas.getContext('2d');
      if (context === null) {
        return;
      }
      await page.render({
        canvasContext: context,
        viewport,
        annotationMode: pdfjsLib.AnnotationMode.DISABLE,
      }).promise;
      status.textContent = 'Page ' + pageNumber + ' of ' + pdf.numPages;
      if (previous instanceof HTMLButtonElement) {
        previous.disabled = pageNumber <= 1;
      }
      if (next instanceof HTMLButtonElement) {
        next.disabled = pageNumber >= pdf.numPages;
      }
    };

    if (previous instanceof HTMLButtonElement) {
      previous.addEventListener('click', () => {
        if (pageNumber > 1) {
          pageNumber -= 1;
          render();
        }
      });
    }
    if (next instanceof HTMLButtonElement) {
      next.addEventListener('click', () => {
        if (pageNumber < pdf.numPages) {
          pageNumber += 1;
          render();
        }
      });
    }
    await render();
  }).catch(() => {
    status.textContent = 'Unavailable';
    if (error instanceof HTMLElement) {
      error.hidden = false;
    }
  });
});
