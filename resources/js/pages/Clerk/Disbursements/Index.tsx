import { Head, Link, router, useForm } from '@inertiajs/react';
import { useRef, useState } from 'react';
import type { FormEvent } from 'react';
import {
    disbursementFileType,
    disbursementStatusClasses,
    disbursementStatusLabel,
    formatDisbursementDate,
    formatFileSize,
    type DisbursementDocumentData,
    type DisbursementDocumentStatus,
    type Paginator,
} from '@/components/disbursements/disbursement';
import ClerkLayout from '@/layouts/ClerkLayout';
import PortalPageSkeleton from '@/components/PortalPageSkeleton';
import withDeferredPage from '@/components/withDeferredPage';

type Props = {
    documents: Paginator<DisbursementDocumentData>;
    filters: {
        search: string;
        status: string;
    };
    stats: {
        total: number;
        draft: number;
        published: number;
        archived: number;
    };
    user?: {
        name?: string;
        staff_id?: string;
        department?: string;
    };
};

type UploadForm = {
    title: string;
    description: string;
    document: File | null;
};

const STATUS_TABS: {
    value: string;
    label: string;
    countKey: keyof Props['stats'];
}[] = [
    { value: 'all', label: 'All documents', countKey: 'total' },
    { value: 'draft', label: 'Drafts', countKey: 'draft' },
    { value: 'published', label: 'Published', countKey: 'published' },
    { value: 'archived', label: 'Archived', countKey: 'archived' },
];

export default withDeferredPage(
    ClerkDisbursementsIndex,
    ['documents', 'stats'],
    (props) => (
        <ClerkLayout active="disbursements" user={props.user}>
            <PortalPageSkeleton metrics={4} />
        </ClerkLayout>
    ),
);

