import { Head, useForm } from '@inertiajs/react';
import { FormEvent, useEffect, useRef, useState } from 'react';

type SelectOption = { value: string; label: string };

type FieldTarget = {
    key: string;
    label: string;
    type: string;
    value: string;
    options: SelectOption[];
};

type DocumentTarget = {
    key: string;
    label: string;
};

type Correction = {
    cycle_number: number;
    instructions: string;
    field_targets: FieldTarget[];
    document_targets: DocumentTarget[];
};

type Application = {
    reference: string;
    applicant_name: string;
    status: string;
    submitted_at: string | null;
    barangay: string | null;
    purok: string | null;
    correction: Correction | null;
};

type Props = {
    application: Application;
};

const STATUS_LABELS: Record<string, string> = {
    pending: 'Pending review',
    needs_correction: 'Needs correction',
    rejected: 'Decision recorded',
    approved: 'Decision recorded',
};

export default function RegistrationApplication({ application }: Props) {
    const logout = useForm({});
    const correction = application.correction;
    const correctionForm = useForm<{
        fields: Record<string, string>;
        documents: Record<string, File | null>;
    }>({
        fields: Object.fromEntries(
            correction?.field_targets.map((target) => [
                target.key,
                target.value,
            ]) ?? [],
        ),
        documents: Object.fromEntries(
            correction?.document_targets.map((target) => [target.key, null]) ??
                [],
        ),
    });
    const correctionErrors = correctionForm.errors as Record<
        string,
        string | undefined
    >;
    const terminal =
        application.status === 'approved' || application.status === 'rejected';

    function signOut() {
        logout.post(
            `/resident/application/${encodeURIComponent(application.reference)}/logout`,
        );
    }

    function submitCorrection(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        if (
            correction?.document_targets.some(
                (target) => !(correctionForm.data.documents[target.key] instanceof File),
            )
        ) {
            correctionForm.setError(
                'correction',
                'Please complete every required correction document before resubmitting.',
            );
            return;
        }

        correctionForm.clearErrors('correction');
        correctionForm.post(
            `/resident/application/${encodeURIComponent(application.reference)}/correction`,
            {
                forceFormData: true,
                preserveScroll: true,
            },
        );
    }


    return (
        <div className="min-h-screen bg-canvas">
            <Head title="Resident Application Status" />
            <header className="border-b border-brand-deep bg-brand">
                <div className="mx-auto flex min-h-16 w-full max-w-4xl items-center justify-between px-4 sm:px-6">
                    <div className="text-white">
                        <p className="text-base font-semibold">KaBarangay</p>
                        <p className="text-xs text-white/80">
                            Secure application access
                        </p>
                    </div>
                    <button
                        type="button"
                        onClick={signOut}
                        disabled={logout.processing}
                        className="rounded-lg border border-white/50 px-3 py-2 text-xs font-semibold text-white hover:bg-white/10 disabled:opacity-60"
                    >
                        {logout.processing ? 'Signing out…' : 'Sign out'}
                    </button>
                </div>
            </header>

            <main className="mx-auto w-full max-w-4xl px-4 py-8 sm:px-6">
                <section className="rounded-xl border border-line-soft bg-white p-5 shadow-card sm:p-8">
                    <p className="text-xs font-semibold tracking-wider text-ink-faint uppercase">
                        Application status
                    </p>
                    <h1 className="mt-2 text-xl font-semibold tracking-tight text-ink-strong sm:text-2xl">
                        {STATUS_LABELS[application.status] ??
                            'Status unavailable'}
                    </h1>
                    <p className="mt-2 text-sm leading-6 text-ink-muted">
                        This page shows information for the application
                        authenticated in this session. It does not create a
                        Resident account or change your application.
                    </p>

                    <dl className="mt-6 grid gap-4 rounded-xl border border-line-soft bg-canvas p-4 sm:grid-cols-2">
                        <div>
                            <dt className="text-xs font-semibold tracking-wide text-ink-faint uppercase">
                                Applicant
                            </dt>
                            <dd className="mt-1 text-sm font-medium text-ink-strong">
                                {application.applicant_name}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-xs font-semibold tracking-wide text-ink-faint uppercase">
                                Application reference
                            </dt>
                            <dd className="mt-1 font-mono text-sm break-all text-ink-strong">
                                {application.reference}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-xs font-semibold tracking-wide text-ink-faint uppercase">
                                Barangay
                            </dt>
                            <dd className="mt-1 text-sm text-ink-strong">
                                {application.barangay || '—'}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-xs font-semibold tracking-wide text-ink-faint uppercase">
                                Purok
                            </dt>
                            <dd className="mt-1 text-sm text-ink-strong">
                                {application.purok || '—'}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-xs font-semibold tracking-wide text-ink-faint uppercase">
                                Submitted
                            </dt>
                            <dd className="mt-1 text-sm text-ink-strong">
                                {application.submitted_at || '—'}
                            </dd>
                        </div>
                    </dl>

                    <section className="mt-6 rounded-xl border border-line-soft bg-surface p-4 sm:p-5">
                        <h2 className="text-sm font-semibold text-ink-strong">Email notifications</h2>
                        <p className="mt-2 text-sm text-ink-muted">
                            Email is optional. If supplied, it may receive the admin decision.
                        </p>
                        <p className="mt-2 text-sm text-ink-muted">
                            Mobile number – Unverified. Verification is not yet available.
                        </p>
                    </section>

                    {application.status === 'needs_correction' &&
                        correction && (
                            <section className="mt-6 rounded-xl border border-amber-200 bg-amber-50 p-4 sm:p-5">
                                <h2 className="text-lg font-semibold text-amber-950">
                                    Correction requested
                                </h2>
                                <p className="mt-2 text-sm leading-6 whitespace-pre-line text-amber-900">
                                    {correction.instructions}
                                </p>

                                <form
                                    onSubmit={submitCorrection}
                                    className="mt-5 space-y-5"
                                >
                                    {correction.field_targets.length > 0 && (
                                        <fieldset className="space-y-4">
                                            <legend className="text-sm font-semibold text-ink-strong">
                                                Fields you may correct
                                            </legend>
                                            {correction.field_targets.map(
                                                (target) => (
                                                    <div key={target.key}>
                                                        <label
                                                            htmlFor={`correction-${target.key}`}
                                                            className="mb-1 block text-sm font-medium text-ink"
                                                        >
                                                            {target.label}
                                                        </label>
                                                        {target.type ===
                                                        'select' ? (
                                                            <select
                                                                id={`correction-${target.key}`}
                                                                value={
                                                                    correctionForm
                                                                        .data
                                                                        .fields[
                                                                        target
                                                                            .key
                                                                    ] ?? ''
                                                                }
                                                                onChange={(
                                                                    event,
                                                                ) =>
                                                                    correctionForm.setData(
                                                                        'fields',
                                                                        {
                                                                            ...correctionForm
                                                                                .data
                                                                                .fields,
                                                                            [target.key]:
                                                                                event
                                                                                    .target
                                                                                    .value,
                                                                        },
                                                                    )
                                                                }
                                                                required
                                                                className="min-h-11 w-full rounded-lg border border-line bg-white px-3 py-2 text-sm"
                                                            >
                                                                <option value="">
                                                                    Select{' '}
                                                                    {target.label.toLowerCase()}
                                                                </option>
                                                                {target.options.map(
                                                                    (
                                                                        option,
                                                                    ) => (
                                                                        <option
                                                                            key={
                                                                                option.value
                                                                            }
                                                                            value={
                                                                                option.value
                                                                            }
                                                                        >
                                                                            {
                                                                                option.label
                                                                            }
                                                                        </option>
                                                                    ),
                                                                )}
                                                            </select>
                                                        ) : (
                                                            <input
                                                                id={`correction-${target.key}`}
                                                                type={
                                                                    target.type
                                                                }
                                                                value={
                                                                    correctionForm
                                                                        .data
                                                                        .fields[
                                                                        target
                                                                            .key
                                                                    ] ?? ''
                                                                }
                                                                onChange={(
                                                                    event,
                                                                ) =>
                                                                    correctionForm.setData(
                                                                        'fields',
                                                                        {
                                                                            ...correctionForm
                                                                                .data
                                                                                .fields,
                                                                            [target.key]:
                                                                                event
                                                                                    .target
                                                                                    .value,
                                                                        },
                                                                    )
                                                                }
                                                                required={
                                                                    ![
                                                                        'middle_name',
                                                                        'suffix',
                                                                        'email',
                                                                    ].includes(
                                                                        target.key,
                                                                    )
                                                                }
                                                                className="min-h-11 w-full rounded-lg border border-line bg-white px-3 py-2 text-sm"
                                                            />
                                                        )}
                                                        {correctionForm.errors[
                                                            `fields.${target.key}`
                                                        ] && (
                                                            <p
                                                                role="alert"
                                                                className="mt-1 text-xs text-red-700"
                                                            >
                                                                {
                                                                    correctionForm
                                                                        .errors[
                                                                        `fields.${target.key}`
                                                                    ]
                                                                }
                                                            </p>
                                                        )}
                                                    </div>
                                                ),
                                            )}
                                        </fieldset>
                                    )}

                                    {correction.document_targets.length > 0 && (
                                        <fieldset className="space-y-4">
                                            <legend className="text-sm font-semibold text-ink-strong">
                                                Replacement documents
                                            </legend>
                                            {correction.document_targets.map(
                                                (target) => (
                                                    <div key={target.key}>
                                                        <label
                                                            htmlFor={`correction-document-${target.key}`}
                                                            className="mb-1 block text-sm font-medium text-ink"
                                                        >
                                                            {target.label}
                                                        </label>
                                                        {target.key === 'valid_id' ? (
                                                            <CorrectionValidIdCameraCapture
                                                                id={`correction-document-${target.key}`}
                                                                value={correctionForm.data.documents[target.key] ?? null}
                                                                error={correctionForm.errors[`documents.${target.key}`]}
                                                                onChange={(file) =>
                                                                    correctionForm.setData('documents', {
                                                                        ...correctionForm.data.documents,
                                                                        [target.key]: file,
                                                                    })
                                                                }
                                                            />
                                                        ) : (
                                                            <input
                                                                id={`correction-document-${target.key}`}
                                                                type="file"
                                                                accept={
                                                                    target.key === 'live_identity_photo'
                                                                        ? 'image/jpeg,image/png'
                                                                        : 'image/jpeg,image/png,application/pdf'
                                                                }
                                                                onChange={(event) =>
                                                                    correctionForm.setData('documents', {
                                                                        ...correctionForm.data.documents,
                                                                        [target.key]:
                                                                            event.target.files?.[0] ?? null,
                                                                    })
                                                                }
                                                                required
                                                                className="block w-full rounded-lg border border-line bg-white p-2 text-sm"
                                                            />
                                                        )}
                                                        {target.key !== 'valid_id' &&
                                                            correctionForm.errors[`documents.${target.key}`] && (
                                                                <p
                                                                    role="alert"
                                                                    className="mt-1 text-xs text-red-700"
                                                                >
                                                                    {correctionForm.errors[`documents.${target.key}`]}
                                                                </p>
                                                            )}
                                                    </div>
                                                ),
                                            )}
                                        </fieldset>
                                    )}

                                    {correctionErrors.correction && (
                                        <p
                                            role="alert"
                                            className="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700"
                                        >
                                            {correctionErrors.correction}
                                        </p>
                                    )}

                                    <p className="text-xs leading-5 text-ink-muted">
                                        Only the fields and documents listed
                                        above can be submitted. Your application
                                        remains the same application and returns
                                        to Pending review after successful
                                        resubmission.
                                    </p>
                                    <button
                                        type="submit"
                                        disabled={correctionForm.processing}
                                        className="min-h-11 rounded-lg bg-brand px-5 py-2.5 text-sm font-semibold text-white hover:bg-brand-deep disabled:cursor-not-allowed disabled:opacity-60"
                                    >
                                        {correctionForm.processing
                                            ? 'Submitting…'
                                            : 'Resubmit correction'}
                                    </button>
                                </form>
                            </section>
                        )}

                    {application.status === 'needs_correction' &&
                        !correction && (
                            <div className="mt-5 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm leading-6 text-amber-900">
                                Correction details are temporarily unavailable.
                                Contact your barangay office for assistance.
                            </div>
                        )}

                    {terminal && (
                        <p className="mt-5 rounded-xl border border-line-soft bg-canvas p-4 text-sm text-ink-muted">
                            This application is read-only.
                        </p>
                    )}
                </section>
            </main>
        </div>
    );
}


