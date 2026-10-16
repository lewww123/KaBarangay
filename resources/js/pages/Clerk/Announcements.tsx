import { Head, useForm, router } from '@inertiajs/react';
import ClerkLayout from '@/layouts/ClerkLayout';
import { useState } from 'react';
import axios from 'axios';

interface User { id: number; name: string; role: string; }
interface Sector { id: number; name: string; slug: string; }
interface ApprovalLog { id: number; action: string; previous_status: string; new_status: string; notes: string; created_at: string; actor: { name: string }; }

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

export default function Announcements({ auth, announcements, sectors, userRole }: Props) {
    const [isModalOpen, setIsModalOpen] = useState(false);
    const [editingItem, setEditingItem] = useState<AnnouncementItem | null>(null);
    const [attendanceModalOpen, setAttendanceModalOpen] = useState(false);
    const [activeAnnouncement, setActiveAnnouncement] = useState<AnnouncementItem | null>(null);
    const [attendanceList, setAttendanceList] = useState<any[]>([]);

    const { data, setData, post, put, processing, reset, errors } = useForm({
        title: '',
        content: '',
        category: 'General',
        target_audience: userRole === 'senior_staff' ? 'senior_citizens' : userRole === 'pwd_staff' ? 'pwd' : 'all',
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
            target_sector_id: item.target_sector_id ? String(item.target_sector_id) : '',
            requires_attendance: item.requires_attendance,
            event_start_at: item.event_start_at ? item.event_start_at.substring(0, 16) : '',
            location: item.location || '',
            is_published: item.is_published,
        });
        setIsModalOpen(true);
    };

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        if (editingItem) {
            put(`/clerk/announcements/${editingItem.id}`, { onSuccess: () => { setIsModalOpen(false); reset(); } });
        } else {
            post('/clerk/announcements', { onSuccess: () => { setIsModalOpen(false); reset(); } });
        }
    };

    const openAttendanceSheet = async (item: AnnouncementItem) => {
        setActiveAnnouncement(item);
        try {
            const res = await axios.get(`/clerk/announcements/${item.id}/attendance`);
            setAttendanceList(res.data.attendances);
            setAttendanceModalOpen(true);
        } catch (err) {
            alert('Attendance is only accessible for approved events.');
        }
    };

    return (
        <ClerkLayout user={auth.user} active="announcements">
            <Head title="Announcements Management" />

            <div className="space-y-6 max-w-7xl mx-auto">
                <div className="flex justify-between items-center">
                    <div>
                        <h1 className="text-2xl font-bold text-gray-800">Barangay Announcements</h1>
                        <p className="text-sm text-gray-500">Create advisories and track event attendance.</p>
                    </div>
                    <button onClick={openCreateModal} className="bg-[#3f6f3d] text-white px-4 py-2 rounded-md font-medium text-sm">
                        + New Announcement
                    </button>
                </div>

                {/* LIST */}
                <div className="space-y-4">
                    {announcements.map((item) => (
                        <div key={item.id} className="bg-white p-6 rounded-lg border shadow-sm space-y-3">
                            <div className="flex justify-between items-start">
                                <div className="space-y-1">
                                    <div className="flex items-center gap-2">
                                        <span className={`px-2 py-0.5 text-[10px] font-bold rounded uppercase ${
                                            item.approval_status === 'approved' ? 'bg-green-100 text-green-800' :
                                            item.approval_status === 'rejected' ? 'bg-red-100 text-red-800' : 'bg-yellow-100 text-yellow-800'
                                        }`}>
                                            ● {item.approval_status}
                                        </span>
                                        <span className="bg-blue-100 text-blue-800 text-[10px] font-bold px-2 py-0.5 rounded uppercase">{item.category}</span>
                                        <span className="bg-gray-100 text-gray-800 text-[10px] font-bold px-2 py-0.5 rounded uppercase">Target: {item.target_audience}</span>
                                    </div>
                                    <h3 className="text-lg font-bold text-gray-800">{item.title}</h3>
                                </div>
                                <div className="flex items-center gap-2">
                                    {item.approval_status === 'approved' && item.requires_attendance && (
                                        <button onClick={() => openAttendanceSheet(item)} className="px-3 py-1.5 bg-blue-600 text-white rounded text-xs font-bold">
                                            Attendance Sheet
                                        </button>
                                    )}
                                    <button onClick={() => openEditModal(item)} className="px-3 py-1.5 border rounded text-xs">
                                        {item.approval_status === 'rejected' ? 'Edit & Resubmit' : 'Edit'}
                                    </button>
                                </div>
                            </div>

                            <p className="text-sm text-gray-600">{item.content}</p>

                            {/* REJECTION ALERT */}
                            {item.approval_status === 'rejected' && item.rejection_reason && (
                                <div className="p-3 bg-red-50 border-l-4 border-red-500 rounded text-xs text-red-700">
                                    <span className="font-bold">Rejection Reason: </span>{item.rejection_reason}
                                </div>
                            )}
                        </div>
                    ))}
                </div>

                {/* CREATE/EDIT MODAL */}
                {isModalOpen && (
                    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
                        <div className="bg-white rounded-lg shadow-xl w-full max-w-lg p-6 space-y-4">
                            <h3 className="font-bold text-lg">{editingItem ? 'Edit & Resubmit Announcement' : 'New Announcement'}</h3>
                            <form onSubmit={handleSubmit} className="space-y-4">
                                <div>
                                    <label className="block text-xs font-bold uppercase text-gray-600 mb-1">Title</label>
                                    <input type="text" value={data.title} onChange={e => setData('title', e.target.value)} required className="w-full border rounded p-2 text-sm" />
                                </div>

                                <div className="grid grid-cols-2 gap-4">
                                    <div>
                                        <label className="block text-xs font-bold uppercase text-gray-600 mb-1">Category</label>
                                        <select value={data.category} onChange={e => setData('category', e.target.value)} className="w-full border rounded p-2 text-sm bg-white">
                                            <option value="General">General</option>
                                            <option value="Event">Event</option>
                                            <option value="Relief Goods">Relief Goods</option>
                                            <option value="Cash Assistance">Cash Assistance</option>
                                            <option value="Meeting">Meeting</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label className="block text-xs font-bold uppercase text-gray-600 mb-1">Target Audience</label>
                                        <select 
                                            value={data.target_audience} 
                                            onChange={e => setData('target_audience', e.target.value)} 
                                            disabled={userRole === 'senior_staff' || userRole === 'pwd_staff'} 
                                            className="w-full border rounded p-2 text-sm bg-white disabled:bg-gray-100"
                                        >
                                            <option value="all">All Residents</option>
                                            <option value="senior_citizens">Senior Citizens</option>
                                            <option value="pwd">PWD</option>
                                        </select>
                                    </div>
                                </div>

                                <div>
                                    <label className="block text-xs font-bold uppercase text-gray-600 mb-1">Content</label>
                                    <textarea rows={4} value={data.content} onChange={e => setData('content', e.target.value)} required className="w-full border rounded p-2 text-sm" />
                                </div>

                                <div className="flex justify-end gap-2 pt-4 border-t">
                                    <button type="button" onClick={() => setIsModalOpen(false)} className="px-4 py-2 border rounded text-sm">Cancel</button>
                                    <button type="submit" disabled={processing} className="px-4 py-2 bg-[#3f6f3d] text-white rounded text-sm font-bold">Submit for Approval</button>
                                </div>
                            </form>
                        </div>
                    </div>
                )}
            </div>
        </ClerkLayout>
    );
}