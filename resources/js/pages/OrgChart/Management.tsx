import BarangayOrgChart from '@/components/org-chart/BarangayOrgChart';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useRef, useState } from 'react';
import type { FormEvent } from 'react';
import {
    collectDescendantIds,
    findOrgChartNode,
    formatOrgChartDate,
    orgChartStatusBadge,
    type OrgChartNode,
    type OrgChartPosition,
    type OrgChartStats,
} from '@/components/org-chart/orgChart';
import AdminLayout from '@/layouts/AdminLayout';
import ClerkLayout from '@/layouts/ClerkLayout';
import PortalPageSkeleton from '@/components/PortalPageSkeleton';
import withDeferredPage from '@/components/withDeferredPage';

type Props = {
    tree: OrgChartNode[];
    positions: OrgChartPosition[];
    stats: OrgChartStats;
    portal: 'admin' | 'clerk';
    user?: {
        name?: string;
        staff_id?: string;
        department?: string;
    };
};

type FormState = {
    title: string;
    assigned_person: string;
    parent_id: string;
    photo: File | null;
};

export default withDeferredPage(
    OrgChartManagement,
    ['tree', 'positions', 'stats'],
    (props) =>
        props.portal === 'admin' ? (
            <AdminLayout active="org-chart">
                <PortalPageSkeleton metrics={4} list />
            </AdminLayout>
        ) : (
            <ClerkLayout active="org-chart" user={props.user}>
                <PortalPageSkeleton metrics={4} list />
            </ClerkLayout>
        ),
);

