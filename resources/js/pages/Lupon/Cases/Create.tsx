import { Head, Link, useForm } from '@inertiajs/react';
import axios from 'axios';
import { useEffect, useRef, useState } from 'react';
import LuponLayout from '@/layouts/LuponLayout';
import PortalPageSkeleton from '@/components/PortalPageSkeleton';
import withDeferredPage from '@/components/withDeferredPage';

type ResidentOption = {
    id: number;
    name: string;
    residentNumber: string;
    mobile: string;
};

type Props = {
    auth: { user: { name?: string }; staffAssignments?: { department: string; position: string }[] };
    caseTypes: string[];
    residents: ResidentOption[];
};

type PartyLookupState = {
    query: string;
    loading: boolean;
    error: string | null;
    matchedResident: {
        id: number;
        name: string;
        resident_number: string;
        address?: string;
        contact?: string;
    } | null;
};

export default withDeferredPage(CreateCase, ['residents'], (props) => (
    <LuponLayout active="cases" user={props.auth.user}><PortalPageSkeleton list rows={3} /></LuponLayout>
));

function CreateCase({ auth, caseTypes, residents }: Props) {
    const { data, setData, post, processing, errors } = useForm({
        case_type: caseTypes[0] || 'Property / Boundary Dispute',
        custom_case_type: '',
        incident_date: new Date().toISOString().split('T')[0],
        incident_location: '',
        narrative: '',
        requested_action: '',
        // Primary Complainant
        complainant_name: '',
        complainant_is_resident: true,
        complainant_resident_id: '' as string | number,
        complainant_address: '',
        complainant_contact: '',
        // Primary Respondent
        respondent_name: '',
        respondent_is_resident: true,
        respondent_resident_id: '' as string | number,
        respondent_address: '',
        respondent_contact: '',
    });

    // Separate lookup states for Complainant and Respondent
    const [complainantLookup, setComplainantLookup] = useState<PartyLookupState>({
        query: '',
        loading: false,
        error: null,
        matchedResident: null,
    });

    const [respondentLookup, setRespondentLookup] = useState<PartyLookupState>({
        query: '',
        loading: false,
        error: null,
        matchedResident: null,
    });

    const complainantInputRef = useRef<HTMLInputElement | null>(null);
    const respondentInputRef = useRef<HTMLInputElement | null>(null);

    // Start keyboard-wedge scans in the first resident lookup field. The
    // respondent field is focused only when the operator selects it.
    useEffect(() => {
        complainantInputRef.current?.focus({ preventScroll: true });
    }, []);

    // QR Lookup Handler for Complainant
    const handleComplainantLookup = async (tokenQuery?: string) => {
        const token = (tokenQuery !== undefined ? tokenQuery : complainantLookup.query).trim();

        if (!token) {
            setComplainantLookup((prev) => ({
                ...prev,
                error: 'Please scan a QR code or enter a Resident Number.',
            }));
            return;
        }

        setComplainantLookup((prev) => ({ ...prev, loading: true, error: null }));

        try {
            const response = await axios.get(`/lupon/cases/qr-lookup?token=${encodeURIComponent(token)}`);
            const resident = response.data.resident;

            // Check if respondent is already using this resident ID
            if (data.respondent_resident_id && String(data.respondent_resident_id) === String(resident.id)) {
                setComplainantLookup((prev) => ({
                    ...prev,
                    loading: false,
                    error: 'Complainant and Respondent cannot be the same registered resident.',
                }));
                return;
            }

            setComplainantLookup({
                query: '',
                loading: false,
                error: null,
                matchedResident: resident,
            });

            setData((prev) => ({
                ...prev,
                complainant_resident_id: resident.id,
                complainant_name: resident.name,
                complainant_address: resident.address || prev.complainant_address,
                complainant_contact: resident.contact || prev.complainant_contact,
                complainant_is_resident: true,
            }));
        } catch (err: any) {
            const message = err.response?.data?.message || 'Resident not found or from another barangay.';
            setComplainantLookup((prev) => ({
                ...prev,
                loading: false,
                error: message,
            }));
        }
    };

    // QR Lookup Handler for Respondent
    const handleRespondentLookup = async (tokenQuery?: string) => {
        const token = (tokenQuery !== undefined ? tokenQuery : respondentLookup.query).trim();

        if (!token) {
            setRespondentLookup((prev) => ({
                ...prev,
                error: 'Please scan a QR code or enter a Resident Number.',
            }));
            return;
        }

        setRespondentLookup((prev) => ({ ...prev, loading: true, error: null }));

        try {
            const response = await axios.get(`/lupon/cases/qr-lookup?token=${encodeURIComponent(token)}`);
            const resident = response.data.resident;

            // Check if complainant is already using this resident ID
            if (data.complainant_resident_id && String(data.complainant_resident_id) === String(resident.id)) {
                setRespondentLookup((prev) => ({
                    ...prev,
                    loading: false,
                    error: 'Respondent and Complainant cannot be the same registered resident.',
                }));
                return;
            }

            setRespondentLookup({
                query: '',
                loading: false,
                error: null,
                matchedResident: resident,
            });

            setData((prev) => ({
                ...prev,
                respondent_resident_id: resident.id,
                respondent_name: resident.name,
                respondent_address: resident.address || prev.respondent_address,
                respondent_contact: resident.contact || prev.respondent_contact,
                respondent_is_resident: true,
            }));
        } catch (err: any) {
            const message = err.response?.data?.message || 'Resident not found or from another barangay.';
            setRespondentLookup((prev) => ({
                ...prev,
                loading: false,
                error: message,
            }));
        }
    };

    // Reset Complainant verified link
    const handleClearComplainant = () => {
        setComplainantLookup({
            query: '',
            loading: false,
            error: null,
            matchedResident: null,
        });
        setData((prev) => ({
            ...prev,
            complainant_resident_id: '',
            complainant_name: '',
            complainant_address: '',
            complainant_contact: '',
            complainant_is_resident: true,
        }));
    };

    // Reset Respondent verified link
    const handleClearRespondent = () => {
        setRespondentLookup({
            query: '',
            loading: false,
            error: null,
            matchedResident: null,
        });
        setData((prev) => ({
            ...prev,
            respondent_resident_id: '',
            respondent_name: '',
            respondent_address: '',
            respondent_contact: '',
            respondent_is_resident: true,
        }));
    };

    // Fallback dropdown selection for Complainant
    const handleSelectComplainantDropdown = (residentId: string) => {
        if (!residentId) {
            handleClearComplainant();
            return;
        }

        if (data.respondent_resident_id && String(data.respondent_resident_id) === String(residentId)) {
            setComplainantLookup((prev) => ({
                ...prev,
                error: 'Complainant and Respondent cannot be the same registered resident.',
            }));
            return;
        }

        const found = residents.find((r) => String(r.id) === String(residentId));
        if (found) {
            setComplainantLookup({
                query: '',
                loading: false,
                error: null,
                matchedResident: {
                    id: found.id,
                    name: found.name,
                    resident_number: found.residentNumber,
                    contact: found.mobile,
                },
            });
            setData((prev) => ({
                ...prev,
                complainant_resident_id: found.id,
                complainant_name: found.name,
                complainant_contact: found.mobile || prev.complainant_contact,
                complainant_is_resident: true,
            }));
        }
    };

    // Fallback dropdown selection for Respondent
    const handleSelectRespondentDropdown = (residentId: string) => {
        if (!residentId) {
            handleClearRespondent();
            return;
        }

        if (data.complainant_resident_id && String(data.complainant_resident_id) === String(residentId)) {
            setRespondentLookup((prev) => ({
                ...prev,
                error: 'Respondent and Complainant cannot be the same registered resident.',
            }));
            return;
        }

        const found = residents.find((r) => String(r.id) === String(residentId));
        if (found) {
            setRespondentLookup({
                query: '',
                loading: false,
                error: null,
                matchedResident: {
                    id: found.id,
                    name: found.name,
                    resident_number: found.residentNumber,
                    contact: found.mobile,
                },
            });
            setData((prev) => ({
                ...prev,
                respondent_resident_id: found.id,
                respondent_name: found.name,
                respondent_contact: found.mobile || prev.respondent_contact,
                respondent_is_resident: true,
            }));
        }
    };

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        post('/lupon/cases');
    };

    return (
        <LuponLayout active="cases" user={auth.user}>
            <Head title="Encode Walk-in Case" />

            <div className="mx-auto max-w-5xl space-y-6 font-sans p-2 sm:p-4">
                <div className="flex items-center justify-between">
                    <div>
                        <Link href="/lupon/cases" className="text-sm font-semibold text-gray-500 hover:text-gray-800">
                            ← Back to Cases
                        </Link>
                        <h1 className="mt-1 text-2xl font-bold text-gray-900">Encode Walk-in / Direct Case</h1>
                        <p className="mt-0.5 text-xs text-gray-500">
                            Directly record a case presented at the barangay hall without prior resident online submission.
                        </p>
                    </div>
                </div>

                <form onSubmit={handleSubmit} className="space-y-6">
                    {/* DISPUTE DETAILS */}
                    <div className="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm space-y-4">
                        <h2 className="text-base font-bold text-gray-900 border-b border-gray-100 pb-3">Dispute Details</h2>

                        <div className="grid gap-4 sm:grid-cols-2">
                            <div>
                                <label className="block text-xs font-semibold text-gray-700">Nature of Complaint</label>
                                <select
                                    value={data.case_type}
                                    onChange={(e) => {
                                        const val = e.target.value;
                                        setData((prev) => ({
                                            ...prev,
                                            case_type: val,
                                            custom_case_type: val === 'Other' ? prev.custom_case_type : '',
                                        }));
                                    }}
                                    className="mt-1 w-full rounded-xl border border-gray-300 p-2.5 text-sm outline-none focus:border-[#3f6f3d]"
                                >
                                    {caseTypes.map((t) => (
                                        <option key={t} value={t}>{t}</option>
                                    ))}
                                </select>
                                {errors.case_type && <p className="mt-1 text-xs text-red-600">{errors.case_type}</p>}
                            </div>

                            {/* CONDITIONAL CUSTOM NATURE INPUT */}
                            {data.case_type === 'Other' && (
                                <div>
                                    <label className="block text-xs font-semibold text-gray-700">
                                        Specify Nature of Complaint <span className="text-red-500">*</span>
                                    </label>
                                    <input
                                        type="text"
                                        required
                                        maxLength={80}
                                        placeholder="e.g. Boundary wall dispute, Encroachment, etc."
                                        value={data.custom_case_type}
                                        onChange={(e) => setData('custom_case_type', e.target.value)}
                                        className="mt-1 w-full rounded-xl border border-gray-300 p-2.5 text-sm outline-none focus:border-[#3f6f3d]"
                                    />
                                    {errors.custom_case_type && (
                                        <p className="mt-1 text-xs text-red-600">{errors.custom_case_type}</p>
                                    )}
                                </div>
                            )}

                            <div>
                                <label className="block text-xs font-semibold text-gray-700">Incident Date</label>
                                <input
                                    type="date"
                                    required
                                    value={data.incident_date}
                                    onChange={(e) => setData('incident_date', e.target.value)}
                                    className="mt-1 w-full rounded-xl border border-gray-300 p-2.5 text-sm outline-none focus:border-[#3f6f3d]"
                                />
                                {errors.incident_date && <p className="mt-1 text-xs text-red-600">{errors.incident_date}</p>}
                            </div>

                            <div className="sm:col-span-2">
                                <label className="block text-xs font-semibold text-gray-700">Place of Incident</label>
                                <input
                                    type="text"
                                    required
                                    placeholder="Location where the incident occurred..."
                                    value={data.incident_location}
                                    onChange={(e) => setData('incident_location', e.target.value)}
                                    className="mt-1 w-full rounded-xl border border-gray-300 p-2.5 text-sm outline-none focus:border-[#3f6f3d]"
                                />
                                {errors.incident_location && <p className="mt-1 text-xs text-red-600">{errors.incident_location}</p>}
                            </div>

                            <div className="sm:col-span-2">
                                <label className="block text-xs font-semibold text-gray-700">Narrative / Statement</label>
                                <textarea
                                    required
                                    rows={4}
                                    placeholder="Enter statement or factual background of the dispute..."
                                    value={data.narrative}
                                    onChange={(e) => setData('narrative', e.target.value)}
                                    className="mt-1 w-full rounded-xl border border-gray-300 p-2.5 text-sm outline-none focus:border-[#3f6f3d]"
                                />
                                {errors.narrative && <p className="mt-1 text-xs text-red-600">{errors.narrative}</p>}
                            </div>

                            <div className="sm:col-span-2">
                                <label className="block text-xs font-semibold text-gray-700">Requested Action / Relief</label>
                                <textarea
                                    required
                                    rows={2}
                                    placeholder="Remedy or settlement requested by complainant..."
                                    value={data.requested_action}
                                    onChange={(e) => setData('requested_action', e.target.value)}
                                    className="mt-1 w-full rounded-xl border border-gray-300 p-2.5 text-sm outline-none focus:border-[#3f6f3d]"
                                />
                                {errors.requested_action && <p className="mt-1 text-xs text-red-600">{errors.requested_action}</p>}
                            </div>
                        </div>
                    </div>

                    {/* TWO-COLUMN PARTIES: COMPLAINANT & RESPONDENT */}
                    <div className="grid gap-6 sm:grid-cols-2">
                        {/* PRIMARY COMPLAINANT */}
                        <div className="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm space-y-4">
                            <div className="flex items-center justify-between border-b border-gray-100 pb-3">
                                <h2 className="text-base font-bold text-gray-900">Primary Complainant</h2>
                                {complainantLookup.matchedResident && (
                                    <span className="inline-flex items-center gap-1 rounded-full bg-green-50 border border-green-200 px-2.5 py-0.5 text-[11px] font-bold text-green-700">
                                        ✓ Verified Resident
                                    </span>
                                )}
                            </div>

                            {/* QR SCAN / LOOKUP BOX */}
                            <div className="rounded-xl border border-[#d9e7d5] bg-[#f7faf5] p-3.5 space-y-2.5">
                                <div className="flex items-center justify-between">
                                    <span className="text-xs font-bold text-[#315b30]">📷 QR Code / Resident ID Lookup</span>
                                    {complainantLookup.matchedResident && (
                                        <button
                                            type="button"
                                            onClick={handleClearComplainant}
                                            className="text-xs font-semibold text-red-600 hover:underline"
                                        >
                                            Change / Remove
                                        </button>
                                    )}
                                </div>

                                {!complainantLookup.matchedResident ? (
                                    <div className="space-y-2">
                                        <div className="flex gap-2">
                                            <input
                                                ref={complainantInputRef}
                                                type="text"
                                                placeholder="Scan QR code or enter Resident #..."
                                                value={complainantLookup.query}
                                                onChange={(e) => setComplainantLookup((prev) => ({ ...prev, query: e.target.value, error: null }))}
                                                onKeyDown={(e) => {
                                                    if (e.key === 'Enter') {
                                                        e.preventDefault();
                                                        handleComplainantLookup();
                                                    }
                                                }}
                                                className="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-xs text-gray-800 outline-none focus:border-[#3f6f3d]"
                                            />
                                            <button
                                                type="button"
                                                disabled={complainantLookup.loading}
                                                onClick={() => handleComplainantLookup()}
                                                className="shrink-0 rounded-lg bg-[#3f6f3d] px-3.5 py-2 text-xs font-semibold text-white shadow-sm hover:bg-[#315b30] disabled:opacity-50"
                                            >
                                                {complainantLookup.loading ? '...' : 'Look Up'}
                                            </button>
                                        </div>
                                        <p className="text-[10px] text-gray-500">
                                            Supports 2D USB/Bluetooth barcode scanners. Scanned QR URL or resident number auto-resolves.
                                        </p>
                                    </div>
                                ) : (
                                    <div className="rounded-lg bg-white p-2.5 border border-green-200 text-xs space-y-1">
                                        <p className="font-bold text-gray-900">{complainantLookup.matchedResident.name}</p>
                                        <p className="text-[11px] text-[#3f6f3d]">ID: {complainantLookup.matchedResident.resident_number}</p>
                                        {complainantLookup.matchedResident.address && (
                                            <p className="text-[11px] text-gray-500">{complainantLookup.matchedResident.address}</p>
                                        )}
                                    </div>
                                )}

                                {complainantLookup.error && (
                                    <div className="rounded-lg bg-red-50 border border-red-200 p-2 text-xs text-red-700 flex items-center justify-between">
                                        <span>{complainantLookup.error}</span>
                                        <button
                                            type="button"
                                            onClick={() => setComplainantLookup((prev) => ({ ...prev, error: null }))}
                                            className="text-xs font-bold text-red-800 hover:underline ml-2"
                                        >
                                            Retry
                                        </button>
                                    </div>
                                )}
                            </div>

                            {/* MANUAL / DROPDOWN ALTERNATIVE */}
                            {!complainantLookup.matchedResident && (
                                <div>
                                    <label className="block text-xs font-semibold text-gray-700">Or Select from Registered List</label>
                                    <select
                                        value={data.complainant_resident_id}
                                        onChange={(e) => handleSelectComplainantDropdown(e.target.value)}
                                        className="mt-1 w-full rounded-xl border border-gray-300 p-2.5 text-xs outline-none focus:border-[#3f6f3d]"
                                    >
                                        <option value="">-- Or enter name manually below --</option>
                                        {residents.map((r) => (
                                            <option key={r.id} value={r.id}>
                                                {r.name} ({r.residentNumber})
                                            </option>
                                        ))}
                                    </select>
                                </div>
                            )}

                            <div>
                                <label className="block text-xs font-semibold text-gray-700">
                                    Complainant Full Name <span className="text-red-500">*</span>
                                </label>
                                <input
                                    type="text"
                                    required
                                    value={data.complainant_name}
                                    onChange={(e) => setData('complainant_name', e.target.value)}
                                    placeholder="Enter full name"
                                    className="mt-1 w-full rounded-xl border border-gray-300 p-2.5 text-sm outline-none focus:border-[#3f6f3d]"
                                />
                                {errors.complainant_name && <p className="mt-1 text-xs text-red-600">{errors.complainant_name}</p>}
                            </div>

                            <div>
                                <label className="block text-xs font-semibold text-gray-700">Address</label>
                                <input
                                    type="text"
                                    value={data.complainant_address}
                                    onChange={(e) => setData('complainant_address', e.target.value)}
                                    placeholder="Barangay / Purok / Street address"
                                    className="mt-1 w-full rounded-xl border border-gray-300 p-2.5 text-sm outline-none focus:border-[#3f6f3d]"
                                />
                            </div>

                            <div>
                                <label className="block text-xs font-semibold text-gray-700">Contact Number</label>
                                <input
                                    type="text"
                                    value={data.complainant_contact}
                                    onChange={(e) => setData('complainant_contact', e.target.value)}
                                    placeholder="09..."
                                    className="mt-1 w-full rounded-xl border border-gray-300 p-2.5 text-sm outline-none focus:border-[#3f6f3d]"
                                />
                            </div>

                            {/* Registered resident toggle */}
                            <div className="pt-2 border-t border-gray-100 flex items-center justify-between text-xs text-gray-600">
                                <span>Party is a resident of this barangay:</span>
                                <label className="inline-flex items-center gap-1.5 cursor-pointer">
                                    <input
                                        type="checkbox"
                                        checked={data.complainant_is_resident}
                                        onChange={(e) => {
                                            const checked = e.target.checked;
                                            setData('complainant_is_resident', checked);
                                            if (!checked) {
                                                handleClearComplainant();
                                            }
                                        }}
                                        className="rounded text-[#3f6f3d] focus:ring-[#3f6f3d]"
                                    />
                                    <span className="font-semibold">{data.complainant_is_resident ? 'Yes' : 'No (Non-Resident)'}</span>
                                </label>
                            </div>
                        </div>

                        {/* PRIMARY RESPONDENT */}
                        <div className="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm space-y-4">
                            <div className="flex items-center justify-between border-b border-gray-100 pb-3">
                                <h2 className="text-base font-bold text-gray-900">Primary Respondent</h2>
                                {respondentLookup.matchedResident && (
                                    <span className="inline-flex items-center gap-1 rounded-full bg-green-50 border border-green-200 px-2.5 py-0.5 text-[11px] font-bold text-green-700">
                                        ✓ Verified Resident
                                    </span>
                                )}
                            </div>

                            {/* QR SCAN / LOOKUP BOX */}
                            <div className="rounded-xl border border-[#d9e7d5] bg-[#f7faf5] p-3.5 space-y-2.5">
                                <div className="flex items-center justify-between">
                                    <span className="text-xs font-bold text-[#315b30]">📷 QR Code / Resident ID Lookup</span>
                                    {respondentLookup.matchedResident && (
                                        <button
                                            type="button"
                                            onClick={handleClearRespondent}
                                            className="text-xs font-semibold text-red-600 hover:underline"
                                        >
                                            Change / Remove
                                        </button>
                                    )}
                                </div>

                                {!respondentLookup.matchedResident ? (
                                    <div className="space-y-2">
                                        <div className="flex gap-2">
                                            <input
                                                ref={respondentInputRef}
                                                type="text"
                                                placeholder="Scan QR code or enter Resident #..."
                                                value={respondentLookup.query}
                                                onChange={(e) => setRespondentLookup((prev) => ({ ...prev, query: e.target.value, error: null }))}
                                                onKeyDown={(e) => {
                                                    if (e.key === 'Enter') {
                                                        e.preventDefault();
                                                        handleRespondentLookup();
                                                    }
                                                }}
                                                className="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-xs text-gray-800 outline-none focus:border-[#3f6f3d]"
                                            />
                                            <button
                                                type="button"
                                                disabled={respondentLookup.loading}
                                                onClick={() => handleRespondentLookup()}
                                                className="shrink-0 rounded-lg bg-[#3f6f3d] px-3.5 py-2 text-xs font-semibold text-white shadow-sm hover:bg-[#315b30] disabled:opacity-50"
                                            >
                                                {respondentLookup.loading ? '...' : 'Look Up'}
                                            </button>
                                        </div>
                                        <p className="text-[10px] text-gray-500">
                                            Linking a verified resident enables the case in their "Complaints Against Me" portal.
                                        </p>
                                    </div>
                                ) : (
                                    <div className="rounded-lg bg-white p-2.5 border border-green-200 text-xs space-y-1">
                                        <p className="font-bold text-gray-900">{respondentLookup.matchedResident.name}</p>
                                        <p className="text-[11px] text-[#3f6f3d]">ID: {respondentLookup.matchedResident.resident_number}</p>
                                        {respondentLookup.matchedResident.address && (
                                            <p className="text-[11px] text-gray-500">{respondentLookup.matchedResident.address}</p>
                                        )}
                                    </div>
                                )}

                                {respondentLookup.error && (
                                    <div className="rounded-lg bg-red-50 border border-red-200 p-2 text-xs text-red-700 flex items-center justify-between">
                                        <span>{respondentLookup.error}</span>
                                        <button
                                            type="button"
                                            onClick={() => setRespondentLookup((prev) => ({ ...prev, error: null }))}
                                            className="text-xs font-bold text-red-800 hover:underline ml-2"
                                        >
                                            Retry
                                        </button>
                                    </div>
                                )}
                            </div>

                            {/* MANUAL / DROPDOWN ALTERNATIVE */}
                            {!respondentLookup.matchedResident && (
                                <div>
                                    <label className="block text-xs font-semibold text-gray-700">Or Select from Registered List</label>
                                    <select
                                        value={data.respondent_resident_id}
                                        onChange={(e) => handleSelectRespondentDropdown(e.target.value)}
                                        className="mt-1 w-full rounded-xl border border-gray-300 p-2.5 text-xs outline-none focus:border-[#3f6f3d]"
                                    >
                                        <option value="">-- Or enter name manually below --</option>
                                        {residents.map((r) => (
                                            <option key={r.id} value={r.id}>
                                                {r.name} ({r.residentNumber})
                                            </option>
                                        ))}
                                    </select>
                                </div>
                            )}

                            <div>
                                <label className="block text-xs font-semibold text-gray-700">
                                    Respondent Full Name <span className="text-red-500">*</span>
                                </label>
                                <input
                                    type="text"
                                    required
                                    value={data.respondent_name}
                                    onChange={(e) => setData('respondent_name', e.target.value)}
                                    placeholder="Enter full name"
                                    className="mt-1 w-full rounded-xl border border-gray-300 p-2.5 text-sm outline-none focus:border-[#3f6f3d]"
                                />
                                {errors.respondent_name && <p className="mt-1 text-xs text-red-600">{errors.respondent_name}</p>}
                                {errors.respondent_resident_id && (
                                    <p className="mt-1 text-xs text-red-600">{errors.respondent_resident_id}</p>
                                )}
                            </div>

                            <div>
                                <label className="block text-xs font-semibold text-gray-700">Address</label>
                                <input
                                    type="text"
                                    value={data.respondent_address}
                                    onChange={(e) => setData('respondent_address', e.target.value)}
                                    placeholder="Barangay / Purok / Street address"
                                    className="mt-1 w-full rounded-xl border border-gray-300 p-2.5 text-sm outline-none focus:border-[#3f6f3d]"
                                />
                            </div>

                            <div>
                                <label className="block text-xs font-semibold text-gray-700">Contact Number</label>
                                <input
                                    type="text"
                                    value={data.respondent_contact}
                                    onChange={(e) => setData('respondent_contact', e.target.value)}
                                    placeholder="09..."
                                    className="mt-1 w-full rounded-xl border border-gray-300 p-2.5 text-sm outline-none focus:border-[#3f6f3d]"
                                />
                            </div>

                            {/* Registered resident toggle */}
                            <div className="pt-2 border-t border-gray-100 flex items-center justify-between text-xs text-gray-600">
                                <span>Party is a resident of this barangay:</span>
                                <label className="inline-flex items-center gap-1.5 cursor-pointer">
                                    <input
                                        type="checkbox"
                                        checked={data.respondent_is_resident}
                                        onChange={(e) => {
                                            const checked = e.target.checked;
                                            setData('respondent_is_resident', checked);
                                            if (!checked) {
                                                handleClearRespondent();
                                            }
                                        }}
                                        className="rounded text-[#3f6f3d] focus:ring-[#3f6f3d]"
                                    />
                                    <span className="font-semibold">{data.respondent_is_resident ? 'Yes' : 'No (Non-Resident)'}</span>
                                </label>
                            </div>
                        </div>
                    </div>

                    <div className="flex justify-end gap-3">
                        <Link
                            href="/lupon/cases"
                            className="rounded-xl border border-gray-300 bg-white px-5 py-2.5 text-sm font-semibold text-gray-700 hover:bg-gray-50"
                        >
                            Cancel
                        </Link>
                        <button
                            type="submit"
                            disabled={processing}
                            className="rounded-xl bg-[#3f6f3d] px-6 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-[#315b30] disabled:opacity-50"
                        >
                            {processing ? 'Encoding...' : 'Save & Open Case'}
                        </button>
                    </div>
                </form>
            </div>
        </LuponLayout>
    );
}
