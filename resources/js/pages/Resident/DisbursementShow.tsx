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

            <main className="mx-auto w-full max-w-5xl px-4 py-6 sm:px-6 lg:px-8">
                <Link
                    href="/resident/disbursement"
                    className="inline-flex items-center gap-1.5 text-xs font-semibold text-gray-500 transition hover:text-[#3b6fa8]"
                >
                    <BackIcon />
                    Back to disbursement documents
                </Link>

                <section className="mt-4 rounded-2xl border border-gray-200 bg-white p-5 shadow-sm sm:p-6">
                    <div className="flex flex-wrap items-center gap-2">
                        <span className="rounded-md border border-[#3b6fa8]/20 bg-[#3b6fa8]/5 px-2 py-0.5 text-[11px] font-semibold text-[#3b6fa8]">
                            {disbursementFileType(document)}
                        </span>

                        <span className="text-[11px] text-gray-400">
                            {formatFileSize(document.file_size)}
                        </span>
                    </div>

                    <h1 className="mt-3 text-2xl font-bold break-words text-gray-900">
                        {document.title}
                    </h1>

                    {document.description && (
                        <p className="mt-2 max-w-3xl text-sm leading-relaxed whitespace-pre-wrap text-gray-600">
                            {document.description}
                        </p>
                    )}

                    <dl className="mt-4 grid gap-3 border-t border-gray-100 pt-4 text-xs sm:grid-cols-3">
                        <div>
                            <dt className="font-medium text-gray-400">
                                Original file
                            </dt>
                            <dd className="mt-1 break-words text-gray-700">
                                {document.original_name}
                            </dd>
                        </div>

                        <div>
                            <dt className="font-medium text-gray-400">
                                Published
                            </dt>
                            <dd className="mt-1 text-gray-700">
                                {formatDisbursementDateTime(
                                    document.published_at,
                                )}
                            </dd>
                        </div>

                        <div>
                            <dt className="font-medium text-gray-400">
                                Format
                            </dt>
                            <dd className="mt-1 text-gray-700">
                                {disbursementFileType(document)}
                            </dd>
                        </div>
                    </dl>
                </section>

                <section className="mt-5">
                    <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                        <h2 className="text-sm font-semibold text-gray-900">
                            Document preview
                        </h2>

                        <p className="text-[11px] text-gray-500">
                            Download the original for the complete file
                        </p>
                    </div>

                    <DisbursementPreview
                        document={document}
                        csvPreview={csvPreview}
                    />
                </section>

                <p className="mt-5 rounded-xl border border-gray-200 bg-white px-4 py-3 text-xs leading-relaxed text-gray-500">
                    For questions about a disbursement record, please contact
                    the barangay office and provide the document title.
                </p>
            </main>
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