function ClerkDisbursementsIndex({ documents, filters, stats, user }: Props) {
    const [search, setSearch] = useState(filters.search);
    const [showUpload, setShowUpload] = useState(false);
    const [actionId, setActionId] = useState<number | null>(null);
    const fileInputRef = useRef<HTMLInputElement>(null);

    const form = useForm<UploadForm>({
        title: '',
        description: '',
        document: null,
    });

    const visit = (next: Partial<Props['filters']>, preserveState = true) => {
        router.get(
            '/clerk/disbursements',
            {
                search: next.search ?? search,
                status: next.status ?? filters.status,
            },
            {
                preserveScroll: true,
                preserveState,
                only: ['documents', 'filters'],
                replace: true,
            },
        );
    };

    const submitSearch = (event: FormEvent) => {
        event.preventDefault();
        visit({ search: search.trim() });
    };

    const openUpload = () => {
        form.clearErrors();
        setShowUpload(true);
    };

    const closeUpload = () => {
        if (form.processing) {
            return;
        }

        setShowUpload(false);
        form.reset();
        form.clearErrors();

        if (fileInputRef.current) {
            fileInputRef.current.value = '';
        }
    };

    const submitUpload = (event: FormEvent) => {
        event.preventDefault();

        form.post('/clerk/disbursements', {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                setShowUpload(false);
                form.reset();

                if (fileInputRef.current) {
                    fileInputRef.current.value = '';
                }
            },
        });
    };

    const runAction = (
        document: DisbursementDocumentData,
        action: 'publish' | 'archive',
    ) => {
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

        setActionId(document.id);
        router.post(
            url,
            {},
            {
                preserveScroll: true,
                onFinish: () => setActionId(null),
            },
        );
    };

    const uploadProgress = Math.min(
        100,
        Math.max(0, form.progress?.percentage ?? 0),
    );

    return (
        <ClerkLayout active="disbursements" user={user}>
            <Head title="Disbursement Documents" />

            <main className="mx-auto w-full max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <p className="text-xs font-semibold tracking-wide text-brand uppercase">
                            Financial transparency
                        </p>

                        <h1 className="mt-1 text-2xl font-semibold text-ink-strong">
                            Disbursement Documents
                        </h1>

                        <p className="mt-1 max-w-2xl text-sm text-ink-muted">
                            Upload PDF and CSV records, review them privately,
                            and publish selected documents to residents.
                        </p>
                    </div>

                    <button
                        type="button"
                        onClick={openUpload}
                        className="inline-flex min-h-10 items-center justify-center gap-2 rounded-lg bg-brand px-4 text-sm font-semibold text-white shadow-card transition hover:bg-brand-deep"
                    >
                        <UploadIcon />
                        Upload document
                    </button>
                </div>

                <section className="mt-6 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    <StatCard
                        label="All documents"
                        value={stats.total}
                        tone="slate"
                    />
                    <StatCard label="Drafts" value={stats.draft} tone="amber" />
                    <StatCard
                        label="Published"
                        value={stats.published}
                        tone="emerald"
                    />
                    <StatCard
                        label="Archived"
                        value={stats.archived}
                        tone="gray"
                    />
                </section>

                <section className="mt-6 overflow-hidden rounded-xl border border-line-soft bg-white shadow-card">
                    <div className="border-b border-line-soft p-4">
                        <div className="flex flex-wrap gap-2">
                            {STATUS_TABS.map((tab) => (
                                <button
                                    key={tab.value}
                                    type="button"
                                    onClick={() =>
                                        visit({ status: tab.value }, false)
                                    }
                                    className={`rounded-full border px-3 py-1.5 text-xs font-semibold transition ${
                                        filters.status === tab.value
                                            ? 'border-brand bg-brand text-white'
                                            : 'border-line-soft bg-white text-ink-muted hover:bg-canvas'
                                    }`}
                                >
                                    {tab.label} ({stats[tab.countKey]})
                                </button>
                            ))}
                        </div>

                        <form
                            onSubmit={submitSearch}
                            className="mt-3 flex flex-col gap-2 sm:flex-row"
                        >
                            <label className="relative flex-1">
                                <span className="sr-only">
                                    Search disbursement documents
                                </span>

                                <SearchIcon />

                                <input
                                    type="search"
                                    value={search}
                                    onChange={(event) =>
                                        setSearch(event.target.value)
                                    }
                                    placeholder="Search by title or file name"
                                    className="min-h-10 w-full rounded-lg border border-line bg-white py-2 pr-3 pl-9 text-sm transition outline-none focus:border-brand focus:ring-2 focus:ring-[#3b6fa8]/20"
                                />
                            </label>

                            <button
                                type="submit"
                                className="min-h-10 rounded-lg border border-line bg-white px-4 text-sm font-semibold text-ink transition hover:bg-canvas"
                            >
                                Search
                            </button>

                            {(filters.search !== '' ||
                                filters.status !== 'all') && (
                                <button
                                    type="button"
                                    onClick={() => {
                                        setSearch('');
                                        router.get(
                                            '/clerk/disbursements',
                                            {},
                                            {
                                                preserveScroll: true,
                                                only: ['documents', 'filters'],
                                                replace: true,
                                            },
                                        );
                                    }}
                                    className="min-h-10 rounded-lg px-3 text-sm font-medium text-ink-muted transition hover:text-ink-strong"
                                >
                                    Clear
                                </button>
                            )}
                        </form>
                    </div>

                    {documents.data.length === 0 ? (
                        <div className="px-4 py-16 text-center">
                            <div className="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-secondary text-ink-faint">
                                <DocumentIcon />
                            </div>

                            <h2 className="mt-3 text-sm font-semibold text-ink-strong">
                                No disbursement documents found
                            </h2>

                            <p className="mx-auto mt-1 max-w-md text-xs text-ink-muted">
                                Upload a PDF or CSV document, or adjust the
                                current filters.
                            </p>

                            <button
                                type="button"
                                onClick={openUpload}
                                className="mt-4 rounded-lg bg-brand px-4 py-2 text-xs font-semibold text-white transition hover:bg-brand-deep"
                            >
                                Upload document
                            </button>
                        </div>
                    ) : (
                        <div className="divide-y divide-gray-100">
                            {documents.data.map((document) => (
                                <DocumentRow
                                    key={document.id}
                                    document={document}
                                    busy={actionId === document.id}
                                    onAction={runAction}
                                />
                            ))}
                        </div>
                    )}

                    {documents.last_page > 1 && (
                        <div className="flex flex-wrap items-center justify-between gap-3 border-t border-line-soft px-4 py-4">
                            <p className="text-xs text-ink-muted">
                                Showing {documents.from ?? 0} to{' '}
                                {documents.to ?? 0} of {documents.total}{' '}
                                documents
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
                                                    only: [
                                                        'documents',
                                                        'filters',
                                                    ],
                                                },
                                            );
                                        }}
                                        className={`rounded-lg px-3 py-1.5 text-xs ${
                                            link.active
                                                ? 'bg-brand text-white'
                                                : link.url
                                                  ? 'border border-line text-ink-muted hover:bg-canvas'
                                                  : 'cursor-not-allowed border border-line-soft text-gray-300'
                                        }`}
                                        dangerouslySetInnerHTML={{
                                            __html: link.label,
                                        }}
                                    />
                                ))}
                            </div>
                        </div>
                    )}
                </section>
            </main>

            {showUpload && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
                    <div className="w-full max-w-lg overflow-hidden rounded-xl bg-white shadow-xl">
                        <div className="flex items-start justify-between border-b border-line-soft px-5 py-4">
                            <div>
                                <h2 className="text-lg font-semibold text-ink-strong">
                                    Upload disbursement document
                                </h2>

                                <p className="mt-1 text-xs text-ink-muted">
                                    PDF or CSV, up to 5 MB. New uploads stay in
                                    draft until published.
                                </p>
                            </div>

                            <button
                                type="button"
                                onClick={closeUpload}
                                disabled={form.processing}
                                className="flex h-8 w-8 items-center justify-center rounded-lg text-ink-faint transition hover:bg-secondary hover:text-ink disabled:opacity-50"
                                aria-label="Close upload form"
                            >
                                <CloseIcon />
                            </button>
                        </div>

                        <form
                            onSubmit={submitUpload}
                            className="space-y-4 px-5 py-5"
                        >
                            <label className="block">
                                <span className="text-xs font-semibold text-ink">
                                    Document title
                                </span>

                                <input
                                    value={form.data.title}
                                    onChange={(event) =>
                                        form.setData(
                                            'title',
                                            event.target.value,
                                        )
                                    }
                                    maxLength={255}
                                    required
                                    autoFocus
                                    placeholder="e.g. 2026 Educational Assistance Payout"
                                    className="mt-1.5 min-h-10 w-full rounded-lg border border-line px-3 text-sm transition outline-none focus:border-brand focus:ring-2 focus:ring-[#3b6fa8]/20"
                                />

                                {form.errors.title && (
                                    <p className="mt-1 text-xs text-red-600">
                                        {form.errors.title}
                                    </p>
                                )}
                            </label>

                            <label className="block">
                                <span className="text-xs font-semibold text-ink">
                                    Description{' '}
                                    <span className="font-normal text-ink-faint">
                                        (optional)
                                    </span>
                                </span>

                                <textarea
                                    value={form.data.description}
                                    onChange={(event) =>
                                        form.setData(
                                            'description',
                                            event.target.value,
                                        )
                                    }
                                    rows={3}
                                    maxLength={2000}
                                    placeholder="Add context for staff reviewing this document."
                                    className="mt-1.5 w-full resize-y rounded-lg border border-line px-3 py-2 text-sm transition outline-none focus:border-brand focus:ring-2 focus:ring-[#3b6fa8]/20"
                                />

                                {form.errors.description && (
                                    <p className="mt-1 text-xs text-red-600">
                                        {form.errors.description}
                                    </p>
                                )}
                            </label>

                            <label className="block">
                                <span className="text-xs font-semibold text-ink">
                                    PDF or CSV file
                                </span>

                                <input
                                    ref={fileInputRef}
                                    type="file"
                                    accept=".pdf,.csv,application/pdf,text/csv"
                                    required
                                    onChange={(event) =>
                                        form.setData(
                                            'document',
                                            event.target.files?.[0] ?? null,
                                        )
                                    }
                                    className="mt-1.5 block w-full cursor-pointer rounded-lg border border-line text-sm text-ink-muted file:mr-3 file:border-0 file:bg-canvas file:px-3 file:py-2.5 file:text-xs file:font-semibold file:text-ink"
                                />

                                {form.data.document && (
                                    <p className="mt-1 text-xs text-ink-muted">
                                        Selected: {form.data.document.name} (
                                        {formatFileSize(
                                            form.data.document.size,
                                        )}
                                        )
                                    </p>
                                )}

                                {form.errors.document && (
                                    <p className="mt-1 text-xs text-red-600">
                                        {form.errors.document}
                                    </p>
                                )}
                            </label>

                            {form.processing && (
                                <div>
                                    <div className="h-1.5 overflow-hidden rounded-full bg-secondary">
                                        <div
                                            className="h-full rounded-full bg-brand transition-all"
                                            style={{
                                                width: `${uploadProgress}%`,
                                            }}
                                        />
                                    </div>

                                    <p className="mt-1 text-right text-[11px] text-ink-muted">
                                        Uploading {uploadProgress}%
                                    </p>
                                </div>
                            )}

                            <div className="flex justify-end gap-2 border-t border-line-soft pt-4">
                                <button
                                    type="button"
                                    onClick={closeUpload}
                                    disabled={form.processing}
                                    className="min-h-10 rounded-lg border border-line px-4 text-sm font-semibold text-ink transition hover:bg-canvas disabled:opacity-50"
                                >
                                    Cancel
                                </button>

                                <button
                                    type="submit"
                                    disabled={
                                        form.processing ||
                                        form.data.document === null
                                    }
                                    className="min-h-10 rounded-lg bg-brand px-4 text-sm font-semibold text-white transition hover:bg-brand-deep disabled:cursor-not-allowed disabled:opacity-50"
                                >
                                    {form.processing
                                        ? 'Uploading...'
                                        : 'Upload draft'}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}
        </ClerkLayout>
    );
}

