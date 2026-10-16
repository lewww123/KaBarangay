import { useState } from 'react';
import { Head, useForm, router } from '@inertiajs/react';
import SeniorLayout from '@/layouts/SeniorLayout';

interface Resident {
    id: number;
    first_name: string;
    last_name: string;
    middle_name?: string;
    suffix?: string;
    resident_number: string;
}

interface AttendanceItem {
    id: number;
    resident: Resident;
    status: 'present' | 'claimed' | 'absent';
    method: string;
    attended_at: string;
}

interface Announcement {
    id: number;
    title: string;
    category: string;
    location?: string;
}

interface Props {
    announcement: Announcement;
    attendances: AttendanceItem[];
    residents?: Resident[];
    user?: { name?: string; staff_id?: string };
}

export default function SeniorAttendanceSheet({ announcement, attendances = [], residents = [], user }: Props) {
    const [qrInput, setQrInput] = useState('');
    const [scanMessage, setScanMessage] = useState<{ text: string; type: 'success' | 'error' } | null>(null);
    const [activeTab, setActiveTab] = useState<'claimed' | 'present'>('claimed');

    const { data, setData, post, processing, reset } = useForm({
        resident_id: '',
        status: 'claimed',
        method: 'manual',
    });

    const handleQrScanSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        setScanMessage(null);

        let cleanToken = qrInput.trim();
        if (!cleanToken) return;

        if (cleanToken.includes('/')) {
            const parts = cleanToken.split('/');
            cleanToken = parts[parts.length - 1];
        }

        fetch(`/api/qr/verify-token?token=${cleanToken}`)
            .then(async (res) => {
                const json = await res.json();
                if (res.ok && json.resident) {
                    router.post(`/senior/announcements/${announcement.id}/attendance/mark`, {
                        resident_id: json.resident.id,
                        status: activeTab,
                        method: 'qr_scanner',
                    }, {
                        preserveScroll: true,
                        onSuccess: () => {
                            setScanMessage({ text: `Tagumpay! Na-record si ${json.resident.last_name}, ${json.resident.first_name} sa ${activeTab.toUpperCase()}.`, type: 'success' });
                            setQrInput('');
                            router.reload({ only: ['attendances'] });
                        },
                    });
                } else {
                    setScanMessage({ text: json.message || 'Mali o expired ang QR code token.', type: 'error' });
                    setQrInput('');
                }
            })
            .catch(() => {
                setScanMessage({ text: 'Nabigong i-verify ang QR code. Subukang muli.', type: 'error' });
                setQrInput('');
            });
    };

    const handleManualSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        data.status = activeTab;
        post(`/senior/announcements/${announcement.id}/attendance/mark`, {
            preserveScroll: true,
            onSuccess: () => {
                reset('resident_id');
                router.reload({ only: ['attendances'] });
            },
        });
    };

    const filteredAttendances = attendances.filter(item => {
        if (activeTab === 'claimed') return item.status === 'claimed';
        if (activeTab === 'present') return item.status === 'present';
        return true;
    });

    return (
        <SeniorLayout active="announcements" user={user}>
            <Head title={`Attendance - ${announcement.title}`} />

            <div className="space-y-6 max-w-7xl mx-auto pb-10">
                {/* PAGE HEADER */}
                <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between bg-white p-5 rounded-xl border border-gray-200/80 shadow-sm">
                    <div className="space-y-1">
                        <div className="flex items-center gap-2">
                            <span className="rounded-full bg-emerald-50 px-2.5 py-0.5 text-[11px] font-bold text-[#3f6f3d] uppercase tracking-wide border border-emerald-200">
                                {announcement.category}
                            </span>
                            <span className="text-xs text-gray-400">•</span>
                            <span className="text-xs font-medium text-gray-500">
                                {announcement.location ? `📍 ${announcement.location}` : '📍 Walang nakasaad na lokasyon'}
                            </span>
                        </div>
                        <h1 className="text-xl font-extrabold text-gray-900 tracking-tight">{announcement.title}</h1>
                    </div>

                    <a
                        href="/senior/announcements"
                        className="inline-flex items-center justify-center gap-1.5 rounded-lg border border-gray-300 bg-white px-4 py-2 text-xs font-semibold text-gray-700 shadow-sm transition hover:bg-gray-50"
                    >
                        <span>←</span> Bumalik sa Announcements
                    </a>
                </div>

                {/* SCANNER & MANUAL ENTRY GRID */}
                <div className="grid grid-cols-1 gap-6 lg:grid-cols-12">
                    {/* QR SCANNER INPUT BOX (Col 7) */}
                    <div className="lg:col-span-7 rounded-xl border border-gray-200/80 bg-white p-6 shadow-sm flex flex-col justify-between space-y-4">
                        <div className="space-y-4">
                            <div className="flex items-center justify-between pb-3 border-b border-gray-100">
                                <div className="flex items-center gap-2.5">
                                    <span className="flex h-8 w-8 items-center justify-center rounded-lg bg-emerald-50 text-[#3f6f3d] text-sm">
                                        📷
                                    </span>
                                    <div>
                                        <h3 className="text-sm font-bold text-gray-900">QR Code / Barcode Scanner</h3>
                                        <p className="text-xs text-gray-500">Kasalukuyang mode: <strong className="uppercase text-[#3f6f3d]">{activeTab}</strong></p>
                                    </div>
                                </div>
                            </div>

                            <form onSubmit={handleQrScanSubmit} className="space-y-3 pt-1">
                                <div className="space-y-1.5">
                                    <label className="block text-xs font-bold text-gray-700">QR Token Input</label>
                                    <input
                                        type="text"
                                        value={qrInput}
                                        onChange={(e) => setQrInput(e.target.value)}
                                        placeholder={`I-scan para itala bilang ${activeTab}...`}
                                        className="w-full rounded-lg border border-gray-300 p-3 text-xs outline-none focus:border-[#3f6f3d] focus:ring-1 focus:ring-[#3f6f3d] bg-gray-50/50 font-mono"
                                        autoFocus
                                    />
                                </div>
                                <button
                                    type="submit"
                                    className="w-full rounded-lg bg-[#3f6f3d] py-2.5 text-xs font-bold text-white shadow-sm transition hover:bg-[#315b30]"
                                >
                                    Prosesuhin ang QR Scan ({activeTab.toUpperCase()})
                                </button>
                            </form>
                        </div>

                        {scanMessage && (
                            <div className={`p-3.5 rounded-lg text-xs font-bold flex items-center gap-2 ${
                                scanMessage.type === 'success' ? 'bg-emerald-50 text-emerald-800 border border-emerald-200' : 'bg-red-50 text-red-800 border border-red-200'
                            }`}>
                                <span>{scanMessage.type === 'success' ? '✅' : '⚠️'}</span>
                                <span>{scanMessage.text}</span>
                            </div>
                        )}
                    </div>

                    {/* MANUAL ENTRY BOX (Col 5) */}
                    <div className="lg:col-span-5 rounded-xl border border-gray-200/80 bg-white p-6 shadow-sm flex flex-col justify-between space-y-4">
                        <div className="space-y-4">
                            <div className="flex items-center gap-2.5 pb-3 border-b border-gray-100">
                                <span className="flex h-8 w-8 items-center justify-center rounded-lg bg-amber-50 text-amber-800 text-sm">
                                    ✍️
                                </span>
                                <div>
                                    <h3 className="text-sm font-bold text-gray-900">Manual Entry</h3>
                                    <p className="text-xs text-gray-500">Mag-record nang walang QR.</p>
                                </div>
                            </div>

                            <form onSubmit={handleManualSubmit} className="space-y-3 pt-1">
                                <div className="space-y-1.5">
                                    <label className="block text-xs font-bold text-gray-700">Piliin ang Residente</label>
                                    <select
                                        value={data.resident_id}
                                        onChange={(e) => setData('resident_id', e.target.value)}
                                        className="w-full rounded-lg border border-gray-300 p-2.5 text-xs outline-none focus:border-[#3f6f3d] bg-white"
                                        required
                                    >
                                        <option value="">-- Pumili ng Residente --</option>
                                        {residents.map((res: any) => (
                                            <option key={res.id} value={res.id}>
                                                {res.last_name}, {res.first_name} ({res.resident_number})
                                            </option>
                                        ))}
                                    </select>
                                </div>

                                <button
                                    type="submit"
                                    disabled={processing}
                                    className="w-full rounded-lg bg-[#3f6f3d] py-3 text-xs font-bold text-white shadow-sm transition hover:bg-[#315b30]"
                                >
                                    Itala bilang {activeTab.toUpperCase()} Manually
                                </button>
                            </form>
                        </div>
                    </div>
                </div>

                {/* TABS & LOGGED ATTENDANCE TABLE */}
                <div className="overflow-hidden rounded-xl border border-gray-200/80 bg-white shadow-sm">
                    {/* TABS HEADER */}
                    <div className="flex flex-col sm:flex-row items-start sm:items-center justify-between border-b border-gray-200/80 bg-gray-50/70 px-6 py-3 gap-3">
                        <div className="flex items-center gap-2">
                            <button
                                type="button"
                                onClick={() => setActiveTab('claimed')}
                                className={`px-4 py-2 rounded-lg text-xs font-bold transition ${
                                    activeTab === 'claimed'
                                        ? 'bg-[#3f6f3d] text-white shadow-sm'
                                        : 'bg-white text-gray-600 border border-gray-300 hover:bg-gray-100'
                                }`}
                            >
                                🎁 Aid Claimed / Naka-receive ({attendances.filter(i => i.status === 'claimed').length})
                            </button>

                            <button
                                type="button"
                                onClick={() => setActiveTab('present')}
                                className={`px-4 py-2 rounded-lg text-xs font-bold transition ${
                                    activeTab === 'present'
                                        ? 'bg-[#3f6f3d] text-white shadow-sm'
                                        : 'bg-white text-gray-600 border border-gray-300 hover:bg-gray-100'
                                }`}
                            >
                                📋 Event Present / Dumalo ({attendances.filter(i => i.status === 'present').length})
                            </button>
                        </div>
                    </div>

                    <div className="overflow-x-auto">
                        <table className="w-full text-left text-sm text-gray-600">
                            <thead className="bg-white text-[11px] font-bold uppercase tracking-wider text-gray-400 border-b border-gray-100">
                                <tr>
                                    <th className="px-6 py-3.5">Pangalan ng Residente</th>
                                    <th className="px-6 py-3.5">ID Number</th>
                                    <th className="px-6 py-3.5">Status</th>
                                    <th className="px-6 py-3.5">Paraan</th>
                                    <th className="px-6 py-3.5 text-right">Oras ng Pagtala</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100 text-xs">
                                {filteredAttendances.length === 0 ? (
                                    <tr>
                                        <td colSpan={5} className="p-10 text-center text-gray-400 italic">
                                            Wala pang nakatalang rekord para sa tab na ito ({activeTab}).
                                        </td>
                                    </tr>
                                ) : (
                                    filteredAttendances.map((item) => (
                                        <tr key={item.id} className="hover:bg-gray-50/60 transition">
                                            <td className="px-6 py-3.5 font-bold text-gray-900">
                                                {item.resident ? `${item.resident.last_name}, ${item.resident.first_name}` : 'Unknown Resident'}
                                            </td>
                                            <td className="px-6 py-3.5 text-gray-500 font-mono">
                                                {item.resident?.resident_number}
                                            </td>
                                            <td className="px-6 py-3.5">
                                                <span className={`inline-flex rounded-full px-2.5 py-0.5 text-[10px] font-extrabold uppercase tracking-wide ${
                                                    item.status === 'present' || item.status === 'claimed'
                                                        ? 'bg-emerald-100 text-emerald-800 border border-emerald-200'
                                                        : 'bg-red-100 text-red-800 border border-red-200'
                                                }`}>
                                                    {item.status}
                                                </span>
                                            </td>
                                            <td className="px-6 py-3.5 uppercase font-semibold text-gray-400 text-[10px]">
                                                {item.method}
                                            </td>
                                            <td className="px-6 py-3.5 text-gray-500 text-right font-medium">
                                                {item.attended_at}
                                            </td>
                                        </tr>
                                    ))
                                )}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </SeniorLayout>
    );
}