function CorrectionValidIdCameraCapture({
    id,
    value,
    error,
    onChange,
}: {
    id: string;
    value: File | null;
    error?: string;
    onChange: (file: File | null) => void;
}) {
    const videoRef = useRef<HTMLVideoElement | null>(null);
    const canvasRef = useRef<HTMLCanvasElement | null>(null);
    const streamRef = useRef<MediaStream | null>(null);

    const [cameraOpen, setCameraOpen] = useState(false);
    const [cameraReady, setCameraReady] = useState(false);
    const [cameraError, setCameraError] = useState('');
    const [startingCamera, setStartingCamera] = useState(false);
    const [capturing, setCapturing] = useState(false);
    const [previewUrl, setPreviewUrl] = useState<string | null>(null);

    useEffect(() => {
        if (!value) {
            setPreviewUrl(null);
            return;
        }

        const url = URL.createObjectURL(value);
        setPreviewUrl(url);

        return () => URL.revokeObjectURL(url);
    }, [value]);

    useEffect(() => {
        if (!cameraOpen || !streamRef.current || !videoRef.current) {
            return;
        }

        const video = videoRef.current;
        video.srcObject = streamRef.current;

        const markReady = () => {
            if (video.videoWidth > 0 && video.videoHeight > 0) {
                setCameraReady(true);
            }
        };

        video.addEventListener('loadedmetadata', markReady);
        video.addEventListener('canplay', markReady);

        const playVideo = async () => {
            try {
                await video.play();
                markReady();
            } catch {
                // Some mobile browsers start playback after metadata is loaded.
            }
        };

        void playVideo();

        return () => {
            video.removeEventListener('loadedmetadata', markReady);
            video.removeEventListener('canplay', markReady);
        };
    }, [cameraOpen]);

    useEffect(() => {
        return () => {
            streamRef.current?.getTracks().forEach((track) => track.stop());
        };
    }, []);

    const stopCamera = () => {
        streamRef.current?.getTracks().forEach((track) => track.stop());
        streamRef.current = null;

        if (videoRef.current) {
            videoRef.current.pause();
            videoRef.current.srcObject = null;
        }

        setCameraReady(false);
        setCameraOpen(false);
        setCapturing(false);
    };

    const requestCamera = async () => {
        const base = {
            width: { ideal: 1280, max: 1920 },
            height: { ideal: 720, max: 1080 },
            frameRate: { ideal: 24, max: 30 },
        };
        const attempts = [
            { video: { facingMode: { ideal: 'environment' }, ...base }, audio: false },
            { video: { facingMode: { ideal: 'user' }, ...base }, audio: false },
            { video: true, audio: false },
        ];

        let lastError;
        for (const constraints of attempts) {
            try {
                return await navigator.mediaDevices.getUserMedia(constraints);
            } catch (attemptError) {
                lastError = attemptError;
            }
        }

        throw lastError;
    };

    const openCamera = async () => {
        setCameraError('');
        setCameraReady(false);

        if (typeof navigator === 'undefined') {
            return;
        }

        if (
            !window.isSecureContext &&
            window.location.hostname !== 'localhost'
        ) {
            setCameraError(
                'Camera access requires HTTPS. Please open KaBarangay using a secure connection.',
            );
            return;
        }

        if (!navigator.mediaDevices?.getUserMedia) {
            setCameraError(
                'Camera access is not supported by this browser. Please use an updated Chrome, Edge, Safari, or Firefox browser.',
            );
            return;
        }

        try {
            setStartingCamera(true);
            stopCamera();

            const stream = await requestCamera();
            streamRef.current = stream;
            setCameraOpen(true);
        } catch (cameraException) {
            const exception = cameraException as DOMException;

            if (exception?.name === 'NotAllowedError' || exception?.name === 'SecurityError') {
                setCameraError(
                    'Camera permission was blocked. Allow camera access in your browser settings, then try again.',
                );
            } else if (exception?.name === 'NotFoundError' || exception?.name === 'DevicesNotFoundError') {
                setCameraError('No camera was detected on this device.');
            } else if (exception?.name === 'NotReadableError' || exception?.name === 'TrackStartError') {
                setCameraError(
                    'The camera is currently being used by another app. Close the other camera app and try again.',
                );
            } else if (exception?.name === 'OverconstrainedError' || exception?.name === 'ConstraintNotSatisfiedError') {
                setCameraError(
                    'The requested camera is not available on this device. Please try again.',
                );
            } else {
                setCameraError(
                    'The camera could not be opened. Please check camera permission and try again.',
                );
            }
        } finally {
            setStartingCamera(false);
        }
    };

    const capturePhoto = () => {
        if (capturing) {
            return;
        }

        const video = videoRef.current;
        const canvas = canvasRef.current;

        if (
            !cameraReady ||
            !video ||
            !canvas ||
            !video.videoWidth ||
            !video.videoHeight
        ) {
            setCameraError(
                'The camera is still preparing. Wait for the preview, then take the photo.',
            );
            return;
        }

        setCameraError('');
        setCapturing(true);

        const maxWidth = 1280;
        const sourceWidth = video.videoWidth;
        const sourceHeight = video.videoHeight;
        const scale = Math.min(1, maxWidth / sourceWidth);
        const outputWidth = Math.max(1, Math.round(sourceWidth * scale));
        const outputHeight = Math.max(1, Math.round(sourceHeight * scale));

        canvas.width = outputWidth;
        canvas.height = outputHeight;

        const context = canvas.getContext('2d', { alpha: false });

        if (!context) {
            setCameraError('Unable to capture the photo. Please try again.');
            setCapturing(false);
            return;
        }

        context.drawImage(video, 0, 0, outputWidth, outputHeight);

        canvas.toBlob(
            (blob) => {
                if (!blob) {
                    setCameraError('Unable to capture the photo. Please try again.');
                    setCapturing(false);
                    return;
                }

                if (blob.size > 5 * 1024 * 1024) {
                    setCameraError(
                        'The captured image is larger than 5 MB. Move closer, improve lighting, and try again.',
                    );
                    setCapturing(false);
                    return;
                }

                const file = new File([blob], `valid-id-${Date.now()}.jpg`, {
                    type: 'image/jpeg',
                });

                onChange(file);
                stopCamera();
            },
            'image/jpeg',
            0.9,
        );
    };

    const message = cameraError || error;

    return (
        <div id={id} className="rounded-xl border border-line-soft bg-canvas p-4">
            <p className="text-sm font-semibold text-ink-strong">Valid Government ID</p>
            <p className="mt-1 text-xs leading-5 text-ink-muted">
                Use your device camera to capture a clear, readable photo of your valid government ID.
                Keep the whole ID inside the guide, avoid glare, and make sure text and photo are sharp.
                Gallery, file upload, and PDF are not available for this correction.
            </p>

            {cameraOpen ? (
                <div className="mt-4 overflow-hidden rounded-xl border border-line-soft bg-black">
                    <div className="relative aspect-[4/3] w-full overflow-hidden bg-black sm:aspect-video">
                        <video
                            ref={videoRef}
                            autoPlay
                            playsInline
                            muted
                            disablePictureInPicture
                            className="h-full w-full object-cover"
                        />
                        {!cameraReady && (
                            <div className="absolute inset-0 flex items-center justify-center bg-black/65 text-white">
                                <div className="text-sm font-semibold">Preparing camera…</div>
                            </div>
                        )}
                        {cameraReady && (
                            <>
                                <div className="pointer-events-none absolute inset-0 flex items-center justify-center p-4">
                                    <div className="h-[68%] w-[92%] max-w-[560px] rounded-2xl border-2 border-dashed border-white/85 shadow-[0_0_0_999px_rgba(0,0,0,0.22)]" />
                                </div>
                                <div className="absolute bottom-3 left-1/2 max-w-[calc(100%-1.5rem)] -translate-x-1/2 rounded-full bg-black/60 px-3 py-1 text-center text-xs font-semibold text-white">
                                    Place your valid ID inside the guide. Keep it flat, bright, and readable.
                                </div>
                            </>
                        )}
                    </div>
                    <div className="flex flex-col gap-3 bg-white p-4 sm:flex-row sm:justify-center">
                        <button
                            type="button"
                            onClick={stopCamera}
                            disabled={capturing}
                            className="min-h-11 rounded-xl border border-line px-5 text-sm font-semibold text-ink hover:bg-canvas disabled:cursor-not-allowed disabled:opacity-60"
                        >
                            Cancel
                        </button>
                        <button
                            type="button"
                            onClick={capturePhoto}
                            disabled={!cameraReady || capturing}
                            className="min-h-11 rounded-xl bg-brand px-6 text-sm font-semibold text-white hover:bg-brand-deep disabled:cursor-not-allowed disabled:opacity-60"
                        >
                            {capturing ? 'Capturing…' : 'Take Photo'}
                        </button>
                    </div>
                </div>
            ) : value && previewUrl ? (
                <div className="mt-4 rounded-xl border border-line bg-white p-4">
                    <div className="grid gap-4 sm:grid-cols-[180px_1fr] sm:items-center">
                        <img
                            src={previewUrl}
                            alt="Valid government ID correction preview"
                            className="aspect-[4/3] w-full rounded-xl border border-line-soft bg-white object-cover"
                        />
                        <div>
                            <p className="text-sm font-semibold text-brand">Valid ID photo captured</p>
                            <p className="mt-2 text-xs leading-5 text-ink-muted">
                                This image was captured directly from your device camera. Review it for clarity before resubmitting.
                            </p>
                            <button
                                type="button"
                                onClick={openCamera}
                                disabled={startingCamera}
                                className="mt-4 min-h-10 rounded-lg border border-line bg-white px-4 text-xs font-semibold text-ink hover:bg-canvas disabled:cursor-not-allowed disabled:opacity-60"
                            >
                                {startingCamera ? 'Opening Camera…' : 'Retake Photo'}
                            </button>
                        </div>
                    </div>
                </div>
            ) : (
                <button
                    type="button"
                    onClick={openCamera}
                    disabled={startingCamera}
                    className="mt-4 flex min-h-11 w-full items-center justify-center rounded-xl bg-brand px-5 text-sm font-semibold text-white hover:bg-brand-deep disabled:cursor-not-allowed disabled:opacity-60 sm:w-auto"
                >
                    {startingCamera ? 'Opening Camera…' : 'Open Camera'}
                </button>
            )}

            <canvas ref={canvasRef} className="hidden" />

            {message && (
                <p role="alert" className="mt-2 text-xs font-semibold text-red-600">
                    {message}
                </p>
            )}

            <p className="mt-2 text-xs leading-5 text-ink-faint">
                Uses your device camera only (rear camera preferred; front camera fallback if needed). Camera permission is required; deployed sites must use HTTPS. Maximum 5 MB.
            </p>
        </div>
    );
}
