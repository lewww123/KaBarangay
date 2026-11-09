import { Head, Link } from '@inertiajs/react';
import DisbursementPreview from '@/components/disbursements/DisbursementPreview';
import {
    disbursementFileType,
    formatDisbursementDateTime,
    formatFileSize,
    type CsvPreview,
    type DisbursementDocumentData,
} from '@/components/disbursements/disbursement';
import ResidentLayout from '@/layouts/ResidentLayout';

type Props = {
    document: DisbursementDocumentData;
    csvPreview: CsvPreview | null;
};

export default function ResidentDisbursementShow({
    document,
    csvPreview,
}: Props) {
    return (
        <ResidentLayout active="disbursement">
            <Head title={document.title} />

            <div className="mx-auto w-full max-w-4xl space-y-5">
                <Link
                    href="/resident/disbursement"
                    className="inline-flex items-center gap-1.5 text-xs font-semibold text-ink-muted transition hover:text-brand"
                >
                    <BackIcon />
                    Back to disbursement documents
                </Link>

                <section className="mt-4 rounded-xl border border-line-soft bg-white p-5 shadow-card sm:p-6">
                    <div className="flex flex-wrap items-center gap-2">
                        <span className="rounded-md border border-brand/20 bg-brand/5 px-2 py-0.5 text-[11px] font-semibold text-brand">
                            {disbursementFileType(document)}
                        </span>

                        <span className="text-[11px] text-ink-faint">
                            {formatFileSize(document.file_size)}
                        </span>
                    </div>

                    <h1 className="mt-3 text-xl font-semibold tracking-tight break-words text-ink-strong sm:text-2xl">
                        {document.title}
                    </h1>

                    {document.description && (
                        <p className="mt-2 max-w-3xl text-sm leading-relaxed whitespace-pre-wrap text-ink-muted">
                            {document.description}
                        </p>
                    )}

                    <dl className="mt-4 grid gap-3 border-t border-line-soft pt-4 text-xs sm:grid-cols-3">
                        <div>
                            <dt className="font-medium text-ink-faint">
                                Original file
                            </dt>
                            <dd className="mt-1 break-words text-ink">
                                {document.original_name}
                            </dd>
                        </div>

                        <div>
                            <dt className="font-medium text-ink-faint">
                                Published
                            </dt>
                            <dd className="mt-1 text-ink">
                                {formatDisbursementDateTime(
                                    document.published_at,
                                )}
                            </dd>
                        </div>

                        <div>
                            <dt className="font-medium text-ink-faint">
                                Format
                            </dt>
                            <dd className="mt-1 text-ink">
                                {disbursementFileType(document)}
                            </dd>
                        </div>
                    </dl>
                </section>

                <section className="mt-5">
                    <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                        <h2 className="text-sm font-semibold text-ink-strong">
                            Document preview
                        </h2>

                        <p className="text-[11px] text-ink-muted">
                            Download the original for the complete file
                        </p>
                    </div>

                    <DisbursementPreview
                        document={document}
                        csvPreview={csvPreview}
                    />
                </section>

                <p className="mt-5 rounded-xl border border-line-soft bg-white px-4 py-3 text-xs leading-relaxed text-ink-muted">
                    For questions about a disbursement record, please contact
                    the barangay office and provide the document title.
                </p>
            </div>
        </ResidentLayout>
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
