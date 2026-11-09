import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import ResidentLayout from '@/layouts/ResidentLayout';

export default function ResidentSectorApplicationEdit({
    resident: _resident,
    application,
    errors = {},
}: {
    resident: { id: number; full_name: string };
    application: {
        id: number;
        tracking_number: string;
        sector: string;
        details: Record<string, unknown> | null;
        correction_notes: string | null;
        documents: Array<{
            id: number;
            title: string;
            type: string;
            url: string;
        }>;
    };
    errors?: Record<string, string[]>;
}) {
    const [notes, setNotes] = useState<string>(
        // Preserve existing details.notes if present, otherwise empty string
        (application.details &&
        typeof application.details === 'object' &&
        'notes' in application.details
            ? (application.details as any).notes
            : '') as string,
    );
    const [files, setFiles] = useState<FileList | null>(null);
    const [submitting, setSubmitting] = useState(false);
    const [submitError, setSubmitError] = useState<string>('');

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        setSubmitting(true);
        setSubmitError('');

        const formData = new FormData();
        if (notes) {
            formData.append('details[notes]', notes);
        }
        if (files) {
            Array.from(files).forEach((file) => {
                formData.append('documents[]', file);
            });
        }
        router.post(
            `/resident/sector-applications/${application.id}`,
            formData,
            {
                forceFormData: true,
                preserveScroll: true,
                onSuccess: () => {
                    setSubmitting(false);
                    setFiles(null);
                },
                onError: (err) => {
                    setSubmitting(false);
                    setSubmitError(Object.values(err).flat().join('\n'));
                },
            },
        );
    };

    return (
        <ResidentLayout active="sectors">
            <Head title="Correct Application" />
            <div className="mx-auto max-w-4xl space-y-5 p-4 font-sans sm:p-6">
                <h1 className="text-xl font-semibold tracking-tight text-ink-strong sm:text-2xl">
                    Correct Application #{application.tracking_number}
                </h1>
                <p className="text-sm text-ink-muted">
                    Sector: {application.sector}
                </p>
                {application.correction_notes && (
                    <div className="rounded-md border border-amber-200 bg-amber-50 p-4">
                        <strong className="block text-amber-800">
                            Correction Instructions:
                        </strong>
                        <p className="mt-1 text-amber-700">
                            {application.correction_notes}
                        </p>
                    </div>
                )}
                <form onSubmit={handleSubmit} className="space-y-4">
                    <div>
                        <label className="block text-sm font-medium text-ink">
                            Notes (optional)
                        </label>
                        <textarea
                            name="details[notes]"
                            value={notes}
                            onChange={(e) => setNotes(e.target.value)}
                            rows={4}
                            className="mt-1 block w-full rounded-md border-line shadow-card focus:border-indigo-500 focus:ring-indigo-500 sm:text-sm"
                            placeholder="Add any additional information needed for correction"
                        />
                        {errors['details.notes'] && (
                            <p className="mt-1 text-sm text-red-600">
                                {errors['details.notes'][0]}
                            </p>
                        )}
                    </div>
                    <div>
                        <label className="block text-sm font-medium text-ink">
                            Replace / Add Documents
                        </label>
                        <input
                            type="file"
                            name="documents[]"
                            multiple
                            onChange={(e) => setFiles(e.target.files)}
                            className="mt-1 block w-full text-sm text-ink-muted file:mr-4 file:rounded-md file:border-0 file:bg-indigo-600 file:px-3 file:py-2 file:text-sm file:font-semibold file:text-white hover:file:bg-indigo-700"
                        />
                        {errors.documents && (
                            <p className="mt-1 text-sm text-red-600">
                                {errors.documents[0]}
                            </p>
                        )}
                    </div>
                    {application.documents.length > 0 && (
                        <div className="space-y-2">
                            <p className="font-medium text-ink">
                                Existing Documents:
                            </p>
                            <ul className="list-inside list-disc space-y-1">
                                {application.documents.map((doc) => (
                                    <li
                                        key={doc.id}
                                        className="text-sm text-ink-muted"
                                    >
                                        <a
                                            href={doc.url}
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            className="underline hover:text-ink-strong"
                                        >
                                            {doc.title} ({doc.type})
                                        </a>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    )}
                    {submitError && (
                        <p className="text-sm text-red-600">{submitError}</p>
                    )}
                    <button
                        type="submit"
                        disabled={submitting}
                        className="inline-flex items-center justify-center gap-2 rounded-xl bg-brand px-4 py-2.5 text-xs font-semibold text-white shadow-card transition hover:bg-brand-deep disabled:opacity-50"
                    >
                        {submitting ? 'Submitting…' : 'Resubmit Correction'}
                    </button>
                </form>
            </div>
        </ResidentLayout>
    );
}
