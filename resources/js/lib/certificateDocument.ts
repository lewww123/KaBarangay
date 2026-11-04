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

/** Capture the reviewed page once; print and PDF use these same pixels. */
export async function captureCertificateOutput(
    sheet: HTMLElement,
    title: string,
) {
    await document.fonts.ready;
    await waitForCertificateAssets(sheet);
    const [{ toPng }, { jsPDF }] = await Promise.all([
        import('html-to-image'),
        import('jspdf'),
    ]);
    const image = await toPng(sheet, {
        pixelRatio: 3,
        width: sheet.offsetWidth,
        height: sheet.offsetHeight,
        backgroundColor: '#ffffff',
        style: {
            position: 'relative',
            left: '0',
            top: '0',
            margin: '0',
            transform: 'none',
            boxShadow: 'none',
            width: `${sheet.offsetWidth}px`,
            height: `${sheet.offsetHeight}px`,
        },
    });
    const pdf = new jsPDF({
        orientation: 'portrait',
        unit: 'mm',
        format: 'a4',
        compress: true,
    });
    pdf.setProperties({ title });
    pdf.addImage(image, 'PNG', 0, 0, 210, 297);
    return { image, pdf: pdf.output('blob') };
}

export async function printCertificateImage(
    source: string,
    title: string,
): Promise<void> {
    const frame = document.createElement('iframe');
    frame.title = 'Certificate print view';
    frame.style.cssText =
        'position:fixed;left:-10000px;top:0;width:210mm;height:297mm;border:0';
    document.body.appendChild(frame);
    try {
        const target = frame.contentDocument;
        const printWindow = frame.contentWindow;
        if (!target || !printWindow)
            throw new Error('The print view could not be opened.');
        target.open();
        target.write(
            '<!doctype html><html><head><style>@page{size:A4;margin:0}html,body{margin:0;padding:0;width:210mm;height:297mm}img{display:block;width:210mm;height:297mm;print-color-adjust:exact;-webkit-print-color-adjust:exact}</style></head><body></body></html>',
        );
        target.close();
        target.title = title;
        const image = target.createElement('img');
        image.alt = title;
        image.src = source;
        target.body.appendChild(image);
        await image.decode();
        printWindow.addEventListener('afterprint', () => frame.remove(), {
            once: true,
        });
        printWindow.focus();
        printWindow.print();
    } catch (error) {
        frame.remove();
        throw error;
    }
}

/** Historical PDF certificates retain the reviewed page rather than rebuilding Word. */
export async function printCertificatePdf(
    blob: Blob,
    title: string,
): Promise<void> {
    const source = URL.createObjectURL(blob);
    const frame = document.createElement('iframe');
    frame.title = title;
    frame.style.cssText =
        'position:fixed;left:-10000px;top:0;width:210mm;height:297mm;border:0';
    const cleanup = () => {
        frame.remove();
        URL.revokeObjectURL(source);
    };
    frame.onload = () => {
        frame.contentWindow?.addEventListener('afterprint', cleanup, {
            once: true,
        });
        frame.contentWindow?.focus();
        frame.contentWindow?.print();
    };
    frame.onerror = cleanup;
    frame.src = source;
    document.body.appendChild(frame);
}

export async function renderCertificateDocument(
    blob: Blob,
    body: HTMLElement,
    styles: HTMLElement,
): Promise<void> {
    const { renderAsync } = await import('docx-preview');
    await renderAsync(await blob.arrayBuffer(), body, styles, DOCX_OPTIONS);
}

/** Ensure embedded seals and VML artwork are ready before a preview is printable. */
export async function waitForCertificateAssets(
    root: ParentNode,
): Promise<void> {
    await Promise.all(
        Array.from(root.querySelectorAll('img')).map(async (image) => {
            if (!image.complete) {
                await new Promise<void>((resolve, reject) => {
                    image.onload = () => resolve();
                    image.onerror = () =>
                        reject(
                            new Error(
                                'A certificate image could not be loaded.',
                            ),
                        );
                });
            }

            if (image.naturalWidth === 0) {
                throw new Error('A certificate image could not be loaded.');
            }
        }),
    );

    // Word may express watermark and logo drawings as SVG-backed VML images.
    const sources = new Set(
        Array.from(root.querySelectorAll<SVGImageElement>('svg image'))
            .map(
                (image) =>
                    image.href?.baseVal ||
                    image.getAttribute('xlink:href') ||
                    '',
            )
            .filter(Boolean),
    );

    await Promise.all(
        Array.from(sources).map(async (source) => {
            const image = new Image();
            image.src = source;
            await image.decode();
        }),
    );
}

