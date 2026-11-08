import withDeferredPage from '@/components/withDeferredPage';
import { useState } from 'react';
import { Head, Link, useForm, router } from '@inertiajs/react';
import AnnouncementAttendanceOption from '@/components/announcements/AnnouncementAttendanceOption';
import AnnouncementImportanceBadge from '@/components/announcements/AnnouncementImportanceBadge';
import AnnouncementLifecycleTabs from '@/components/announcements/AnnouncementLifecycleTabs';
import AnnouncementReviewSummary from '@/components/announcements/AnnouncementReviewSummary';
import ProgramStatusSelect from '@/components/announcements/ProgramStatusSelect';
import MarkAnnouncementDoneButton from '@/components/announcements/MarkAnnouncementDoneButton';
import {
    ANNOUNCEMENT_IMPORTANCE_OPTIONS,
    attendanceUrl,
    canMarkAnnouncementDone,
    formatAnnouncementPostedAt,
    formatApprovalStatusLabel,
    formatAnnouncementStatusLabel,
    formatAnnouncementType,
    isAttendanceAvailable,
    isEventAnnouncement,
    toBoolean,
    type AnnouncementTab,
    type AnnouncementTabCounts,
} from '@/components/announcements/announcement';
import PwdLayout from '@/layouts/PwdLayout';
import PortalPageSkeleton from '@/components/PortalPageSkeleton';
import { toDateTimeLocalValue } from '@/lib/utils';

interface Announcement {
    id: number;
    title: string;
    content: string;
    category: string;
    importance?: string | null;
    type?: 'event' | 'informational';
    approval_status: 'pending' | 'approved' | 'rejected';
    program_status?: 'draft' | 'ongoing' | 'done' | null;
    is_published: boolean;
    rejection_reason?: string | null;
    requires_attendance: boolean;
    attendance_available?: boolean;
    event_start_at?: string | null;
    location?: string | null;
    completed_at?: string | null;
    completed_by?: number | null;
    completer?: { name: string } | null;
    created_at: string;
}

type AnnouncementKind = 'auto' | 'update' | 'program';

interface Props {
    announcements: Announcement[];
    activeTab: AnnouncementTab;
    tabCounts: AnnouncementTabCounts;
    kind?: AnnouncementKind;
    auth?: {
        user?: { name?: string };
        staffAssignments?: { department: string; position: string }[];
    };
}

export default withDeferredPage(
    PwdAnnouncementsIndex,
    ['announcements', 'tabCounts'],
    (props) => (
        <PwdLayout active={props.kind === 'update' ? 'updates' : props.kind === 'program' ? 'programs' : 'announcements'} user={props.auth?.user}>
            <PortalPageSkeleton rows={5} />
        </PwdLayout>
    ),
);

