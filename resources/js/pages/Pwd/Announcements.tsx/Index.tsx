import { useState } from 'react';
import { Head, Link, useForm, router } from '@inertiajs/react';
import PwdLayout from '@/layouts/PwdLayout';

interface Announcement {
    id: number;
    title: string;
    content: string;
    category: string;
    approval_status: 'pending' | 'approved' | 'rejected';
    requires_attendance: boolean;
    event_start_at?: string | null;
    location?: string | null;
    created_at: string;
}

interface Props {
    announcements: Announcement[];
    user?: {
        name?: string;
        staff_id?: string;
    };
}

export default function PwdAnnouncementsIndex({ announcements = [], user }: Props) {
    const [showModal, setShowModal] = useState(false);
    const [editingItem, setEditingItem] = useState<Announcement | null>(null);
    const [deletingId, setDeletingId] = useState<number | null>(null);
    const [searchQuery, setSearchQuery] = useState('');
    const [selectedCategory, setSelectedCategory] = useState('ALL');

    const { data, setData, post, put, processing, errors, reset } = useForm({
        title: '',
        content: '',
        category: 'General',
        requires_attendance: false,
        event_start_at: '',
        location: '',
    });

    const openCreateModal = () => {
        setEditingItem(null);
        reset();
        setShowModal(true);
    };

    const openEditModal = (item: Announcement) => {
        setEditingItem(item);
        setData({
            title: item.title,
            content: item.content,
            category: item.category || 'General',
            requires_attendance: item.requires_attendance || false,
            event_start_at: item.event_start_at || '',
            location: item.location || '',
        });
        setShowModal(true);
    };

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        if (editingItem) {
            put(`/pwd/announcements/${editingItem.id}`, {
                onSuccess: () => {
                    reset();
                    setShowModal(false);
                    setEditingItem(null);
                },
            });
        } else {
            post('/pwd/announcements', {
                onSuccess: () => {
                    reset();
                    setShowModal(false);
                },
            });
        }
    };

    const handleDelete = (id: number) => {
        router.delete(`/pwd/announcements/${id}`, {
            onSuccess: () => setDeletingId(null),
        });
    };

    const filteredAnnouncements = announcements.filter((item) => {
        const matchesSearch = item.title.toLowerCase().includes(searchQuery.toLowerCase()) ||
            item.content.toLowerCase().includes(searchQuery.toLowerCase());
        const matchesCategory = selectedCategory === 'ALL' || item.category === selectedCategory;
        return matchesSearch && matchesCategory;
    });

    return (
        <PwdLayout active="announcements" user={user}>
            <Head title="PWD Sector Announcements" />

            <div className="space-y-5">
                {/* PAGE HEADER */}
                <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between border-b border-gray-200 bg-white p-4 sm:p-5 rounded-lg shadow-sm">
                    <div>
                        <h1 className="text-xl font-bold text-gray-900">PWD Sector Announcements</h1>
                        <p className="text-xs text-gray-500">
                            Create, update, and manage announcements for the PWD sector.
                        </p>
                    </div>

                    <button
                        type="button"
                        onClick={openCreateModal}
                        className="inline-flex items-center justify-center gap-2 rounded-md bg-[#3f6f3d] px-4 py-2 text-xs font-semibold text-white shadow transition hover:bg-[#315b30]"
                    >
                        + Create PWD Announcement
                    </button>
                </div>

                {/* SEARCH & FILTER BAR */}
                <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between bg-white p-3.5 rounded-lg border border-gray-200 shadow-sm">
                    <input
                        type="text"
                        value={searchQuery}
                        onChange={(e) => setSearchQuery(e.target.value)}
                        placeholder="Search announcements by title or content..."
                        className="w-full sm:w-72 rounded-md border border-gray-300 px-3 py-1.5 text-xs outline-none focus:border-[#3f6f3d]"
                    />

                    <div className="flex items-center gap-2">
                        <label className="text-xs font-medium text-gray-500">Category:</label>
                        <select
                            value={selectedCategory}
                            onChange={(e) => setSelectedCategory(e.target.value)}
                            className="rounded-md border border-gray-300 px-3 py-1.5 text-xs outline-none focus:border-[#3f6f3d]"
                        >
                            <option value="ALL">All Categories</option>
                            <option value="General">General</option>
                            <option value="Ayuda">Ayuda / Financial Aid</option>
                            <option value="Health">Medical / Mobility Support</option>
                            <option value="Event">PWD Event</option>
                        </select>
                    </div>
                </div>

                {/* ANNOUNCEMENTS TABLE */}
                <div className="overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm">
                    <div className="overflow-x-auto">
                        <table className="w-full text-left text-sm text-gray-600">
                            <thead className="bg-gray-50 text-xs font-semibold uppercase text-gray-500 border-b border-gray-200">
                                <tr>
                                    <th className="px-5 py-3">Title</th>
                                    <th className="px-5 py-3">Category</th>
                                    <th className="px-5 py-3">Status</th>
                                    <th className="px-5 py-3">Event Date</th>
                                    <th className="px-5 py-3 text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100">
                                {filteredAnnouncements.length === 0 ? (
                                    <tr>
                                        <td colSpan={5} className="p-8 text-center text-sm text-gray-500">
                                            No PWD sector announcements found.
                                        </td>
                                    </tr>
                                ) : (
                                    filteredAnnouncements.map((item) => (
                                        <tr key={item.id} className="hover:bg-gray-50/80">
                                            <td className="px-5 py-3.5 font-semibold text-gray-900">{item.title}</td>
                                            <td className="px-5 py-3.5">
                                                <span className="rounded bg-[#e8f1e4] px-2 py-0.5 text-xs font-bold text-[#3f6f3d] uppercase">
                                                    {item.category}
                                                </span>
                                            </td>
                                            <td className="px-5 py-3.5">
                                                <span className={`rounded px-2.5 py-1 text-xs font-bold uppercase ${
                                                    item.approval_status === 'approved'
                                                        ? 'bg-emerald-100 text-emerald-800'
                                                        : item.approval_status === 'rejected'
                                                        ? 'bg-red-100 text-red-800'
                                                        : 'bg-amber-100 text-amber-800'
                                                }`}>
                                                    {item.approval_status}
                                                </span>
                                            </td>
                                            <td className="px-5 py-3.5 text-xs text-gray-500">
                                                {item.event_start_at ? item.event_start_at : 'N/A'}
                                            </td>
                                            <td className="px-5 py-3.5 text-right space-x-2">
                                                {item.approval_status === 'approved' && item.requires_attendance && (
                                                    <Link
                                                        href={`/pwd/announcements/${item.id}/attendance`}
                                                        className="inline-flex rounded bg-[#3f6f3d] px-2.5 py-1 text-xs font-bold text-white hover:bg-[#315b30]"
                                                    >
                                                        Attendance
                                                    </Link>
                                                )}
                                                <button
                                                    type="button"
                                                    onClick={() => openEditModal(item)}
                                                    className="inline-flex rounded border border-gray-300 px-2.5 py-1 text-xs font-medium text-gray-700 hover:bg-gray-100"
                                                >
                                                    Edit
                                                </button>
                                                <button
                                                    type="button"
                                                    onClick={() => setDeletingId(item.id)}
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

                {/* CREATE / EDIT MODAL */}
                {showModal && (
                    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
                        <div className="w-full max-w-lg rounded-lg bg-white p-6 shadow-xl space-y-4">
                            <div className="flex items-center justify-between border-b border-gray-100 pb-3">
                                <h2 className="text-base font-bold text-gray-900">
                                    {editingItem ? 'Edit PWD Announcement' : 'New PWD Announcement'}
                                </h2>
                                <button onClick={() => setShowModal(false)} className="text-gray-400 hover:text-gray-600">✕</button>
                            </div>

                            <form onSubmit={handleSubmit} className="space-y-4">
                                <div>
                                    <label className="block text-xs font-bold text-gray-700">Title</label>
                                    <input
                                        type="text"
                                        value={data.title}
                                        onChange={(e) => setData('title', e.target.value)}
                                        className="mt-1 w-full rounded-md border border-gray-300 p-2 text-xs outline-none focus:border-[#3f6f3d]"
                                        placeholder="e.g. Financial Assistance & Wheelchair Distribution"
                                        required
                                    />
                                    {errors.title && <p className="mt-1 text-xs text-red-500">{errors.title}</p>}
                                </div>

                                <div>
                                    <label className="block text-xs font-bold text-gray-700">Content</label>
                                    <textarea
                                        rows={3}
                                        value={data.content}
                                        onChange={(e) => setData('content', e.target.value)}
                                        className="mt-1 w-full rounded-md border border-gray-300 p-2 text-xs outline-none focus:border-[#3f6f3d]"
                                        placeholder="Provide full announcement details..."
                                        required
                                    />
                                </div>

                                <div className="grid grid-cols-2 gap-3">
                                    <div>
                                        <label className="block text-xs font-bold text-gray-700">Category</label>
                                        <select
                                            value={data.category}
                                            onChange={(e) => setData('category', e.target.value)}
                                            className="mt-1 w-full rounded-md border border-gray-300 p-2 text-xs outline-none focus:border-[#3f6f3d]"
                                        >
                                            <option value="General">General Update</option>
                                            <option value="Ayuda">Ayuda / Financial Aid</option>
                                            <option value="Health">Medical / Mobility Support</option>
                                            <option value="Event">PWD Event</option>
                                        </select>
                                    </div>

                                    <div>
                                        <label className="block text-xs font-bold text-gray-700">Location</label>
                                        <input
                                            type="text"
                                            value={data.location}
                                            onChange={(e) => setData('location', e.target.value)}
                                            className="mt-1 w-full rounded-md border border-gray-300 p-2 text-xs outline-none focus:border-[#3f6f3d]"
                                            placeholder="Barangay Covered Court"
                                        />
                                    </div>
                                </div>

                                <div className="flex items-center gap-2 pt-1">
                                    <input
                                        type="checkbox"
                                        id="req_att_pwd"
                                        checked={data.requires_attendance}
                                        onChange={(e) => setData('requires_attendance', e.target.checked)}
                                        className="rounded border-gray-300 text-[#3f6f3d] focus:ring-[#3f6f3d]"
                                    />
                                    <label htmlFor="req_att_pwd" className="text-xs font-semibold text-gray-700">
                                        Requires QR Attendance / Aid Claiming Sheet
                                    </label>
                                </div>

                                <div className="flex justify-end gap-2 border-t border-gray-100 pt-3">
                                    <button
                                        type="button"
                                        onClick={() => setShowModal(false)}
                                        className="rounded-md border border-gray-300 px-4 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50"
                                    >
                                        Cancel
                                    </button>
                                    <button
                                        type="submit"
                                        disabled={processing}
                                        className="rounded-md bg-[#3f6f3d] px-4 py-1.5 text-xs font-semibold text-white hover:bg-[#315b30]"
                                    >
                                        {editingItem ? 'Update Announcement' : 'Submit for Approval'}
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                )}

                {/* DELETE CONFIRMATION MODAL */}
                {deletingId && (
                    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
                        <div className="w-full max-w-sm rounded-lg bg-white p-5 shadow-xl space-y-3">
                            <h3 className="text-sm font-bold text-gray-900">Delete Announcement?</h3>
                            <p className="text-xs text-gray-500">
                                Are you sure you want to delete this announcement? This action cannot be undone.
                            </p>
                            <div className="flex justify-end gap-2 pt-2">
                                <button
                                    type="button"
                                    onClick={() => setDeletingId(null)}
                                    className="rounded-md border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50"
                                >
                                    Cancel
                                </button>
                                <button
                                    type="button"
                                    onClick={() => handleDelete(deletingId)}
                                    className="rounded-md bg-red-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-red-700"
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