function DocumentRow({
    document,
    busy,
    onAction,
}: {
    document: DisbursementDocumentData;
    busy: boolean;
    onAction: (
        document: DisbursementDocumentData,
        action: 'publish' | 'archive',
    ) => void;
}) {
    const status = document.status as DisbursementDocumentStatus;

    return (
        <article className="flex flex-col gap-4 px-4 py-4 lg:flex-row lg:items-center lg:justify-between">
            <div className="min-w-0 flex-1">
                <div className="flex flex-wrap items-center gap-2">
                    <span
                        className={`rounded-full border px-2 py-0.5 text-[11px] font-semibold ${disbursementStatusClasses(status)}`}
                    >
                        {disbursementStatusLabel(status)}
                    </span>

                    <span className="rounded-lg border border-line-soft bg-canvas px-2 py-0.5 text-[11px] font-semibold text-ink-muted">
                        {disbursementFileType(document)}
                    </span>

                    <span className="text-[11px] text-ink-faint">
                        {formatFileSize(document.file_size)}
                    </span>
                </div>

                <h3 className="mt-2 truncate text-sm font-semibold text-ink-strong">
                    {document.title}
                </h3>

                {document.description && (
                    <p className="mt-1 line-clamp-2 max-w-3xl text-xs text-ink-muted">
                        {document.description}
                    </p>
                )}

                <div className="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-[11px] text-ink-muted">
                    <span className="max-w-full truncate">
                        {document.original_name}
                    </span>

                    <span>
                        Uploaded {formatDisbursementDate(document.created_at)}
                    </span>

                    {document.uploader_name && (
                        <span>By {document.uploader_name}</span>
                    )}

                    {document.published_at && (
                        <span>
                            Published{' '}
                            {formatDisbursementDate(document.published_at)}
                        </span>
                    )}

                    {document.archived_at && (
                        <span>
                            Archived{' '}
                            {formatDisbursementDate(document.archived_at)}
                        </span>
                    )}
                </div>
            </div>

            <div className="flex shrink-0 flex-wrap gap-2">
                <Link
                    href={document.preview_url}
                    className="inline-flex min-h-9 items-center justify-center rounded-lg border border-line bg-white px-3 text-xs font-semibold text-ink transition hover:bg-canvas"
                >
                    Preview
                </Link>

                <a
                    href={document.download_url}
                    className="inline-flex min-h-9 items-center justify-center rounded-lg border border-line bg-white px-3 text-xs font-semibold text-ink transition hover:bg-canvas"
                >
                    Download
                </a>

                {document.status === 'draft' && (
                    <button
                        type="button"
                        disabled={busy}
                        onClick={() => onAction(document, 'publish')}
                        className="inline-flex min-h-9 items-center justify-center rounded-lg bg-brand px-3 text-xs font-semibold text-white transition hover:bg-brand-deep disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        {busy ? 'Publishing...' : 'Publish'}
                    </button>
                )}

                {document.status !== 'archived' && (
                    <button
                        type="button"
                        disabled={busy}
                        onClick={() => onAction(document, 'archive')}
                        className="inline-flex min-h-9 items-center justify-center rounded-lg border border-amber-200 bg-amber-50 px-3 text-xs font-semibold text-amber-800 transition hover:bg-amber-100 disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        {busy ? 'Updating...' : 'Archive'}
                    </button>
                )}
            </div>
        </article>
    );
}