function PwdAnnouncementsIndex({
    announcements,
    activeTab,
    tabCounts,
    kind = 'auto',
    auth,
}: Props) {
    const isUpdate = kind === 'update';
    const isProgram = kind === 'program';
    const basePath = isUpdate
        ? '/pwd/updates'
        : isProgram
          ? '/pwd/programs'
          : '/pwd/announcements';
    const forcedFormType = isUpdate
        ? 'informational'
        : isProgram
          ? 'event'
          : null;

    const [showModal, setShowModal] = useState(false);
    const [editingItem, setEditingItem] = useState<Announcement | null>(null);
    const [deletingId, setDeletingId] = useState<number | null>(null);
    const [formType, setFormType] = useState<'event' | 'informational'>(
        forcedFormType ?? 'informational',
    );
    const [searchQuery, setSearchQuery] = useState('');
    const [selectedType, setSelectedType] = useState('ALL');
    const [selectedCategory, setSelectedCategory] = useState('ALL');
    const [selectedImportance, setSelectedImportance] = useState('ALL');

    const { data, setData, post, put, processing, errors, reset } = useForm({
        title: '',
        program_status: 'ongoing',
        content: '',
        category: isProgram ? 'Event' : 'General',
        importance: 'normal',
        requires_attendance: false,
        event_start_at: '',
        location: '',
    });

    const openCreateModal = () => {
        setEditingItem(null);
        setFormType(forcedFormType ?? 'informational');
        reset();
        if (isProgram) {
            setData('category', 'Event');
        }
        setShowModal(true);
    };

    const openEditModal = (item: Announcement) => {
        if (item.completed_at) {
            return;
        }

        const type = isEventAnnouncement(item) ? 'event' : 'informational';

        setEditingItem(item);
        setFormType(forcedFormType ?? type);
        setData({
            title: item.title,
            program_status:
                item.program_status === 'draft' ? 'draft' : 'ongoing',
            content: item.content,
            category: isProgram
                ? 'Event'
                : isUpdate && item.category === 'Event'
                  ? 'General'
                  : item.category || 'General',
            importance: item.importance || 'normal',
            requires_attendance: isProgram
                ? toBoolean(item.requires_attendance)
                : false,
            event_start_at: toDateTimeLocalValue(item.event_start_at),
            location: item.location || '',
        });
        setShowModal(true);
    };

    const handleTabChange = (tab: AnnouncementTab) => {
        router.get(
            basePath,
            { tab },
            { preserveScroll: true, preserveState: true, replace: true },
        );
    };

    const handleTypeChange = (type: 'event' | 'informational') => {
        setFormType(type);

        if (type === 'event') {
            setData('category', 'Event');
            return;
        }

        setData(
            'category',
            data.category === 'Event' ? 'General' : data.category,
        );
        setData('requires_attendance', false);
        setData('event_start_at', '');
        setData('location', '');
    };

    const handleCategoryChange = (category: string) => {
        setData('category', category);
    };

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        if (editingItem) {
            put(`${basePath}/${editingItem.id}`, {
                onSuccess: () => {
                    reset();
                    setShowModal(false);
                    setEditingItem(null);
                },
            });
        } else {
            post(basePath, {
                onSuccess: () => {
                    reset();
                    setShowModal(false);
                },
            });
        }
    };

    const handleDelete = (id: number) => {
        router.delete(`${basePath}/${id}`, {
            onSuccess: () => setDeletingId(null),
        });
    };

    const reviewType = isEventAnnouncement(data) ? 'event' : 'informational';

    const filteredAnnouncements = announcements.filter((item) => {
        const matchesSearch =
            item.title.toLowerCase().includes(searchQuery.toLowerCase()) ||
            item.content.toLowerCase().includes(searchQuery.toLowerCase());
        const matchesCategory =
            selectedCategory === 'ALL' || item.category === selectedCategory;
        const matchesImportance =
            selectedImportance === 'ALL' ||
            (item.importance || 'normal') === selectedImportance;
        const matchesType =
            selectedType === 'ALL' ||
            (isEventAnnouncement(item) ? 'event' : 'informational') ===
                selectedType;
        return (
            matchesSearch && matchesCategory && matchesImportance && matchesType
        );
    });

    return (
        <PwdLayout
            active={
                isUpdate ? 'updates' : isProgram ? 'programs' : 'announcements'
            }
            user={auth?.user}
        >
            <Head
                title={
                    isUpdate
                        ? 'PWD Sector Updates'
                        : isProgram
                          ? 'PWD Sector Programs'
                          : 'PWD Sector Announcements'
                }
            />

            <div className="space-y-5">
                <div className="flex flex-col gap-3 rounded-lg border-b border-line-soft bg-white p-4 shadow-card sm:flex-row sm:items-center sm:justify-between sm:p-5">
                    <div>
                        <h1 className="text-xl font-semibold text-ink-strong">
                            {isUpdate
                                ? 'PWD Sector Updates'
                                : isProgram
                                  ? 'PWD Sector Programs'
                                  : 'PWD Sector Announcements'}
                        </h1>
                        <p className="text-xs text-ink-muted">
                            {isUpdate
                                ? 'Publish informational notices and advisories for the PWD sector.'
                                : isProgram
                                  ? 'Create and manage scheduled PWD sector activities and attendance.'
                                  : 'Create, update, and manage announcements for the PWD sector.'}
                        </p>
                    </div>

                    <button
                        type="button"
                        onClick={openCreateModal}
                        className="inline-flex items-center justify-center gap-2 rounded-lg bg-brand px-4 py-2 text-xs font-semibold text-white shadow transition hover:bg-brand-deep"
                    >
                        {isUpdate
                            ? '+ Publish Update'
                            : isProgram
                              ? '+ Create Program'
                              : '+ Create PWD Announcement'}
                    </button>
                </div>


                    {!isUpdate && (
                        <div className="rounded-lg border border-line-soft bg-white p-3.5 shadow-card">
                            <AnnouncementLifecycleTabs
                                activeTab={activeTab}
                                counts={tabCounts}
                                onChange={handleTabChange}
                            />
                        </div>
                    )}

                    <div className="flex flex-col gap-3 rounded-lg border border-line-soft bg-white p-3.5 shadow-card sm:flex-row sm:items-center sm:justify-between">
                        <input
                            type="text"
                            value={searchQuery}
                            onChange={(e) => setSearchQuery(e.target.value)}
                            placeholder={
                                isUpdate
                                    ? 'Search updates by title or content...'
                                    : 'Search announcements by title or content...'
                            }
                            className="w-full rounded-lg border border-line px-3 py-1.5 text-xs outline-none focus:border-brand sm:w-72"
                        />

                        <div className="flex flex-wrap items-center gap-2">
                            {!isUpdate && (
                                <>
                                    <label className="text-xs font-medium text-ink-muted">
                                        Type:
                                    </label>
                                    <select
                                        value={selectedType}
                                        onChange={(e) =>
                                            setSelectedType(e.target.value)
                                        }
                                        className="rounded-lg border border-line px-3 py-1.5 text-xs outline-none focus:border-brand"
                                    >
                                        <option value="ALL">All Types</option>
                                        <option value="event">Events</option>
                                        <option value="informational">
                                            Informational Updates
                                        </option>
                                    </select>
                                </>
                            )}

                            <label className="ml-2 text-xs font-medium text-ink-muted">
                                Category:
                            </label>
                            <select
                                value={selectedCategory}
                                onChange={(e) =>
                                    setSelectedCategory(e.target.value)
                                }
                                className="rounded-lg border border-line px-3 py-1.5 text-xs outline-none focus:border-brand"
                            >
                                <option value="ALL">All Categories</option>
                                <option value="General">General</option>
                                <option value="Ayuda">
                                    Ayuda / Financial Aid
                                </option>
                                <option value="Health">
                                    Medical / Mobility Support
                                </option>
                                {!isUpdate && (
                                    <option value="Event">PWD Event</option>
                                )}
                            </select>

                            <label className="ml-2 text-xs font-medium text-ink-muted">
                                Importance:
                            </label>
                            <select
                                value={selectedImportance}
                                onChange={(e) =>
                                    setSelectedImportance(e.target.value)
                                }
                                className="rounded-lg border border-line px-3 py-1.5 text-xs outline-none focus:border-brand"
                            >
                                <option value="ALL">All Importance</option>
                                {ANNOUNCEMENT_IMPORTANCE_OPTIONS.map(
                                    (option) => (
                                        <option
                                            key={option.value}
                                            value={option.value}
                                        >
                                            {option.label}
                                        </option>
                                    ),
                                )}
                            </select>
                        </div>
                    </div>

                    <div className="overflow-hidden rounded-lg border border-line-soft bg-white shadow-card">
                        <div className="overflow-x-auto">
                            <table className="w-full text-left text-sm text-ink-muted">
                                <thead className="border-b border-line-soft bg-canvas text-xs font-semibold text-ink-muted uppercase">
                                    <tr>
                                        <th className="px-5 py-3">Title</th>
                                        <th className="px-5 py-3">Type</th>
                                        <th className="px-5 py-3">Category</th>
                                        <th className="px-5 py-3">
                                            Importance
                                        </th>
                                        <th className="px-5 py-3">Status</th>
                                        <th className="px-5 py-3">
                                            {isUpdate ? 'Posted' : 'Event Date'}
                                        </th>
                                        <th className="px-5 py-3 text-right">
                                            Actions
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-100">
                                    {filteredAnnouncements.length === 0 ? (
                                        <tr>
                                            <td
                                                colSpan={7}
                                                className="p-8 text-center text-sm text-ink-muted"
                                            >
                                                No PWD sector announcements
                                                found in this tab.
                                            </td>
                                        </tr>
                                    ) : (
                                        filteredAnnouncements.map((item) => (
                                            <tr
                                                key={item.id}
                                                className="hover:bg-canvas/80"
                                            >
                                                <td className="px-5 py-3.5 font-semibold text-ink-strong">
                                                    {item.title}
                                                    {!isEventAnnouncement(
                                                        item,
                                                    ) &&
                                                        item.rejection_reason && (
                                                            <p
                                                                className={`mt-1 text-xs font-normal ${item.approval_status === 'rejected' ? 'text-red-600' : 'text-amber-600'}`}
                                                            >
                                                                {item.approval_status ===
                                                                'rejected'
                                                                    ? `Returned for revision: ${item.rejection_reason}`
                                                                    : `Previously returned for revision (resubmitted, awaiting approval): ${item.rejection_reason}`}
                                                            </p>
                                                        )}
                                                </td>
                                                <td className="px-5 py-3.5">
                                                    <span
                                                        className={`rounded px-2 py-0.5 text-[10px] font-semibold uppercase ${
                                                            isEventAnnouncement(
                                                                item,
                                                            )
                                                                ? 'bg-purple-100 text-purple-800'
                                                                : 'bg-secondary text-ink-muted'
                                                        }`}
                                                    >
                                                        {formatAnnouncementType(
                                                            item,
                                                        )}
                                                    </span>
                                                </td>
                                                <td className="px-5 py-3.5">
                                                    <span className="rounded bg-brand-soft px-2 py-0.5 text-xs font-semibold text-brand uppercase">
                                                        {item.category}
                                                    </span>
                                                </td>
                                                <td className="px-5 py-3.5">
                                                    <AnnouncementImportanceBadge
                                                        importance={
                                                            item.importance
                                                        }
                                                    />
                                                </td>
                                                <td className="px-5 py-3.5">
                                                    {item.completed_at ? (
                                                        <div className="flex flex-col items-start gap-1">
                                                            <span className="rounded bg-emerald-100 px-2.5 py-1 text-xs font-semibold text-emerald-800 uppercase">
                                                                {isUpdate
                                                                    ? 'Completed'
                                                                    : 'Done'}
                                                            </span>
                                                            {isUpdate && (
                                                                <span className="text-[10px] text-ink-muted">
                                                                    {formatApprovalStatusLabel(
                                                                        item.approval_status,
                                                                    )}
                                                                </span>
                                                            )}
                                                        </div>
                                                    ) : (
                                                        <span
                                                            className={`rounded px-2.5 py-1 text-xs font-semibold uppercase ${
                                                                item.approval_status ===
                                                                'approved'
                                                                    ? 'bg-emerald-100 text-emerald-800'
                                                                    : item.approval_status ===
                                                                        'rejected'
                                                                      ? 'bg-red-100 text-red-800'
                                                                      : 'bg-amber-100 text-amber-800'
                                                            }`}
                                                        >
                                                            {formatAnnouncementStatusLabel(
                                                                item,
                                                            )}
                                                        </span>
                                                    )}
                                                </td>
                                                <td className="px-5 py-3.5 text-xs text-ink-muted">
                                                    {isUpdate
                                                        ? formatAnnouncementPostedAt(
                                                              item.created_at,
                                                          )
                                                        : item.event_start_at
                                                          ? item.event_start_at
                                                          : 'N/A'}
                                                </td>
                                                <td className="space-x-2 px-5 py-3.5 text-right">
                                                    <Link
                                                        href={`${basePath}/${item.id}`}
                                                        className="inline-flex rounded border border-[#c2cbd4] bg-surface px-2.5 py-1 text-xs font-medium text-brand hover:bg-brand-soft"
                                                    >
                                                        Details
                                                    </Link>
                                                    {isAttendanceAvailable(
                                                        item,
                                                    ) && (
                                                        <Link
                                                            href={attendanceUrl(
                                                                'pwd',
                                                                item.id,
                                                            )}
                                                            className="inline-flex rounded bg-brand px-2.5 py-1 text-xs font-semibold text-white hover:bg-brand-deep"
                                                        >
                                                            Attendance
                                                        </Link>
                                                    )}
                                                    {isUpdate &&
                                                    item.approval_status ===
                                                        'approved' &&
                                                    item.is_published &&
                                                    !item.completed_at ? (
                                                        <MarkAnnouncementDoneButton
                                                            portal="pwd"
                                                            announcementId={
                                                                item.id
                                                            }
                                                            title={item.title}
                                                            kind="update"
                                                            className="rounded border border-emerald-200 bg-emerald-50 px-2.5 py-1 text-xs font-medium text-emerald-700 hover:bg-emerald-100"
                                                        />
                                                    ) : !isUpdate &&
                                                      canMarkAnnouncementDone(
                                                          item,
                                                      ) ? (
                                                        <MarkAnnouncementDoneButton
                                                            portal="pwd"
                                                            announcementId={
                                                                item.id
                                                            }
                                                            title={item.title}
                                                            className="rounded border border-emerald-200 bg-emerald-50 px-2.5 py-1 text-xs font-medium text-emerald-700 hover:bg-emerald-100"
                                                        />
                                                    ) : null}
                                                    <button
                                                        type="button"
                                                        onClick={() =>
                                                            openEditModal(item)
                                                        }
                                                        disabled={Boolean(
                                                            item.completed_at,
                                                        )}
                                                        className="inline-flex rounded border border-line px-2.5 py-1 text-xs font-medium text-ink hover:bg-secondary disabled:cursor-not-allowed disabled:opacity-50"
                                                    >
                                                        Edit
                                                    </button>
                                                    <button
                                                        type="button"
                                                        onClick={() =>
                                                            setDeletingId(
                                                                item.id,
                                                            )
                                                        }
                                                        className="inline-flex rounded border border-red-200 bg-red-50 px-2.5 py-1 text-xs font-medium text-red-600 hover:bg-red-100"
                                                    >
                                                        Delete
                                                    </button>
                                                </td>
                                            </tr>
                                        ))
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </div>


                {showModal && (
                    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
                        <div className="w-full max-w-lg space-y-4 rounded-lg bg-white p-6 shadow-xl">
                            <div className="flex items-center justify-between border-b border-line-soft pb-3">
                                <h2 className="text-base font-semibold text-ink-strong">
                                    {editingItem
                                        ? isUpdate
                                            ? 'Edit PWD Update'
                                            : isProgram
                                              ? 'Edit PWD Program'
                                              : 'Edit PWD Announcement'
                                        : isUpdate
                                          ? 'New PWD Update'
                                          : isProgram
                                            ? 'New PWD Program'
                                            : 'New PWD Announcement'}
                                </h2>
                                <button
                                    onClick={() => setShowModal(false)}
                                    className="text-ink-faint hover:text-ink-muted"
                                >
                                    
                                </button>
                            </div>

                            <form onSubmit={handleSubmit} className="space-y-4">
                                <div>
                                    <label className="block text-xs font-semibold text-ink">
                                        Title
                                    </label>
                                    <input
                                        type="text"
                                        value={data.title}
                                        onChange={(e) =>
                                            setData('title', e.target.value)
                                        }
                                        className="mt-1 w-full rounded-lg border border-line p-2 text-xs outline-none focus:border-brand"
                                        placeholder="e.g. Financial Assistance & Wheelchair Distribution"
                                        required
                                    />
                                    {errors.title && (
                                        <p className="mt-1 text-xs text-red-500">
                                            {errors.title}
                                        </p>
                                    )}
                                </div>

                                <div>
                                    <label className="block text-xs font-semibold text-ink">
                                        Content
                                    </label>
                                    <textarea
                                        rows={3}
                                        value={data.content}
                                        onChange={(e) =>
                                            setData('content', e.target.value)
                                        }
                                        className="mt-1 w-full rounded-lg border border-line p-2 text-xs outline-none focus:border-brand"
                                        placeholder="Provide full announcement details..."
                                        required
                                    />
                                    {errors.content && (
                                        <p className="mt-1 text-xs text-red-500">
                                            {errors.content}
                                        </p>
                                    )}
                                </div>

                                {!forcedFormType && (
                                    <div>
                                        <label className="block text-xs font-semibold text-ink">
                                            Announcement Type
                                        </label>
                                        <div className="mt-1 grid grid-cols-2 gap-2">
                                            <button
                                                type="button"
                                                onClick={() =>
                                                    handleTypeChange(
                                                        'informational',
                                                    )
                                                }
                                                className={`rounded-lg border px-3 py-2 text-left text-xs font-semibold transition ${
                                                    formType === 'informational'
                                                        ? 'border-brand bg-brand-soft text-brand-deep'
                                                        : 'border-line bg-white text-ink-muted hover:bg-canvas'
                                                }`}
                                            >
                                                Informational Update
                                                <span className="mt-0.5 block text-[10px] font-normal text-ink-muted">
                                                    Advisory or notice without
                                                    an event schedule.
                                                </span>
                                            </button>
                                            <button
                                                type="button"
                                                onClick={() =>
                                                    handleTypeChange('event')
                                                }
                                                className={`rounded-lg border px-3 py-2 text-left text-xs font-semibold transition ${
                                                    formType === 'event'
                                                        ? 'border-brand bg-brand-soft text-brand-deep'
                                                        : 'border-line bg-white text-ink-muted hover:bg-canvas'
                                                }`}
                                            >
                                                Event
                                                <span className="mt-0.5 block text-[10px] font-normal text-ink-muted">
                                                    Activity with a schedule and
                                                    venue.
                                                </span>
                                            </button>
                                        </div>
                                    </div>
                                )}

                                {!isProgram && (
                                    <div>
                                        <label className="block text-xs font-semibold text-ink">
                                            Category
                                        </label>
                                        <select
                                            value={data.category}
                                            onChange={(e) =>
                                                handleCategoryChange(
                                                    e.target.value,
                                                )
                                            }
                                            className="mt-1 w-full rounded-lg border border-line p-2 text-xs outline-none focus:border-brand"
                                        >
                                            <option value="General">
                                                General Update
                                            </option>
                                            <option value="Ayuda">
                                                Ayuda / Financial Aid
                                            </option>
                                            <option value="Health">
                                                Medical / Mobility Support
                                            </option>
                                            {formType === 'event' && (
                                                <option value="Event">
                                                    PWD Event
                                                </option>
                                            )}
                                        </select>
                                    </div>
                                )}

                                <div>
                                    <label className="block text-xs font-semibold text-ink">
                                        Importance
                                    </label>
                                    <select
                                        value={data.importance}
                                        onChange={(e) =>
                                            setData(
                                                'importance',
                                                e.target.value,
                                            )
                                        }
                                        className="mt-1 w-full rounded-lg border border-line p-2 text-xs outline-none focus:border-brand"
                                    >
                                        {ANNOUNCEMENT_IMPORTANCE_OPTIONS.map(
                                            (option) => (
                                                <option
                                                    key={option.value}
                                                    value={option.value}
                                                >
                                                    {option.label}
                                                </option>
                                            ),
                                        )}
                                    </select>
                                    {errors.importance && (
                                        <p className="mt-1 text-xs text-red-500">
                                            {errors.importance}
                                        </p>
                                    )}
                                </div>

                                {formType === 'event' && (
                                    <>
                                        <div className="grid grid-cols-2 gap-3">
                                            <div>
                                                <label className="block text-xs font-semibold text-ink">
                                                    Event Date
                                                </label>
                                                <input
                                                    type="datetime-local"
                                                    value={data.event_start_at}
                                                    onChange={(e) =>
                                                        setData(
                                                            'event_start_at',
                                                            e.target.value,
                                                        )
                                                    }
                                                    className="mt-1 w-full rounded-lg border border-line p-2 text-xs outline-none focus:border-brand"
                                                    required
                                                />
                                                {errors.event_start_at && (
                                                    <p className="mt-1 text-xs text-red-500">
                                                        {errors.event_start_at}
                                                    </p>
                                                )}
                                            </div>

                                            <div>
                                                <label className="block text-xs font-semibold text-ink">
                                                    Location
                                                </label>
                                                <input
                                                    type="text"
                                                    value={data.location}
                                                    onChange={(e) =>
                                                        setData(
                                                            'location',
                                                            e.target.value,
                                                        )
                                                    }
                                                    className="mt-1 w-full rounded-lg border border-line p-2 text-xs outline-none focus:border-brand"
                                                    placeholder="Barangay Covered Court"
                                                    required
                                                />
                                                {errors.location && (
                                                    <p className="mt-1 text-xs text-red-500">
                                                        {errors.location}
                                                    </p>
                                                )}
                                            </div>
                                        </div>

                                        <AnnouncementAttendanceOption
                                            id="req_att_pwd"
                                            checked={data.requires_attendance}
                                            onChange={(checked) =>
                                                setData(
                                                    'requires_attendance',
                                                    checked,
                                                )
                                            }
                                        />
                                    </>
                                )}

                                {reviewType === 'event' && (
                                    <ProgramStatusSelect
                                        value={data.program_status}
                                        onChange={(value) =>
                                            setData('program_status', value)
                                        }
                                    />
                                )}
                                <AnnouncementReviewSummary
                                    type={reviewType}
                                    audience="pwd"
                                    requiresAttendance={
                                        data.requires_attendance
                                    }
                                    willResubmit={
                                        Boolean(editingItem) &&
                                        !isUpdate &&
                                        reviewType !== 'event'
                                    }
                                    directPublish={
                                        isUpdate || reviewType === 'event'
                                    }
                                />

                                <div className="flex justify-end gap-2 border-t border-line-soft pt-3">
                                    <button
                                        type="button"
                                        onClick={() => setShowModal(false)}
                                        className="rounded-lg border border-line px-4 py-1.5 text-xs font-medium text-ink hover:bg-canvas"
                                    >
                                        Cancel
                                    </button>
                                    <button
                                        type="submit"
                                        disabled={processing}
                                        aria-busy={processing}
                                        className="rounded-lg bg-brand px-4 py-1.5 text-xs font-semibold text-white hover:bg-brand-deep disabled:opacity-60"
                                    >
                                        {processing
                                            ? 'Submitting...'
                                            : isUpdate
                                              ? editingItem
                                                  ? 'Republish Update'
                                                  : 'Publish Update'
                                              : isProgram
                                                ? data.program_status ===
                                                  'draft'
                                                    ? 'Save Draft'
                                                    : 'Publish Program'
                                                : editingItem
                                                  ? 'Update Announcement'
                                                  : 'Submit for Approval'}
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                )}

                {deletingId && (
                    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
                        <div className="w-full max-w-sm space-y-3 rounded-lg bg-white p-5 shadow-xl">
                            <h3 className="text-sm font-semibold text-ink-strong">
                                Delete Announcement?
                            </h3>
                            <p className="text-xs text-ink-muted">
                                Are you sure you want to delete this
                                announcement? This action cannot be undone.
                            </p>
                            <div className="flex justify-end gap-2 pt-2">
                                <button
                                    type="button"
                                    onClick={() => setDeletingId(null)}
                                    className="rounded-lg border border-line px-3 py-1.5 text-xs font-medium text-ink hover:bg-canvas"
                                >
                                    Cancel
                                </button>
                                <button
                                    type="button"
                                    onClick={() => handleDelete(deletingId)}
                                    className="rounded-lg bg-red-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-red-700"
                                >
                                    Confirm Delete
                                </button>
                            </div>
                        </div>
                    </div>
                )}
            </div>
        </PwdLayout>
    );
}
