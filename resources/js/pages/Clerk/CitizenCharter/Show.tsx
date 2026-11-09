import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useRef, useState } from 'react';
import type { FormEvent } from 'react';
import {
    charterStatusBadge,
    formatCharterDate,
    formatFileSize,
    type CitizenCharterServiceData,
    type CitizenCharterVersionData,
} from '@/components/citizen-charter/charter';
import ClerkLayout from '@/layouts/ClerkLayout';
import PortalPageSkeleton from '@/components/PortalPageSkeleton';
import withDeferredPage from '@/components/withDeferredPage';

type Props = {
    service: CitizenCharterServiceData;
    user?: {
        name?: string;
        staff_id?: string;
        department?: string;
    };
};

type EditForm = {
    title: string;
    category: string;
    description: string;
    display_order: number;
};

type VersionForm = {
    document: File | null;
};

export default withDeferredPage(
    ClerkCitizenCharterShow,
    ['service'],
    (props) => (
        <ClerkLayout active="citizen-charter" user={props.user}>
            <PortalPageSkeleton list />
        </ClerkLayout>
    ),
);

function ClerkCitizenCharterShow({ service, user }: Props) {
    const page = usePage<{ flash?: { success?: string; error?: string } }>();
    const flash = page.props.flash;

    const [isEditing, setIsEditing] = useState(false);
    const [showVersionModal, setShowVersionModal] = useState(false);
    const [previewVersion, setPreviewVersion] =
        useState<CitizenCharterVersionData | null>(
            service.current_version ||
                (service.versions && service.versions[0]) ||
                null,
        );
    const versionInputRef = useRef<HTMLInputElement>(null);

    const editForm = useForm<EditForm>({
        title: service.title,
        category: service.category || '',
        description: service.description || '',
        display_order: service.display_order || 0,
    });

    const versionForm = useForm<VersionForm>({
        document: null,
    });

    const handleEditSubmit = (e: FormEvent) => {
        e.preventDefault();
        editForm.put(
            service.update_url || `/clerk/citizen-charter/${service.id}`,
            {
                preserveScroll: true,
                onSuccess: () => setIsEditing(false),
            },
        );
    };

    const handleVersionSubmit = (e: FormEvent) => {
        e.preventDefault();
        versionForm.post(
            service.upload_version_url ||
                `/clerk/citizen-charter/${service.id}/versions`,
            {
                forceFormData: true,
                preserveScroll: true,
                onSuccess: () => {
                    versionForm.reset();
                    if (versionInputRef.current) {
                        versionInputRef.current.value = '';
                    }
                    setShowVersionModal(false);
                },
            },
        );
    };

    const handlePublish = () => {
        if (
            confirm(
                "Are you sure you want to publish this Citizen's Charter service? The latest draft document revision will become immediately visible to all residents.",
            )
        ) {
            router.post(
                service.publish_url ||
                    `/clerk/citizen-charter/${service.id}/publish`,
                {},
                {
                    preserveScroll: true,
                },
            );
        }
    };

    const handleArchive = () => {
        if (
            confirm(
                'Are you sure you want to archive this service? It will be immediately removed from the resident portal while preserving all historical revisions.',
            )
        ) {
            router.post(
                service.archive_url ||
                    `/clerk/citizen-charter/${service.id}/archive`,
                {},
                {
                    preserveScroll: true,
                },
            );
        }
    };

    const badge = charterStatusBadge(service.status);

    return (
        <ClerkLayout active="citizen-charter" user={user}>
            <Head title={`${service.title} | Citizen's Charter`} />

            <div className="mx-auto max-w-6xl space-y-6">
                {/* BREADCRUMB */}
                <div className="flex items-center gap-2 text-xs text-ink-muted">
                    <Link
                        href="/clerk/citizen-charter"
                        className="hover:text-brand"
                    >
                        Citizen&apos;s Charter
                    </Link>
                    <span>/</span>
                    <span className="font-semibold text-ink-strong">
                        {service.title}
                    </span>
                </div>

                {/* FLASH NOTICES */}
                {flash?.success && (
                    <div className="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800">
                        {flash.success}
                    </div>
                )}
                {flash?.error && (
                    <div className="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-800">
                        {flash.error}
                    </div>
                )}

                {/* HEADER CARD */}
                <div className="rounded-xl border border-line-soft bg-white p-6 shadow-card">
                    <div className="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
                        <div className="space-y-1">
                            <div className="flex flex-wrap items-center gap-2">
                                <span
                                    className={`inline-flex rounded-full border px-2.5 py-0.5 text-xs font-semibold ${badge.classes}`}
                                >
                                    {badge.label}
                                </span>
                                {service.category && (
                                    <span className="inline-flex rounded-full bg-secondary px-2.5 py-0.5 text-xs font-medium text-ink">
                                        {service.category}
                                    </span>
                                )}
                                <span className="text-xs text-ink-faint">
                                    Display Order: {service.display_order}
                                </span>
                            </div>

                            <h1 className="text-2xl font-semibold text-ink-strong">
                                {service.title}
                            </h1>

                            {service.description && (
                                <p className="text-sm leading-relaxed text-ink-muted">
                                    {service.description}
                                </p>
                            )}

                            <div className="flex flex-wrap gap-4 pt-2 text-xs text-ink-muted">
                                <div>
                                    Created:{' '}
                                    <span className="font-semibold text-ink">
                                        {formatCharterDate(service.created_at)}
                                    </span>
                                </div>
                                {service.published_at && (
                                    <div>
                                        Published:{' '}
                                        <span className="font-semibold text-ink">
                                            {formatCharterDate(
                                                service.published_at,
                                            )}
                                        </span>
                                    </div>
                                )}
                                {service.creator_name && (
                                    <div>
                                        Encoded By:{' '}
                                        <span className="font-semibold text-ink">
                                            {service.creator_name}
                                        </span>
                                    </div>
                                )}
                            </div>
                        </div>

                        {/* ACTION BUTTONS */}
                        <div className="flex flex-wrap items-center gap-2">
                            {service.status !== 'archived' && (
                                <>
                                    <button
                                        type="button"
                                        onClick={() => setIsEditing(!isEditing)}
                                        className="rounded-lg border border-line px-3 py-2 text-xs font-semibold text-ink transition hover:bg-secondary"
                                    >
                                        {isEditing
                                            ? 'Cancel Edit'
                                            : 'Edit Details'}
                                    </button>

                                    <button
                                        type="button"
                                        onClick={() =>
                                            setShowVersionModal(true)
                                        }
                                        className="rounded-lg border border-blue-300 bg-blue-50 px-3 py-2 text-xs font-semibold text-blue-700 transition hover:bg-blue-100"
                                    >
                                        Upload Revision
                                    </button>

                                    <button
                                        type="button"
                                        onClick={handlePublish}
                                        className="rounded-lg bg-brand px-3.5 py-2 text-xs font-semibold text-white transition hover:bg-brand-deep"
                                    >
                                        {service.is_published
                                            ? 'Re-Publish'
                                            : 'Publish Live'}
                                    </button>

                                    <button
                                        type="button"
                                        onClick={handleArchive}
                                        className="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-xs font-semibold text-red-600 transition hover:bg-red-100"
                                    >
                                        Archive
                                    </button>
                                </>
                            )}
                        </div>
                    </div>

                    {/* METADATA EDIT FORM */}
                    {isEditing && (
                        <form
                            onSubmit={handleEditSubmit}
                            className="mt-6 space-y-4 border-t border-line-soft pt-5"
                        >
                            <h3 className="text-sm font-semibold text-ink-strong">
                                Edit Service Information
                            </h3>

                            <div>
                                <label className="block text-xs font-semibold text-ink">
                                    Service Title{' '}
                                    <span className="text-red-500">*</span>
                                </label>
                                <input
                                    type="text"
                                    required
                                    value={editForm.data.title}
                                    onChange={(e) =>
                                        editForm.setData(
                                            'title',
                                            e.target.value,
                                        )
                                    }
                                    className="mt-1 w-full rounded-lg border border-line px-3 py-2 text-xs focus:border-brand focus:outline-hidden"
                                />
                                {editForm.errors.title && (
                                    <p className="mt-1 text-xs text-red-600">
                                        {editForm.errors.title}
                                    </p>
                                )}
                            </div>

                            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                <div>
                                    <label className="block text-xs font-semibold text-ink">
                                        Category
                                    </label>
                                    <input
                                        type="text"
                                        value={editForm.data.category}
                                        onChange={(e) =>
                                            editForm.setData(
                                                'category',
                                                e.target.value,
                                            )
                                        }
                                        className="mt-1 w-full rounded-lg border border-line px-3 py-2 text-xs focus:border-brand focus:outline-hidden"
                                    />
                                </div>
                                <div>
                                    <label className="block text-xs font-semibold text-ink">
                                        Display Order
                                    </label>
                                    <input
                                        type="number"
                                        min="0"
                                        value={editForm.data.display_order}
                                        onChange={(e) =>
                                            editForm.setData(
                                                'display_order',
                                                parseInt(e.target.value) || 0,
                                            )
                                        }
                                        className="mt-1 w-full rounded-lg border border-line px-3 py-2 text-xs focus:border-brand focus:outline-hidden"
                                    />
                                </div>
                            </div>

                            <div>
                                <label className="block text-xs font-semibold text-ink">
                                    Description / Summary
                                </label>
                                <textarea
                                    rows={3}
                                    value={editForm.data.description}
                                    onChange={(e) =>
                                        editForm.setData(
                                            'description',
                                            e.target.value,
                                        )
                                    }
                                    className="mt-1 w-full rounded-lg border border-line px-3 py-2 text-xs focus:border-brand focus:outline-hidden"
                                />
                            </div>

                            <div className="flex items-center gap-2">
                                <button
                                    type="submit"
                                    disabled={editForm.processing}
                                    className="rounded-lg bg-brand px-4 py-2 text-xs font-semibold text-white hover:bg-brand-deep disabled:opacity-50"
                                >
                                    {editForm.processing
                                        ? 'Saving...'
                                        : 'Save Changes'}
                                </button>
                                <button
                                    type="button"
                                    onClick={() => setIsEditing(false)}
                                    className="rounded-lg border border-line px-4 py-2 text-xs font-semibold text-ink hover:bg-secondary"
                                >
                                    Cancel
                                </button>
                            </div>
                        </form>
                    )}
                </div>

                {/* PDF PREVIEW & VERSION HISTORY GRID */}
                <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                    {/* PREVIEW CONTAINER */}
                    <div className="space-y-3 lg:col-span-2">
                        <div className="flex items-center justify-between">
                            <h2 className="text-sm font-semibold text-ink-strong">
                                Document Preview{' '}
                                {previewVersion &&
                                    `(Version ${previewVersion.version_number})`}
                            </h2>
                            {previewVersion?.download_url && (
                                <div className="flex items-center gap-2">
                                    {previewVersion.file_url && (
                                        <a
                                            href={previewVersion.file_url}
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            className="rounded-lg border border-line px-2.5 py-1 text-xs font-semibold text-ink hover:bg-secondary"
                                        >
                                            Open in New Tab 
                                        </a>
                                    )}
                                    <a
                                        href={previewVersion.download_url}
                                        className="rounded-lg bg-brand px-2.5 py-1 text-xs font-semibold text-white hover:bg-brand-deep"
                                    >
                                        Download PDF 
                                    </a>
                                </div>
                            )}
                        </div>

                        <div className="overflow-hidden rounded-xl border border-line-soft bg-white shadow-card">
                            {previewVersion?.file_url ? (
                                <iframe
                                    src={previewVersion.file_url}
                                    className="h-[600px] w-full border-0"
                                    title={`Citizen's Charter PDF - ${service.title}`}
                                />
                            ) : (
                                <div className="p-12 text-center text-xs text-ink-faint">
                                    No document version selected for preview.
                                </div>
                            )}
                        </div>
                    </div>

                    {/* VERSION HISTORY SIDEBAR */}
                    <div className="space-y-3">
                        <h2 className="text-sm font-semibold text-ink-strong">
                            Version History ({service.versions?.length || 0})
                        </h2>

                        <div className="space-y-2">
                            {service.versions?.map((ver) => {
                                const isCurrentActive =
                                    previewVersion?.id === ver.id;
                                const isLivePublished =
                                    service.current_version?.id === ver.id &&
                                    service.is_published;
                                const verBadge = charterStatusBadge(ver.status);

                                return (
                                    <div
                                        key={ver.id}
                                        onClick={() => setPreviewVersion(ver)}
                                        className={`cursor-pointer rounded-xl border p-3.5 transition ${
                                            isCurrentActive
                                                ? 'border-brand bg-[#f0f6ef] shadow-xs'
                                                : 'border-line-soft bg-white hover:border-line hover:bg-canvas'
                                        }`}
                                    >
                                        <div className="flex items-center justify-between">
                                            <div className="flex items-center gap-1.5 text-xs font-semibold text-ink-strong">
                                                <span>
                                                    Version {ver.version_number}
                                                </span>
                                                {isLivePublished && (
                                                    <span className="py-0.2 rounded-full bg-emerald-100 px-1.5 text-[9px] font-semibold text-emerald-800">
                                                        LIVE
                                                    </span>
                                                )}
                                            </div>

                                            <span
                                                className={`py-0.2 rounded-full border px-2 text-[10px] font-semibold ${verBadge.classes}`}
                                            >
                                                {verBadge.label}
                                            </span>
                                        </div>

                                        <p className="mt-1 truncate text-[11px] text-ink-muted">
                                            {ver.original_name} (
                                            {formatFileSize(ver.file_size)})
                                        </p>

                                        <div className="mt-2 flex items-center justify-between text-[10px] text-ink-faint">
                                            <span>
                                                {formatCharterDate(
                                                    ver.created_at,
                                                )}
                                            </span>
                                            {ver.uploader_name && (
                                                <span>
                                                    By {ver.uploader_name}
                                                </span>
                                            )}
                                        </div>
                                    </div>
                                );
                            })}
                        </div>
                    </div>
                </div>
            </div>

            {/* UPLOAD REVISION MODAL */}
            {showVersionModal && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
                    <div className="w-full max-w-md rounded-xl bg-white p-6 shadow-xl">
                        <div className="flex items-center justify-between border-b border-line-soft pb-3">
                            <h2 className="text-base font-semibold text-ink-strong">
                                Upload Replacement Document
                            </h2>
                            <button
                                type="button"
                                onClick={() => setShowVersionModal(false)}
                                className="text-ink-faint hover:text-ink-muted"
                            >
                                
                            </button>
                        </div>

                        <form
                            onSubmit={handleVersionSubmit}
                            className="mt-4 space-y-4"
                        >
                            <div className="rounded-xl border border-blue-200 bg-blue-50 p-3 text-xs leading-relaxed text-blue-800">
                                <strong>Safe Versioning:</strong> Uploading a
                                new PDF creates an unreleased draft revision.
                                Your current published document remains
                                accessible to residents until you explicitly
                                choose to publish the new version.
                            </div>

                            <div>
                                <label className="block text-xs font-semibold text-ink">
                                    New PDF Document{' '}
                                    <span className="text-red-500">*</span>
                                </label>
                                <input
                                    ref={versionInputRef}
                                    type="file"
                                    required
                                    accept=".pdf,application/pdf"
                                    onChange={(e) =>
                                        versionForm.setData(
                                            'document',
                                            e.target.files?.[0] || null,
                                        )
                                    }
                                    className="mt-1 block w-full text-xs text-ink-muted file:mr-3 file:rounded-lg file:border-0 file:bg-brand-soft file:px-3 file:py-1.5 file:text-xs file:font-semibold file:text-brand hover:file:bg-[#d6e4f2]"
                                />
                                <p className="mt-1 text-[11px] text-ink-faint">
                                    PDF only. Max 2 MB per runtime limit.
                                </p>
                                {versionForm.errors.document && (
                                    <p className="mt-1 text-xs text-red-600">
                                        {versionForm.errors.document}
                                    </p>
                                )}
                            </div>

                            <div className="flex items-center justify-end gap-2 border-t border-line-soft pt-4">
                                <button
                                    type="button"
                                    onClick={() => setShowVersionModal(false)}
                                    className="rounded-lg border border-line px-4 py-2 text-xs font-semibold text-ink transition hover:bg-secondary"
                                >
                                    Cancel
                                </button>
                                <button
                                    type="submit"
                                    disabled={versionForm.processing}
                                    className="rounded-lg bg-brand px-4 py-2 text-xs font-semibold text-white transition hover:bg-brand-deep disabled:opacity-50"
                                >
                                    {versionForm.processing
                                        ? 'Uploading...'
                                        : 'Upload Revision'}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}
        </ClerkLayout>
    );
}