/**
 * Print an already-rendered certificate sheet in an isolated frame.
 *
 * The sheet carries its own DOCX-rendered styles and the branded print rules,
 * so only the host stylesheets need to be copied for Tailwind utilities to
 * resolve inside the frame.
 */
export async function printBrandedCertificate(
    sheet: HTMLElement,
    title: string,
): Promise<void> {
    const frame = document.createElement('iframe');
    frame.title = 'Certificate print view';
    frame.style.cssText =
        'position:fixed;left:-10000px;top:0;width:210mm;height:297mm;border:0';
    document.body.appendChild(frame);

    try {
        const printWindow = frame.contentWindow;
        const printDocument = frame.contentDocument;
        if (!printWindow || !printDocument) {
            throw new Error('The certificate print view could not be opened.');
        }

        printDocument.open();
        printDocument.write(
            '<!doctype html><html lang="en"><head><meta charset="UTF-8"></head><body></body></html>',
        );
        printDocument.close();
        printDocument.title = title;

        const base = printDocument.createElement('base');
        base.href = document.baseURI;
        printDocument.head.appendChild(base);

        document
            .querySelectorAll<HTMLElement>('link[rel="stylesheet"], style')
            .forEach((node) => {
                printDocument.head.appendChild(node.cloneNode(true));
            });

        const style = printDocument.createElement('style');
        style.textContent = `
            @page { size: A4; margin: 0; }
            html, body { margin: 0 !important; padding: 0 !important; background: white; }
            *, *::before, *::after { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
            .certificate-print-sheet { position: relative !important; width: 210mm !important; height: 297mm !important; min-height: 297mm !important; margin: 0 auto !important; overflow: hidden !important; box-shadow: none !important; }
            #certificate-print-sheet { transform: none !important; transform-origin: initial !important; }
            .certificate-shell-art { display: block !important; }
        `;
        printDocument.head.appendChild(style);

        printDocument.body.appendChild(sheet.cloneNode(true));

        await printDocument.fonts.ready;
        await waitForCertificateAssets(printDocument);

        printWindow.addEventListener('afterprint', () => frame.remove(), {
            once: true,
        });
        printWindow.focus();
        printWindow.print();
    } catch (error) {
        frame.remove();
        throw error;
    }
}

export async function printCertificateDocument(
    blob: Blob,
    title: string,
): Promise<void> {
    const frame = document.createElement('iframe');
    frame.title = 'Certificate print view';
    frame.style.cssText =
        'position:fixed;left:-10000px;top:0;width:210mm;height:297mm;border:0';
    document.body.appendChild(frame);

    try {
        const printWindow = frame.contentWindow;
        const printDocument = frame.contentDocument;
        if (!printWindow || !printDocument) {
            throw new Error('The certificate print view could not be opened.');
        }
        printDocument.open();
        printDocument.write(
            '<!doctype html><html lang="en"><head><meta charset="UTF-8"></head><body></body></html>',
        );
        printDocument.close();
        printDocument.title = title;
        await renderCertificateDocument(
            blob,
            printDocument.body,
            printDocument.head,
        );

        const page = printDocument.querySelector<HTMLElement>(
            'section.certificate-docx',
        );
        const dimension = (value: string | undefined, fallback: string) =>
            value && /^\d+(?:\.\d+)?(?:pt|px|mm|cm|in)$/.test(value)
                ? value
                : fallback;
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
        printWindow.addEventListener('afterprint', () => frame.remove(), {
            once: true,
        });
        printWindow.focus();
        printWindow.print();
    } catch (error) {
        frame.remove();
        throw error;
    }
}
