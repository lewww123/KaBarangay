import { useEffect, useId, useRef, useState } from 'react';
import type { Html5Qrcode } from 'html5-qrcode';
export default function QrCamera({
    onScan,
    onClose,
}: {
    onScan: (code: string) => Promise<boolean>;
    onClose: () => void;
}) {
    const id = `qr-${useId().replace(/[^a-z0-9]/gi, '')}`;
    const callback = useRef(onScan);
    callback.current = onScan;
    const close = useRef(onClose);
    close.current = onClose;
    const [error, setError] = useState<string | null>(null);
    useEffect(() => {
        let cancelled = false;
        let scanner: Html5Qrcode | null = null;
        let busy = false;
        let previous = '';
        let previousAt = 0;
        const stop = async () => {
            if (scanner?.isScanning)
                await scanner.stop().catch(() => undefined);
            try {
                scanner?.clear();
            } catch {
                /* Starting scanners are cleared once startup finishes. */
            }
        };
        void (async () => {
            const { Html5Qrcode, Html5QrcodeSupportedFormats } =
                await import('html5-qrcode');
            if (cancelled) return;
            scanner = new Html5Qrcode(id, {
                formatsToSupport: [Html5QrcodeSupportedFormats.QR_CODE],
                verbose: false,
            });
            try {
                await scanner.start(
                    { facingMode: 'environment' },
                    { fps: 10, qrbox: { width: 220, height: 220 } },
                    (code) => {
                        if (
                            cancelled ||
                            busy ||
                            (code === previous &&
                                Date.now() - previousAt < 3000)
                        )
                            return;
                        previous = code;
                        previousAt = Date.now();
                        busy = true;
                        void callback
                            .current(code)
                            .then((matched) => {
                                if (matched && !cancelled) close.current();
                            })
                            .finally(() => {
                                busy = false;
                            });
                    },
                    () => undefined,
                );
                if (cancelled) await stop();
            } catch {
                if (!cancelled)
                    setError(
                        'Camera unavailable. Allow camera access or use the scanner/reference field.',
                    );
            }
        })().catch(() => {
            if (!cancelled)
                setError(
                    'Camera could not be started. Use the scanner/reference field.',
                );
        });
        return () => {
            cancelled = true;
            void stop();
        };
    }, [id]);
    return (
        <div className="space-y-2 rounded-xl border border-slate-200 bg-slate-50 p-3">
            <div
                id={id}
                className="mx-auto max-w-sm overflow-hidden rounded-lg"
            />
            {error && (
                <p role="alert" className="text-xs text-red-700">
                    {error}
                </p>
            )}
            <button
                type="button"
                onClick={onClose}
                className="rounded-lg border bg-white px-3 py-2 text-xs font-semibold"
            >
                Close camera
            </button>
        </div>
    );
}