function OrgChartManagement({ tree, positions, stats, portal, user }: Props) {
    const page = usePage<{ flash?: { success?: string; error?: string } }>();
    const flash = page.props.flash;

    const base = portal === 'admin' ? '/admin/org-chart' : '/clerk/org-chart';

    const [modalOpen, setModalOpen] = useState(false);
    const [editing, setEditing] = useState<OrgChartPosition | null>(null);
    const photoRef = useRef<HTMLInputElement>(null);

    const form = useForm<FormState>({
        title: '',
        assigned_person: '',
        parent_id: '',
        photo: null,
    });

    const editingNode = editing ? findOrgChartNode(tree, editing.id) : null;
    const excludedIds = new Set<number>();

    if (editing) {
        excludedIds.add(editing.id);

        if (editingNode) {
            collectDescendantIds(editingNode).forEach((id) =>
                excludedIds.add(id),
            );
        }
    }

    const parentOptions = positions.filter(
        (position) => !excludedIds.has(position.id),
    );

    const openCreate = () => {
        setEditing(null);
        form.setData({
            title: '',
            assigned_person: '',
            parent_id: '',
            photo: null,
        });
        form.clearErrors();
        setModalOpen(true);
    };

    const openEdit = (position: OrgChartPosition) => {
        setEditing(position);
        form.setData({
            title: position.title,
            assigned_person: position.assigned_person ?? '',
            parent_id:
                position.parent_id !== null ? String(position.parent_id) : '',
            photo: null,
        });
        form.clearErrors();
        setModalOpen(true);
    };

    const closeModal = () => {
        setModalOpen(false);
        setEditing(null);
        form.reset();
        form.clearErrors();

        if (photoRef.current) {
            photoRef.current.value = '';
        }
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();

        const options = {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => closeModal(),
        };

        // PHP does not reliably parse multipart PUT bodies. POST the form and
        // let Laravel route edits through its PUT method override instead.
        form.transform((data) =>
            editing ? { ...data, _method: 'put' } : data,
        );
        form.post(editing ? `${base}/${editing.id}` : base, options);
    };

    const publish = (position: OrgChartPosition) => {
        if (
            !window.confirm(
                `Publish "${position.title}" to the resident organizational chart?`,
            )
        ) {
            return;
        }

        router.post(
            `${base}/${position.id}/publish`,
            {},
            { preserveScroll: true },
        );
    };

    const archive = (position: OrgChartPosition) => {
        if (
            !window.confirm(
                `Archive "${position.title}"? It will be hidden from residents.`,
            )
        ) {
            return;
        }

        router.post(
            `${base}/${position.id}/archive`,
            {},
            { preserveScroll: true },
        );
    };

    const remove = (position: OrgChartPosition) => {
        if (
            !window.confirm(
                `Delete "${position.title}"? This cannot be undone.`,
            )
        ) {
            return;
        }

        router.delete(`${base}/${position.id}`, { preserveScroll: true });
    };

    const content = (
        <>
            <Head title="Organizational Chart | Barangay Management" />

            <div className="mx-auto max-w-6xl space-y-6">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h1 className="text-xl font-bold text-gray-900 sm:text-2xl">
                            Organizational Chart
                        </h1>
                        <p className="mt-1 text-sm text-gray-500">
                            Maintain the barangay position structure, photos,
                            and published resident chart.
                        </p>
                    </div>

                    <button
                        type="button"
                        onClick={openCreate}
                        className="inline-flex items-center justify-center gap-2 rounded-lg bg-[#3b6fa8] px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-[#2a5279]"
                    >
                        <span
                            aria-hidden="true"
                            className="text-base leading-none"
                        >
                            +
                        </span>
                        <span>Add Position</span>
                    </button>
                </div>

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

                <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    <StatTile label="Total Positions" value={stats.total} />
                    <StatTile label="Drafts" value={stats.draft} tone="blue" />
                    <StatTile
                        label="Published"
                        value={stats.published}
                        tone="green"
                    />
                    <StatTile
                        label="Archived"
                        value={stats.archived}
                        tone="gray"
                    />
                </div>

                {tree.length > 0 && (
                    <BarangayOrgChart
                        tree={tree}
                        onSelect={openEdit}
                        showStatus
                    />
                )}

                <div className="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm sm:p-5">
                    <div className="mb-4 flex flex-col gap-2 border-b border-gray-100 pb-4 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <h2 className="text-sm font-bold text-gray-900">
                                Position Structure
                            </h2>
                            <p className="mt-1 text-xs text-gray-500">
                                Saving a published position returns it to draft
                                so changes can be reviewed before republishing.
                            </p>
                        </div>
                        <span className="text-xs font-medium text-gray-400">
                            {stats.total} position
                            {stats.total === 1 ? '' : 's'}
                        </span>
                    </div>

                    {tree.length === 0 ? (
                        <div className="p-10 text-center">
                            <BuildingIcon className="mx-auto h-10 w-10 text-gray-300" />
                            <h3 className="mt-3 text-sm font-bold text-gray-800">
                                No positions yet
                            </h3>
                            <p className="mt-1 text-xs text-gray-500">
                                Add the first position to begin building the
                                barangay organizational chart.
                            </p>
                        </div>
                    ) : (
                        <div className="space-y-3">
                            {tree.map((node) => (
                                <PositionNode
                                    key={node.id}
                                    node={node}
                                    depth={0}
                                    onEdit={openEdit}
                                    onPublish={publish}
                                    onArchive={archive}
                                    onDelete={remove}
                                />
                            ))}
                        </div>
                    )}
                </div>
            </div>

            {modalOpen && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
                    <div className="w-full max-w-lg rounded-2xl bg-white p-6 shadow-xl">
                        <div className="flex items-center justify-between border-b border-gray-200 pb-3">
                            <h2 className="text-base font-bold text-gray-900">
                                {editing ? 'Edit Position' : 'Add Position'}
                            </h2>
                            <button
                                type="button"
                                onClick={closeModal}
                                className="text-gray-400 hover:text-gray-600"
                                aria-label="Close"
                            >
                                ✕
                            </button>
                        </div>

                        <form onSubmit={submit} className="mt-4 space-y-4">
                            <div>
                                <label className="block text-xs font-semibold text-gray-700">
                                    Position Title{' '}
                                    <span className="text-red-500">*</span>
                                </label>
                                <input
                                    type="text"
                                    required
                                    maxLength={255}
                                    placeholder="e.g. Punong Barangay"
                                    value={form.data.title}
                                    onChange={(event) =>
                                        form.setData(
                                            'title',
                                            event.target.value,
                                        )
                                    }
                                    className="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-xs focus:border-[#3b6fa8] focus:outline-hidden"
                                />
                                {form.errors.title && (
                                    <p className="mt-1 text-xs text-red-600">
                                        {form.errors.title}
                                    </p>
                                )}
                            </div>

                            <div>
                                <label className="block text-xs font-semibold text-gray-700">
                                    Assigned Person Name (Optional)
                                </label>
                                <input
                                    type="text"
                                    maxLength={255}
                                    placeholder="e.g. Hon. Juan Dela Cruz"
                                    value={form.data.assigned_person}
                                    onChange={(event) =>
                                        form.setData(
                                            'assigned_person',
                                            event.target.value,
                                        )
                                    }
                                    className="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-xs focus:border-[#3b6fa8] focus:outline-hidden"
                                />
                                <p className="mt-1 text-[11px] text-gray-400">
                                    Leave empty if the position is currently
                                    vacant.
                                </p>
                                {form.errors.assigned_person && (
                                    <p className="mt-1 text-xs text-red-600">
                                        {form.errors.assigned_person}
                                    </p>
                                )}
                            </div>

                            <div>
                                <label className="block text-xs font-semibold text-gray-700">
                                    Parent Position (Optional)
                                </label>
                                <select
                                    value={form.data.parent_id}
                                    onChange={(event) =>
                                        form.setData(
                                            'parent_id',
                                            event.target.value,
                                        )
                                    }
                                    className="mt-1 w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-xs focus:border-[#3b6fa8] focus:outline-hidden"
                                >
                                    <option value="">
                                        None — top-level position
                                    </option>
                                    {parentOptions.map((position) => (
                                        <option
                                            key={position.id}
                                            value={String(position.id)}
                                        >
                                            {position.title}
                                        </option>
                                    ))}
                                </select>
                                {form.errors.parent_id && (
                                    <p className="mt-1 text-xs text-red-600">
                                        {form.errors.parent_id}
                                    </p>
                                )}
                            </div>

                            <div>
                                <label className="block text-xs font-semibold text-gray-700">
                                    Official Photo (Optional)
                                </label>
                                <input
                                    ref={photoRef}
                                    type="file"
                                    accept="image/*"
                                    onChange={(event) =>
                                        form.setData(
                                            'photo',
                                            event.target.files?.[0] ?? null,
                                        )
                                    }
                                    className="mt-1 block w-full text-xs text-gray-500 file:mr-3 file:rounded-lg file:border-0 file:bg-[#eaf1f8] file:px-3 file:py-1.5 file:text-xs file:font-semibold file:text-[#3b6fa8] hover:file:bg-[#d6e4f2]"
                                />
                                <p className="mt-1 text-[11px] text-gray-400">
                                    Stored privately. Maximum 2 MB. Leave empty
                                    to keep the current photo, or to show a
                                    neutral placeholder.
                                </p>
                                {form.errors.photo && (
                                    <p className="mt-1 text-xs text-red-600">
                                        {form.errors.photo}
                                    </p>
                                )}
                            </div>

                            {editing && editing.status === 'published' && (
                                <div className="rounded-xl border border-blue-200 bg-blue-50 p-3 text-xs leading-relaxed text-blue-800">
                                    This position is currently published. Saving
                                    changes will return it to draft so the
                                    revised version must be published
                                    explicitly.
                                </div>
                            )}

                            <div className="flex items-center justify-end gap-2 border-t border-gray-200 pt-4">
                                <button
                                    type="button"
                                    onClick={closeModal}
                                    className="rounded-lg border border-gray-300 px-4 py-2 text-xs font-semibold text-gray-700 transition hover:bg-gray-100"
                                >
                                    Cancel
                                </button>
                                <button
                                    type="submit"
                                    disabled={form.processing}
                                    className="rounded-lg bg-[#3b6fa8] px-4 py-2 text-xs font-semibold text-white transition hover:bg-[#2a5279] disabled:opacity-50"
                                >
                                    {form.processing
                                        ? 'Saving...'
                                        : editing
                                          ? 'Save as Draft'
                                          : 'Create Draft'}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}
        </>
    );

    return portal === 'admin' ? (
        <AdminLayout active="org-chart">{content}</AdminLayout>
    ) : (
        <ClerkLayout active="org-chart" user={user}>
            {content}
        </ClerkLayout>
    );
}

