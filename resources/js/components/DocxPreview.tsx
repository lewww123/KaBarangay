import { useEffect, useRef, useState } from 'react';
import {
    renderCertificateDocument,
    waitForCertificateAssets,
} from '@/lib/certificateDocument';

interface Props {
    body?: string;
    url: string;
    method?: 'GET' | 'POST';
    onReady?: (ready: boolean) => void;
}

/** Preview the same server-rendered DOCX used for issuance, without issuing it. */
const ignoreReady = () => undefined;
export default function DocxPreview({
    body,
    url,
    method = 'GET',
    onReady = ignoreReady,
}: Props) {
    const container = useRef<HTMLDivElement>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        const controller = new AbortController();
        let active = true;
        onReady(false);
        setLoading(true);
        setError(null);
        container.current?.replaceChildren();
        const timeout = window.setTimeout(async () => {
            try {
                const token =
                    document
                        .querySelector('meta[name="csrf-token"]')
                        ?.getAttribute('content') || '';
                const response = await fetch(url, {
                    method,
                    credentials: 'same-origin',
                    signal: controller.signal,
                    headers: {
                        Accept: 'application/json',
                        'Content-Type':
                            'application/x-www-form-urlencoded;charset=UTF-8',
                        'X-CSRF-TOKEN': token,
                    },
                    body,
                });
                if (!response.ok || response.redirected) {
                    const result = await response.json().catch(() => null);
                    const message = result?.errors
                        ? Object.values(result.errors)
                              .flat()
                              .find((value) => typeof value === 'string')
                        : null;
                    throw new Error(
                        typeof message === 'string'
                            ? message
                            : 'The document preview could not be generated. Check the details and template.',
                    );
                }
                const blob = await response.blob();
                const signature = new Uint8Array(
                    await blob.slice(0, 4).arrayBuffer(),
                );
                if (signature[0] !== 0x50 || signature[1] !== 0x4b) {
                    throw new Error(
                        'The server did not return a certificate document.',
                    );
                }
                const documentBody = document.createElement('div');
                const documentStyles = document.createElement('div');
                await renderCertificateDocument(
                    blob,
                    documentBody,
                    documentStyles,
                );
                await waitForCertificateAssets(documentBody);
                // Word's floating table anchors are not retained by docx-preview.
                // Place the generated board at the same A4 coordinates as the live preview.
                documentBody.querySelectorAll('table').forEach((table) => {
                    if (
                        table.textContent
                            ?.trim()
                            .startsWith('BARANGAY KAGAWADS')
                    ) {
                        table.classList.add('certificate-board-document');
                        documentBody.appendChild(table);
                    }
                });
                if (active && container.current) {
                    container.current.replaceChildren(
                        documentStyles,
                        documentBody,
                    );
                    onReady(true);
                }
            } catch (reason) {
                if (active && !controller.signal.aborted) {
                    setError(
                        reason instanceof Error
                            ? reason.message
                            : 'Unable to load document preview.',
                    );
                }
            } finally {
                if (active) setLoading(false);
            }
        }, 300);

        return () => {
            active = false;
            controller.abort();
            window.clearTimeout(timeout);
        };
    }, [body, url, method, onReady]);

    return (
        <div className="space-y-3" aria-busy={loading}>
            {loading && (
                <p role="status" className="text-center text-sm text-ink-muted">
                    Preparing full-design document preview…
                </p>
            )}
            {error && (
                <p
                    role="alert"
                    className="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800"
                >
                    {error}
                </p>
            )}
            <div
                ref={container}
                className="overflow-auto [&_.certificate-docx-wrapper]:bg-transparent [&_.certificate-docx-wrapper]:p-0"
                aria-label="Full-design document preview"
            />
        </div>
    );
}
