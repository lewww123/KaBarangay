const DOCX_OPTIONS = {
    className: 'certificate-docx',
    inWrapper: true,
    hideWrapperOnPrint: true,
    ignoreWidth: false,
    ignoreHeight: false,
    ignoreFonts: false,
    renderHeaders: true,
    renderFooters: true,
    renderFootnotes: true,
    renderEndnotes: true,
    renderAltChunks: false,
    useBase64URL: true,
    experimental: true,
};

export async function renderCertificateDocument(blob: Blob, body: HTMLElement, styles: HTMLElement): Promise<void> {
    const { renderAsync } = await import('docx-preview');
    await renderAsync(await blob.arrayBuffer(), body, styles, DOCX_OPTIONS);
}

/** Ensure embedded seals and VML artwork are ready before a preview is printable. */
export async function waitForCertificateAssets(root: ParentNode): Promise<void> {
    await Promise.all(Array.from(root.querySelectorAll('img')).map(async (image) => {
        if (!image.complete) {
            await new Promise<void>((resolve, reject) => {
                image.onload = () => resolve();
                image.onerror = () => reject(new Error('A certificate image could not be loaded.'));
            });
        }

        if (image.naturalWidth === 0) {
            throw new Error('A certificate image could not be loaded.');
        }
    }));

    // Word may express watermark and logo drawings as SVG-backed VML images.
    const sources = new Set(Array.from(root.querySelectorAll<SVGImageElement>('svg image'))
        .map((image) => image.href?.baseVal || image.getAttribute('xlink:href') || '')
        .filter(Boolean));

    await Promise.all(Array.from(sources).map(async (source) => {
        const image = new Image();
        image.src = source;
        await image.decode();
    }));
}

export async function printCertificateDocument(blob: Blob, title: string): Promise<void> {
    const frame = document.createElement('iframe');
    frame.title = 'Certificate print view';
    frame.style.cssText = 'position:fixed;left:-10000px;top:0;width:210mm;height:297mm;border:0';
    document.body.appendChild(frame);

    try {
        const printWindow = frame.contentWindow;
        const printDocument = frame.contentDocument;
        if (!printWindow || !printDocument) {
            throw new Error('The certificate print view could not be opened.');
        }
        printDocument.open();
        printDocument.write('<!doctype html><html lang="en"><head><meta charset="UTF-8"></head><body></body></html>');
        printDocument.close();
        printDocument.title = title;
        await renderCertificateDocument(blob, printDocument.body, printDocument.head);

        const page = printDocument.querySelector<HTMLElement>('section.certificate-docx');
        const dimension = (value: string | undefined, fallback: string) =>
            value && /^\d+(?:\.\d+)?(?:pt|px|mm|cm|in)$/.test(value) ? value : fallback;
        const width = dimension(page?.style.width, '210mm');
        const height = dimension(page?.style.minHeight, '297mm');
        const style = printDocument.createElement('style');
        style.textContent = `
            @page { size: ${width} ${height}; margin: 0; }
            html, body { margin: 0 !important; padding: 0 !important; background: white; }
            *, *::before, *::after { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
            .certificate-docx-wrapper { background: white !important; padding: 0 !important; display: block !important; }
            .certificate-docx-wrapper > section.certificate-docx { margin: 0 !important; box-shadow: none !important; break-after: page; }
            .certificate-docx-wrapper > section.certificate-docx:last-child { break-after: auto; }
            @media print {
                html, body { width: auto; height: auto; overflow: visible !important; }
                .certificate-docx-wrapper > section.certificate-docx { break-inside: avoid; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            }
        `;
        printDocument.head.appendChild(style);
        await printDocument.fonts.ready;
        await waitForCertificateAssets(printDocument);
        printWindow.addEventListener('afterprint', () => frame.remove(), { once: true });
        printWindow.focus();
        printWindow.print();
    } catch (error) {
        frame.remove();
        throw error;
    }
}