function StatTile({
    label,
    value,
    tone = 'default',
}: {
    label: string;
    value: number;
    tone?: 'default' | 'blue' | 'green' | 'gray';
}) {
    const toneClass = {
        default: 'border-gray-200 bg-white text-gray-900',
        blue: 'border-blue-200 bg-blue-50/50 text-blue-900',
        green: 'border-emerald-200 bg-emerald-50/50 text-emerald-900',
        gray: 'border-gray-200 bg-gray-50 text-gray-700',
    }[tone];

    return (
        <div className={`rounded-xl border p-4 shadow-sm ${toneClass}`}>
            <div className="text-xs font-medium tracking-wider uppercase opacity-70">
                {label}
            </div>
            <div className="mt-2 text-2xl font-bold">{value}</div>
        </div>
    );
}

function BuildingIcon({ className }: { className?: string }) {
    return (
        <svg
            viewBox="0 0 24 24"
            fill="none"
            className={className}
            stroke="currentColor"
            strokeWidth="1.6"
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
        >
            <path d="M4 21V6l8-3 8 3v15" />
            <path d="M2 21h20" />
            <path d="M9 10h1M14 10h1M9 14h1M14 14h1M9 18h1M14 18h1" />
        </svg>
    );
}

function PositionNode({
    node,
    depth,
    onEdit,
    onPublish,
    onArchive,
    onDelete,
}: {
    node: OrgChartNode;
    depth: number;
    onEdit: (position: OrgChartPosition) => void;
    onPublish: (position: OrgChartPosition) => void;
    onArchive: (position: OrgChartPosition) => void;
    onDelete: (position: OrgChartPosition) => void;
}) {
    const badge = orgChartStatusBadge(node.status);
    const hasChildren = node.children.length > 0;

    return (
        <div
            className={
                depth > 0
                    ? 'ml-3 border-l border-gray-200 pl-3 sm:ml-5 sm:pl-5'
                    : ''
            }
        >
            <div className="rounded-xl border border-gray-200 bg-white p-3 shadow-xs sm:p-4">
                <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div className="flex min-w-0 items-start gap-3">
                        <PositionPhoto node={node} size="sm" />

                        <div className="min-w-0">
                            <div className="flex flex-wrap items-center gap-2">
                                <h3 className="truncate text-sm font-bold text-gray-900">
                                    {node.title}
                                </h3>
                                <span
                                    className={`inline-flex rounded-full border px-2 py-0.5 text-[10px] font-bold ${badge.classes}`}
                                >
                                    {badge.label}
                                </span>
                            </div>

                            <p className="mt-0.5 text-xs font-medium text-gray-700">
                                {node.assigned_person ? (
                                    <span>{node.assigned_person}</span>
                                ) : (
                                    <span className="text-gray-400 italic">
                                        Vacant
                                    </span>
                                )}
                            </p>

                            <p className="mt-1 text-[11px] text-gray-400">
                                {node.has_photo
                                    ? 'Official photo on file'
                                    : 'No photo on file'}
                                {' · '}
                                Updated {formatOrgChartDate(node.updated_at)}
                            </p>
                        </div>
                    </div>

                    <div className="flex flex-wrap items-center gap-2">
                        <button
                            type="button"
                            onClick={() => onEdit(node)}
                            className="rounded-md border border-gray-300 px-2.5 py-1 text-xs font-semibold text-gray-700 transition hover:bg-gray-100"
                        >
                            Edit
                        </button>

                        {node.status !== 'published' && (
                            <button
                                type="button"
                                onClick={() => onPublish(node)}
                                className="rounded-md bg-[#3b6fa8] px-2.5 py-1 text-xs font-semibold text-white transition hover:bg-[#2a5279]"
                            >
                                {node.status === 'archived'
                                    ? 'Republish'
                                    : 'Publish'}
                            </button>
                        )}

                        {node.status !== 'archived' && (
                            <button
                                type="button"
                                onClick={() => onArchive(node)}
                                className="rounded-md border border-amber-200 bg-amber-50 px-2.5 py-1 text-xs font-semibold text-amber-700 transition hover:bg-amber-100"
                            >
                                Archive
                            </button>
                        )}

                        <button
                            type="button"
                            onClick={() => onDelete(node)}
                            disabled={hasChildren}
                            title={
                                hasChildren
                                    ? 'Reassign or remove child positions first.'
                                    : 'Delete position'
                            }
                            className="rounded-md border border-red-200 bg-red-50 px-2.5 py-1 text-xs font-semibold text-red-600 transition hover:bg-red-100 disabled:cursor-not-allowed disabled:border-gray-200 disabled:bg-gray-50 disabled:text-gray-300"
                        >
                            Delete
                        </button>
                    </div>
                </div>
            </div>

            {hasChildren && (
                <div className="mt-3 space-y-3">
                    {node.children.map((child) => (
                        <PositionNode
                            key={child.id}
                            node={child}
                            depth={depth + 1}
                            onEdit={onEdit}
                            onPublish={onPublish}
                            onArchive={onArchive}
                            onDelete={onDelete}
                        />
                    ))}
                </div>
            )}
        </div>
    );
}

function PositionPhoto({
    node,
    size,
}: {
    node: OrgChartNode;
    size: 'sm' | 'lg';
}) {
    const dimension = size === 'lg' ? 'h-20 w-20' : 'h-11 w-11';

    if (node.photo_url) {
        return (
            <img
                src={node.photo_url}
                alt={`${node.title} official photo`}
                loading="lazy"
                decoding="async"
                className={`${dimension} shrink-0 rounded-full border border-gray-200 object-cover`}
            />
        );
    }

    return (
        <div
            className={`${dimension} flex shrink-0 items-center justify-center rounded-full border border-dashed border-gray-300 bg-gray-50 text-gray-400`}
            aria-label="No photo on file"
        >
            <svg
                viewBox="0 0 24 24"
                fill="none"
                className={size === 'lg' ? 'h-9 w-9' : 'h-5 w-5'}
                stroke="currentColor"
                strokeWidth="1.6"
                strokeLinecap="round"
                strokeLinejoin="round"
                aria-hidden="true"
            >
                <circle cx="12" cy="8" r="4" />
                <path d="M4 21c.8-4.2 3.5-6 8-6s7.2 1.8 8 6" />
            </svg>
        </div>
    );
}
