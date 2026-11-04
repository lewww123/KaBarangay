import { Head, useForm } from '@inertiajs/react';
import ClerkLayout from '@/layouts/ClerkLayout';
import { useState } from 'react';
import axios from 'axios';

interface User {
    id: number;
    name: string;
    staffAssignments?: { department: string; position: string }[];
}
interface Sector {
    id: number;
    name: string;
    slug: string;
}
interface ApprovalLog {
    id: number;
    action: string;
    previous_status: string;
    new_status: string;
    notes: string;
    created_at: string;
    actor: { name: string };
}

interface AnnouncementItem {
    id: number;
    title: string;
    content: string;
    category: string;
    target_audience: string;
    target_sector_id?: number;
    requires_attendance: boolean;
    event_start_at?: string;
    location?: string;
    is_published: boolean;
    approval_status: 'pending' | 'approved' | 'rejected';
    rejection_reason?: string;
    created_at: string;
    total_expected?: number;
    total_attended?: number;
    author?: { name: string };
    reviewer?: { name: string };
    approval_logs?: ApprovalLog[];
}

interface Props {
    auth: { user: User };
    announcements: AnnouncementItem[];
    sectors: Sector[];
    userRole: string;
}

export default function Announcements({
    auth,
    announcements,
    sectors: _sectors,
    userRole,
}: Props) {
    const [isModalOpen, setIsModalOpen] = useState(false);
    const [editingItem, setEditingItem] = useState<AnnouncementItem | null>(
        null,
    );
    const [_attendanceModalOpen, setAttendanceModalOpen] = useState(false);
    const [_activeAnnouncement, setActiveAnnouncement] =
        useState<AnnouncementItem | null>(null);
    const [_attendanceList, setAttendanceList] = useState<any[]>([]);

    const {
        data,
        setData,
        post,
        put,
        processing,
        reset,
        errors: _errors,
    } = useForm({
        title: '',
        content: '',
        category: 'General',
        target_audience:
            userRole === 'senior_staff'
                ? 'senior_citizens'
                : userRole === 'pwd_staff'
                  ? 'pwd'
                  : 'all',
        target_sector_id: '',
        requires_attendance: false,
        event_start_at: '',
        location: '',
        is_published: true,
    });

    const openCreateModal = () => {
        setEditingItem(null);
        reset();
        setIsModalOpen(true);
    };

    const openEditModal = (item: AnnouncementItem) => {
        setEditingItem(item);
        setData({
            title: item.title,
            content: item.content,
            category: item.category,
            target_audience: item.target_audience,
            target_sector_id: item.target_sector_id
                ? String(item.target_sector_id)
                : '',
            requires_attendance: item.requires_attendance,
            event_start_at: item.event_start_at
                ? item.event_start_at.substring(0, 16)
                : '',
            location: item.location || '',
            is_published: item.is_published,
        });
        setIsModalOpen(true);
    };

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        if (editingItem) {
            put(`/clerk/announcements/${editingItem.id}`, {
                onSuccess: () => {
                    setIsModalOpen(false);
                    reset();
                },
            });
        } else {
            post('/clerk/announcements', {
                onSuccess: () => {
                    setIsModalOpen(false);
                    reset();
                },
            });
        }
    };

    const openAttendanceSheet = async (item: AnnouncementItem) => {
        setActiveAnnouncement(item);
        try {
            const res = await axios.get(
                `/clerk/announcements/${item.id}/attendance`,
            );
            setAttendanceList(res.data.attendances);
            setAttendanceModalOpen(true);
        } catch {
            alert('Attendance is only accessible for approved events.');
        }
    };

    return (
        <ClerkLayout user={auth.user} active="announcements">
            <Head title="Announcements Management" />

            <div className="mx-auto max-w-7xl space-y-6">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-2xl font-bold text-gray-800">
                            Barangay Announcements
                        </h1>
                        <p className="text-sm text-gray-500">
                            Create advisories and track event attendance.
                        </p>
                    </div>
                    <button
                        onClick={openCreateModal}
                        className="rounded-md bg-[#3f6f3d] px-4 py-2 text-sm font-medium text-white"
                    >
                        + New Announcement
                    </button>
                </div>

                {/* LIST */}
                <div className="space-y-4">
                    {announcements.map((item) => (
                        <div
                            key={item.id}
                            className="space-y-3 rounded-lg border bg-white p-6 shadow-sm"
                        >
                            <div className="flex items-start justify-between">
                                <div className="space-y-1">
                                    <div className="flex items-center gap-2">
                                        <span
                                            className={`rounded px-2 py-0.5 text-[10px] font-bold uppercase ${
                                                item.approval_status ===
                                                'approved'
                                                    ? 'bg-green-100 text-green-800'
                                                    : item.approval_status ===
                                                        'rejected'
                                                      ? 'bg-red-100 text-red-800'
                                                      : 'bg-yellow-100 text-yellow-800'
                                            }`}
                                        >
                                            ● {item.approval_status}
                                        </span>
                                        <span className="rounded bg-blue-100 px-2 py-0.5 text-[10px] font-bold text-blue-800 uppercase">
                                            {item.category}
                                        </span>
                                        <span className="rounded bg-gray-100 px-2 py-0.5 text-[10px] font-bold text-gray-800 uppercase">
                                            Target: {item.target_audience}
                                        </span>
                                    </div>
                                    <h3 className="text-lg font-bold text-gray-800">
                                        {item.title}
                                    </h3>
                                </div>
                                <div className="flex items-center gap-2">
                                    {item.approval_status === 'approved' &&
                                        item.requires_attendance && (
                                            <button
                                                onClick={() =>
                                                    openAttendanceSheet(item)
                                                }
                                                className="rounded bg-blue-600 px-3 py-1.5 text-xs font-bold text-white"
                                            >
                                                Attendance Sheet
                                            </button>
                                        )}
                                    <button
                                        onClick={() => openEditModal(item)}
                                        className="rounded border px-3 py-1.5 text-xs"
                                    >
                                        {item.approval_status === 'rejected'
                                            ? 'Edit & Resubmit'
                                            : 'Edit'}
                                    </button>
                                </div>
                            </div>

                            <p className="text-sm text-gray-600">
                                {item.content}
                            </p>

                            {/* REJECTION ALERT */}
                            {item.approval_status === 'rejected' &&
                                item.rejection_reason && (
                                    <div className="rounded border-l-4 border-red-500 bg-red-50 p-3 text-xs text-red-700">
                                        <span className="font-bold">
                                            Rejection Reason:{' '}
                                        </span>
                                        {item.rejection_reason}
                                    </div>
                                )}
                        </div>
                    ))}
                </div>

                {/* CREATE/EDIT MODAL */}
                {isModalOpen && (
                    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
                        <div className="w-full max-w-lg space-y-4 rounded-lg bg-white p-6 shadow-xl">
                            <h3 className="text-lg font-bold">
                                {editingItem
                                    ? 'Edit & Resubmit Announcement'
                                    : 'New Announcement'}
                            </h3>
                            <form onSubmit={handleSubmit} className="space-y-4">
                                <div>
                                    <label className="mb-1 block text-xs font-bold text-gray-600 uppercase">
                                        Title
                                    </label>
                                    <input
                                        type="text"
                                        value={data.title}
                                        onChange={(e) =>
                                            setData('title', e.target.value)
                                        }
                                        required
                                        className="w-full rounded border p-2 text-sm"
                                    />
                                </div>

                                <div className="grid grid-cols-2 gap-4">
                                    <div>
                                        <label className="mb-1 block text-xs font-bold text-gray-600 uppercase">
                                            Category
                                        </label>
                                        <select
                                            value={data.category}
                                            onChange={(e) =>
                                                setData(
                                                    'category',
                                                    e.target.value,
                                                )
                                            }
                                            className="w-full rounded border bg-white p-2 text-sm"
                                        >
                                            <option value="General">
                                                General
                                            </option>
                                            <option value="Event">Event</option>
                                            <option value="Relief Goods">
                                                Relief Goods
                                            </option>
                                            <option value="Cash Assistance">
                                                Cash Assistance
                                            </option>
                                            <option value="Meeting">
                                                Meeting
                                            </option>
                                        </select>
                                    </div>
                                    <div>
                                        <label className="mb-1 block text-xs font-bold text-gray-600 uppercase">
                                            Target Audience
                                        </label>
                                        <select
                                            value={data.target_audience}
                                            onChange={(e) =>
                                                setData(
                                                    'target_audience',
                                                    e.target.value,
                                                )
                                            }
                                            disabled={
                                                userRole === 'senior_staff' ||
                                                userRole === 'pwd_staff'
                                            }
                                            className="w-full rounded border bg-white p-2 text-sm disabled:bg-gray-100"
                                        >
                                            <option value="all">
                                                All Residents
                                            </option>
                                            <option value="senior_citizens">
                                                Senior Citizens
                                            </option>
                                            <option value="pwd">PWD</option>
                                        </select>
                                    </div>
                                </div>

                                <div>
                                    <label className="mb-1 block text-xs font-bold text-gray-600 uppercase">
                                        Content
                                    </label>
                                    <textarea
                                        rows={4}
                                        value={data.content}
                                        onChange={(e) =>
                                            setData('content', e.target.value)
                                        }
                                        required
                                        className="w-full rounded border p-2 text-sm"
                                    />
                                </div>

                                <div className="flex justify-end gap-2 border-t pt-4">
                                    <button
                                        type="button"
                                        onClick={() => setIsModalOpen(false)}
                                        className="rounded border px-4 py-2 text-sm"
                                    >
                                        Cancel
                                    </button>
                                    <button
                                        type="submit"
                                        disabled={processing}
                                        className="rounded bg-[#3f6f3d] px-4 py-2 text-sm font-bold text-white"
                                    >
                                        Submit for Approval
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                )}
            </div>
        </ClerkLayout>
    );
}
