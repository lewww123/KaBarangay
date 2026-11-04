import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import DisbursementPreview from '@/components/disbursements/DisbursementPreview';
import {
    disbursementFileType,
    disbursementStatusClasses,
    disbursementStatusLabel,
    formatDisbursementDateTime,
    formatFileSize,
    type CsvPreview,
    type DisbursementDocumentData,
    type DisbursementDocumentStatus,
} from '@/components/disbursements/disbursement';
import ClerkLayout from '@/layouts/ClerkLayout';

type Props = {
    document: DisbursementDocumentData;
    csvPreview: CsvPreview | null;
    user?: {
        name?: string;
        staff_id?: string;
        department?: string;
    };
};

export default function ClerkDisbursementShow({
    document,
    csvPreview,
    user,
}: Props) {
    const [busy, setBusy] = useState(false);
    const status = document.status as DisbursementDocumentStatus;

    const runAction = (action: 'publish' | 'archive') => {
        const url =
            action === 'publish' ? document.publish_url : document.archive_url;

        if (!url) {
            return;
        }

        if (
            action === 'archive' &&
            !window.confirm(
                `Archive "${document.title}"? Residents will no longer see this document.`,
            )
        ) {
            return;
        }

        setBusy(true);
        router.post(
            url,
            {},
            {
                preserveScroll: true,
                onFinish: () => setBusy(false),
            },
        );
    };

    return (
        <ClerkLayout active="disbursements" user={user}>
            <Head title={document.title} />

            <main className="mx-auto w-full max-w-6xl px-4 py-6 sm:px-6 lg:px-8">
                <Link
                    href="/clerk/disbursements"
                    className="inline-flex items-center gap-1.5 text-xs font-semibold text-gray-500 transition hover:text-[#3f6f3d]"
                >
                    <BackIcon />
                    Back to disbursement documents
                </Link>

                <section className="mt-4 rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
                    <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                        <div className="min-w-0">
                            <div className="flex flex-wrap items-center gap-2">
                                <span
                                    className={`rounded-full border px-2.5 py-1 text-[11px] font-semibold ${disbursementStatusClasses(status)}`}
                                >
                                    {disbursementStatusLabel(status)}
                                </span>

                                <span className="rounded-md border border-gray-200 bg-gray-50 px-2 py-1 text-[11px] font-semibold text-gray-600">
                                    {disbursementFileType(document)}
                                </span>

                                <span className="text-xs text-gray-400">
                                    {formatFileSize(document.file_size)}
                                </span>
                            </div>

                            <h1 className="mt-3 text-2xl font-bold break-words text-gray-900">
                                {document.title}
                            </h1>

                            {document.description && (
                                <p className="mt-2 max-w-3xl text-sm whitespace-pre-wrap text-gray-600">
                                    {document.description}
                                </p>
                            )}
                        </div>

                        <div className="flex shrink-0 flex-wrap gap-2">
                            <a
                                href={document.download_url}
                                className="inline-flex min-h-10 items-center justify-center rounded-lg border border-gray-300 bg-white px-4 text-sm font-semibold text-gray-700 transition hover:bg-gray-50"
                            >
                                Download original
                            </a>

                            {document.status === 'draft' && (
                                <button
                                    type="button"
                                    disabled={busy}
                                    onClick={() => runAction('publish')}
                                    className="inline-flex min-h-10 items-center justify-center rounded-lg bg-[#3f6f3d] px-4 text-sm font-semibold text-white transition hover:bg-[#315b30] disabled:cursor-not-allowed disabled:opacity-50"
                                >
                                    {busy ? 'Publishing...' : 'Publish'}
                                </button>
                            )}

                            {document.status !== 'archived' && (
                                <button
                                    type="button"
                                    disabled={busy}
                                    onClick={() => runAction('archive')}
                                    className="inline-flex min-h-10 items-center justify-center rounded-lg border border-amber-200 bg-amber-50 px-4 text-sm font-semibold text-amber-800 transition hover:bg-amber-100 disabled:cursor-not-allowed disabled:opacity-50"
                                >
                                    {busy ? 'Updating...' : 'Archive'}
                                </button>
                            )}
                        </div>
                    </div>

                    <dl className="mt-5 grid gap-3 border-t border-gray-100 pt-5 text-xs sm:grid-cols-2 lg:grid-cols-4">
                        <Detail
                            label="Original file"
                            value={document.original_name}
                        />
                        <Detail
                            label="Uploaded"
                            value={formatDisbursementDateTime(
                                document.created_at,
                            )}
                        />
                        <Detail
                            label="Uploaded by"
                            value={document.uploader_name || 'Not available'}
                        />
                        <Detail
                            label="Published"
                            value={
                                document.published_at
                                    ? formatDisbursementDateTime(
                                          document.published_at,
                                      )
                                    : 'Not published'
                            }
                        />
                    </dl>

                    {status === 'draft' && (
                        <p className="mt-4 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">
                            This document is a draft and is not visible to
                            residents until it is published.
                        </p>
                    )}

                    {status === 'archived' && (
                        <p className="mt-4 rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-xs text-gray-600">
                            Archived on{' '}
                            {formatDisbursementDateTime(document.archived_at)}.
                            Archived documents cannot be republished.
                        </p>
                    )}
                </section>

                <section className="mt-5">
                    <div className="mb-3 flex items-center justify-between">
                        <h2 className="text-sm font-semibold text-gray-900">
                            Private preview
                        </h2>

                        <p className="text-[11px] text-gray-500">
                            Staff-only view of the uploaded original
                        </p>
                    </div>

                    <DisbursementPreview
                        document={document}
                        csvPreview={csvPreview}
                    />
                </section>
            </main>
        </ClerkLayout>
    );
}

function Detail({ label, value }: { label: string; value: string }) {
    return (
        <div className="min-w-0">
            <dt className="font-medium text-gray-400">{label}</dt>
            <dd className="mt-1 break-words text-gray-700">{value}</dd>
        </div>
    );
}

function BackIcon() {
    return (
        <svg
            className="h-3.5 w-3.5"
            fill="none"
            viewBox="0 0 24 24"
            stroke="currentColor"
            strokeWidth="2"
            aria-hidden="true"
        >
            <path
                strokeLinecap="round"
                strokeLinejoin="round"
                d="m15 18-6-6 6-6"
            />
        </svg>
    );
}