function StatCard({
    label,
    value,
    tone,
}: {
    label: string;
    value: number;
    tone: 'slate' | 'amber' | 'emerald' | 'gray';
}) {
    const tones = {
        slate: 'border-line-soft bg-white text-ink-strong',
        amber: 'border-amber-200 bg-amber-50 text-amber-900',
        emerald: 'border-brand-muted bg-brand-soft text-brand-deep',
        gray: 'border-line-soft bg-canvas text-ink',
    };

    return (
        <div className={`rounded-xl border p-4 ${tones[tone]}`}>
            <p className="text-xs font-medium opacity-70">{label}</p>
            <p className="mt-1 text-2xl font-semibold">{value}</p>
        </div>
    );
}

function UploadIcon() {
    return (
        <svg
            className="h-4 w-4"
            fill="none"
            viewBox="0 0 24 24"
            stroke="currentColor"
            strokeWidth="2"
            aria-hidden="true"
        >
            <path
                strokeLinecap="round"
                strokeLinejoin="round"
                d="M12 16V4m0 0L7 9m5-5 5 5M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2"
            />
        </svg>
    );
}

function SearchIcon() {
    return (
        <svg
            className="pointer-events-none absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-ink-faint"
            fill="none"
            viewBox="0 0 24 24"
            stroke="currentColor"
            strokeWidth="2"
            aria-hidden="true"
        >
            <circle cx="11" cy="11" r="7" />
            <path strokeLinecap="round" d="m20 20-3.5-3.5" />
        </svg>
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

function CloseIcon() {
    return (
        <svg
            className="h-4 w-4"
            fill="none"
            viewBox="0 0 24 24"
            stroke="currentColor"
            strokeWidth="2"
            aria-hidden="true"
        >
            <path strokeLinecap="round" d="M6 6l12 12M18 6 6 18" />
        </svg>
    );
}
