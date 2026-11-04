import { Deferred, Head, Link, router } from '@inertiajs/react';
import {
    disbursementFileType,
    formatDisbursementDate,
    formatFileSize,
    type DisbursementDocumentData,
    type Paginator,
} from '@/components/disbursements/disbursement';
import ResidentLayout from '@/layouts/ResidentLayout';
import PortalPageSkeleton from '@/components/PortalPageSkeleton';

type Props = {
    documents: Paginator<DisbursementDocumentData>;
    resident?: {
        full_name?: string;
    };
};

export default function ResidentDisbursement(props: Props) {
    return (
        <Deferred
            data="documents"
            fallback={
                <ResidentLayout active="disbursement">
                    <PortalPageSkeleton list />
                </ResidentLayout>
            }
        >
            <DisbursementContent {...props} />
        </Deferred>
    );
}

function DisbursementContent({ documents, resident }: Props) {
    return (
        <ResidentLayout active="disbursement">
            <Head title="Disbursement Documents" />

            <main className="mx-auto w-full max-w-5xl px-4 py-6 sm:px-6 lg:px-8">
                <section className="overflow-hidden rounded-2xl bg-[#3f6f3d] px-5 py-6 text-white shadow-sm sm:px-7">
                    <p className="text-xs font-semibold tracking-wide text-white/70 uppercase">
                        Barangay transparency
                    </p>

                    <h1 className="mt-1 text-2xl font-bold sm:text-3xl">
                        Disbursement Documents
                    </h1>

                    <p className="mt-2 max-w-2xl text-sm leading-relaxed text-white/85">
                        View official payout records and financial documents
                        published by your barangay.
                    </p>

                    {resident?.full_name && (
                        <p className="mt-4 inline-flex rounded-full bg-white/10 px-3 py-1 text-xs text-white/85">
                            For {resident.full_name}
                        </p>
                    )}
                </section>

                <div className="mt-5 flex items-center justify-between gap-3">
                    <div>
                        <h2 className="text-sm font-semibold text-gray-900">
                            Published documents
                        </h2>

                        <p className="mt-0.5 text-xs text-gray-500">
                            {documents.total}{' '}
                            {documents.total === 1 ? 'document' : 'documents'}{' '}
                            available
                        </p>
                    </div>
                </div>

                {documents.data.length === 0 ? (
                    <section className="mt-4 rounded-2xl border border-dashed border-gray-300 bg-white px-5 py-14 text-center">
                        <div className="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-gray-100 text-gray-400">
                            <DocumentIcon />
                        </div>

                        <h2 className="mt-3 text-sm font-semibold text-gray-800">
                            No disbursement documents yet
                        </h2>

                        <p className="mx-auto mt-1 max-w-md text-xs leading-relaxed text-gray-500">
                            Published payout records will appear here. Please
                            check again later or contact the barangay office for
                            assistance.
                        </p>
                    </section>
                ) : (
                    <section className="mt-4 grid gap-3">
                        {documents.data.map((document) => (
                            <article
                                key={document.id}
                                className="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm transition hover:border-[#3f6f3d]/30 hover:shadow-md sm:p-5"
                            >
                                <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                                    <div className="min-w-0 flex-1">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <span className="rounded-md border border-[#3f6f3d]/20 bg-[#3f6f3d]/5 px-2 py-0.5 text-[11px] font-semibold text-[#3f6f3d]">
                                                {disbursementFileType(document)}
                                            </span>

                                            <span className="text-[11px] text-gray-400">
                                                {formatFileSize(
                                                    document.file_size,
                                                )}
                                            </span>

                                            {document.published_at && (
                                                <span className="text-[11px] text-gray-400">
                                                    Published{' '}
                                                    {formatDisbursementDate(
                                                        document.published_at,
                                                    )}
                                                </span>
                                            )}
                                        </div>

                                        <h3 className="mt-2 text-base font-semibold break-words text-gray-900">
                                            {document.title}
                                        </h3>

                                        {document.description && (
                                            <p className="mt-1 line-clamp-2 text-sm leading-relaxed text-gray-500">
                                                {document.description}
                                            </p>
                                        )}

                                        <p className="mt-2 truncate text-[11px] text-gray-400">
                                            {document.original_name}
                                        </p>
                                    </div>

                                    <div className="flex shrink-0 flex-wrap gap-2">
                                        <Link
                                            href={document.preview_url}
                                            className="inline-flex min-h-10 items-center justify-center rounded-lg bg-[#3f6f3d] px-4 text-xs font-semibold text-white transition hover:bg-[#315b30]"
                                        >
                                            View document
                                        </Link>

                                        <a
                                            href={document.download_url}
                                            className="inline-flex min-h-10 items-center justify-center rounded-lg border border-gray-300 bg-white px-4 text-xs font-semibold text-gray-700 transition hover:bg-gray-50"
                                        >
                                            Download
                                        </a>
                                    </div>
                                </div>
                            </article>
                        ))}
                    </section>
                )}

                {documents.last_page > 1 && (
                    <div className="mt-5 flex flex-wrap items-center justify-between gap-3">
                        <p className="text-xs text-gray-500">
                            Showing {documents.from ?? 0} to {documents.to ?? 0}{' '}
                            of {documents.total}
                        </p>

                        <div className="flex flex-wrap gap-1">
                            {documents.links.map((link, index) => (
                                <button
                                    key={index}
                                    type="button"
                                    disabled={!link.url}
                                    onClick={() => {
                                        if (!link.url) {
                                            return;
                                        }

                                        router.get(
                                            link.url,
                                            {},
                                            {
                                                preserveScroll: true,
                                                preserveState: true,
                                                only: ['documents'],
                                            },
                                        );
                                    }}
                                    className={`rounded-md px-3 py-1.5 text-xs ${
                                        link.active
                                            ? 'bg-[#3f6f3d] text-white'
                                            : link.url
                                              ? 'border border-gray-300 bg-white text-gray-600 hover:bg-gray-50'
                                              : 'cursor-not-allowed border border-gray-200 bg-white text-gray-300'
                                    }`}
                                    dangerouslySetInnerHTML={{
                                        __html: link.label,
                                    }}
                                />
                            ))}
                        </div>
                    </div>
                )}
            </main>
        </ResidentLayout>
    );
}

function DocumentIcon() {
    return (
        <svg
            className="h-6 w-6"
            fill="none"
            viewBox="0 0 24 24"
            stroke="currentColor"
            strokeWidth="1.8"
            aria-hidden="true"
        >
            <path
                strokeLinecap="round"
                strokeLinejoin="round"
                d="M7 3h7l4 4v14H7V3zM14 3v5h5M9.5 12h5M9.5 16h5"
            />
        </svg>
    );
}
