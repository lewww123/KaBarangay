import { Head } from '@inertiajs/react';
import {
    Html5Qrcode,
    Html5QrcodeSupportedFormats,
} from 'html5-qrcode';
import { useEffect, useRef, useState } from 'react';
import AdminLayout from '@/layouts/AdminLayout';

export default function QrScanner() {
    const scannerRef = useRef<Html5Qrcode | null>(null);
    const hasScannedRef = useRef(false);

    const [isScanning, setIsScanning] = useState(false);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        const scanner = new Html5Qrcode('resident-qr-reader', {
            formatsToSupport: [
                Html5QrcodeSupportedFormats.QR_CODE,
            ],
            verbose: false,
        });

        scannerRef.current = scanner;

        const startScanner = async () => {
            try {
                setError(null);

                await scanner.start(
                    {
                        facingMode: 'environment',
                    },
                    {
                        fps: 10,
                        qrbox: {
                            width: 250,
                            height: 250,
                        },
                    },
                    async (decodedText) => {
                        if (hasScannedRef.current) {
                            return;
                        }

                        hasScannedRef.current = true;

                        try {
                            let verificationPath: string | null = null;

                            /*
                            |--------------------------------------------------------------------------
                            | QR contains a full KaBarangay verification URL
                            |--------------------------------------------------------------------------
                            */

                            try {
                                const scannedUrl = new URL(
                                    decodedText,
                                    window.location.origin,
                                );

                                if (
                                    scannedUrl.origin ===
                                        window.location.origin &&
                                    scannedUrl.pathname.startsWith(
                                        '/verify/resident/',
                                    )
                                ) {
                                    verificationPath =
                                        scannedUrl.pathname +
                                        scannedUrl.search +
                                        scannedUrl.hash;
                                }
                            } catch {
                                verificationPath = null;
                            }

                            /*
                            |--------------------------------------------------------------------------
                            | Also allow scanning the raw KBQR token
                            |--------------------------------------------------------------------------
                            */

                            if (
                                !verificationPath &&
                                decodedText.startsWith('KBQR-')
                            ) {
                                verificationPath =
                                    `/verify/resident/${encodeURIComponent(
                                        decodedText,
                                    )}`;
                            }

                            /*
                            |--------------------------------------------------------------------------
                            | Reject unrelated QR codes
                            |--------------------------------------------------------------------------
                            */

                            if (!verificationPath) {
                                hasScannedRef.current = false;

                                setError(
                                    'This is not a valid KaBarangay resident QR code.',
                                );

                                return;
                            }

                            /*
                            |--------------------------------------------------------------------------
                            | Stop camera before opening verification page
                            |--------------------------------------------------------------------------
                            */

                            try {
                                await scanner.stop();
                            } catch {
                                // Scanner may already be stopping.
                            }

                            setIsScanning(false);

                            /*
                            |--------------------------------------------------------------------------
                            | Open resident verification page
                            |--------------------------------------------------------------------------
                            */

                            window.location.assign(
                                verificationPath,
                            );
                        } catch {
                            hasScannedRef.current = false;

                            setError(
                                'Unable to process the scanned QR code.',
                            );
                        }
                    },
                    () => {
                        // Ignore normal scanning errors while searching for a QR.
                    },
                );

                setIsScanning(true);
            } catch (scanError) {
                console.error(
                    'QR scanner error:',
                    scanError,
                );

                setIsScanning(false);

                setError(
                    'Unable to access the camera. Please allow camera permission and try again.',
                );
            }
        };

        startScanner();

        return () => {
            const stopScanner = async () => {
                try {
                    if (scannerRef.current) {
                        await scannerRef.current.stop();
                    }
                } catch {
                    // Scanner may already be stopped.
                }

                try {
                    scannerRef.current?.clear();
                } catch {
                    // Scanner may already be cleared.
                }
            };

            stopScanner();
        };
    }, []);

    return (
        <>
            <Head title="Resident QR Scanner" />

            <AdminLayout active="qr-scanner">

                {/* PAGE HEADER */}
                <div className="mb-6">
                    <h1 className="text-2xl font-semibold text-gray-800">
                        Resident QR Scanner
                    </h1>

                    <p className="mt-1 text-sm text-gray-500">
                        Scan a KaBarangay resident QR code to verify
                        the resident.
                    </p>
                </div>

                {/* SCANNER CARD */}
                <div className="mx-auto max-w-2xl">

                    <div className="overflow-hidden rounded-2xl bg-white shadow-lg ring-1 ring-gray-200">

                        {/* Scanner */}
                        <div className="p-4 sm:p-6">
                            <div
                                id="resident-qr-reader"
                                className="mx-auto w-full max-w-md overflow-hidden rounded-xl"
                            />
                        </div>

                        {/* Status */}
                        <div className="border-t border-gray-100 px-5 py-5 text-center">

                            {isScanning && !error && (
                                <>
                                    <div className="mx-auto mb-3 flex h-10 w-10 items-center justify-center rounded-full bg-green-100">
                                        <span className="h-3 w-3 animate-pulse rounded-full bg-green-600" />
                                    </div>

                                    <p className="font-semibold text-gray-900">
                                        Scanner is ready
                                    </p>

                                    <p className="mt-1 text-sm text-gray-500">
                                        Point the camera at the resident's
                                        QR code.
                                    </p>
                                </>
                            )}

                            {error && (
                                <div className="rounded-xl border border-red-200 bg-red-50 p-4">
                                    <p className="text-sm font-semibold text-red-800">
                                        {error}
                                    </p>

                                    <p className="mt-2 text-xs text-red-700">
                                        Make sure you are scanning a valid
                                        KaBarangay resident QR code and that
                                        camera permission is enabled.
                                    </p>
                                </div>
                            )}

                        </div>
                    </div>

                    {/* INFORMATION */}
                    <div className="mt-5 rounded-xl border border-blue-200 bg-blue-50 p-4">
                        <p className="text-sm font-semibold text-blue-900">
                            Verification Information
                        </p>

                        <p className="mt-1 text-sm leading-6 text-blue-800">
                            Only active KaBarangay resident QR codes will
                            proceed to the resident verification page.
                        </p>
                    </div>

                    {/* SECURITY NOTICE */}
                    <p className="mt-5 text-center text-xs leading-5 text-gray-500">
                        Do not share or photograph resident information
                        unnecessarily. Use resident verification only for
                        authorized barangay purposes.
                    </p>

                </div>

            </AdminLayout>
        </>
    );
}