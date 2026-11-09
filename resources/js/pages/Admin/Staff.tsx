import { Head, router, useForm } from '@inertiajs/react';
import { FormEvent, useEffect, useRef, useState } from 'react';
import AdminLayout from '@/layouts/AdminLayout';
import QrCamera from '@/components/lupon/QrCamera';

type Resident = { id: number; name: string; resident_number: string | null };
type Assignment = {
    id: number;
    resident: Resident;
    user_email: string;
    department: string;
    department_label: string;
    position: string;
    is_active: boolean;
};
type Department = { value: string; label: string; position: string };
type Props = {
    assignments: {
        data: Assignment[];
        total: number;
        from: number | null;
        to: number | null;
        current_page: number;
        last_page: number;
        prev_page_url: string | null;
        next_page_url: string | null;
    };
    filters: { search: string };
    departments: Department[];
};

export default function Staff({ assignments, filters, departments }: Props) {
    const [search, setSearch] = useState(filters.search);
    const [residentSearch, setResidentSearch] = useState('');
    const [residents, setResidents] = useState<Resident[]>([]);
    const [selectedResident, setSelectedResident] = useState<Resident | null>(
        null,
    );
    const [editing, setEditing] = useState<Assignment | null>(null);
    const [open, setOpen] = useState(false);
    const [scanning, setScanning] = useState(false);
    const [lookupError, setLookupError] = useState('');
    const [searching, setSearching] = useState(false);
    const [searched, setSearched] = useState(false);
    const [success, setSuccess] = useState('');
    const dialog = useRef<HTMLDialogElement>(null);
    const lookupVersion = useRef(0);
    const form = useForm({ resident_id: '', department: '', position: '' });

    useEffect(() => {
        if (open) dialog.current?.showModal();
        else dialog.current?.close();
    }, [open]);
    useEffect(() => {
        if (
            !open ||
            selectedResident ||
            editing ||
            residentSearch.trim().length < 2
        ) {
            setResidents([]);
            setSearching(false);
            return;
        }
        const controller = new AbortController();
        setSearching(true);
        setSearched(false);
        const timer = window.setTimeout(async () => {
            try {
                const response = await fetch(
                    `/admin/staff/residents?search=${encodeURIComponent(residentSearch.trim())}`,
                    {
                        headers: { Accept: 'application/json' },
                        signal: controller.signal,
                    },
                );
                if (!response.ok) throw new Error('Search failed');
                const payload = await response.json();
                if (!controller.signal.aborted) {
                    setResidents(payload.residents ?? []);
                    setSearched(true);
                }
            } catch {
                if (!controller.signal.aborted)
                    setLookupError(
                        'Unable to search residents. Please try again.',
                    );
            } finally {
                if (!controller.signal.aborted) setSearching(false);
            }
        }, 250);
        return () => {
            controller.abort();
            window.clearTimeout(timer);
        };
    }, [open, residentSearch, selectedResident, editing]);

    const clear = () => {
        lookupVersion.current++;
        setOpen(false);
        setScanning(false);
        setEditing(null);
        setSelectedResident(null);
        setResidentSearch('');
        setResidents([]);
        setSearched(false);
        setLookupError('');
        form.reset();
        form.clearErrors();
    };
    const close = () => {
        if (!form.processing) clear();
    };
    const selectResident = (resident: Resident) => {
        lookupVersion.current++;
        setSelectedResident(resident);
        setResidentSearch(resident.name);
        setResidents([]);
        setLookupError('');
        form.setData('resident_id', String(resident.id));
        form.clearErrors('resident_id');
    };
    const scan = async (token: string) => {
        const version = ++lookupVersion.current;
        try {
            const response = await fetch(
                `/api/qr/verify-token?token=${encodeURIComponent(token)}`,
                { headers: { Accept: 'application/json' } },
            );
            if (!response.ok)
                throw new Error(
                    'QR code not found in your barangay, or the resident is inactive.',
                );
            const payload = await response.json();
            const eligible = await fetch(
                `/admin/staff/residents?resident_id=${payload.resident.id}`,
                { headers: { Accept: 'application/json' } },
            );
            if (!eligible.ok)
                throw new Error(
                    'Unable to verify this resident. Please try again.',
                );
            const matches = await eligible.json();
            if (!matches.residents?.length)
                throw new Error(
                    'This resident needs an active account and linked user before staff assignment.',
                );
            if (version !== lookupVersion.current) return false;
            selectResident(matches.residents[0]);
            setScanning(false);
            return true;
        } catch (error) {
            if (version === lookupVersion.current)
                setLookupError(
                    error instanceof Error
                        ? error.message
                        : 'Unable to read QR code.',
                );
            return false;
        }
    };
    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (!form.data.resident_id) {
            form.setError(
                'resident_id',
                'Select a resident from the suggestions or scan their QR code.',
            );
            return;
        }
        const options = {
            preserveScroll: true,
            onSuccess: () => {
                setSuccess(
                    editing
                        ? 'Staff assignment updated.'
                        : 'Staff assigned successfully.',
                );
                clear();
            },
        };
        if (editing) form.put(`/admin/staff/${editing.id}`, options);
        else form.post('/admin/staff', options);
    };
    const edit = (assignment: Assignment) => {
        setEditing(assignment);
        setSelectedResident(assignment.resident);
        setResidentSearch(assignment.resident.name);
        form.setData({
            resident_id: String(assignment.resident.id),
            department: assignment.department,
            position: assignment.position,
        });
        form.clearErrors();
        setLookupError('');
        setOpen(true);
    };
    const inputClass =
        'mt-1 min-h-11 w-full rounded-lg border border-line bg-white px-3 py-2 text-sm disabled:bg-secondary';

    return (
        <AdminLayout active="staff">
            <Head title="Staff" />
            <div className="mx-auto max-w-7xl space-y-6">
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-semibold text-ink-strong">
                            Barangay Staff
                        </h1>
                        <p className="mt-1 text-sm text-ink-muted">
                            Staff members, positions, and department assignments
                            in your barangay.
                        </p>
                    </div>
                    <button
                        type="button"
                        onClick={() => {
                            clear();
                            setOpen(true);
                        }}
                        className="min-h-11 rounded-lg bg-brand px-5 py-2.5 text-sm font-semibold text-white"
                    >
                        Assign new staff
                    </button>
                </div>
                {success && (
                    <p
                        role="status"
                        className="rounded-lg bg-green-50 p-3 text-sm text-green-800"
                    >
                        {success}
                    </p>
                )}
                <form
                    onSubmit={(event) => {
                        event.preventDefault();
                        router.get('/admin/staff', search ? { search } : {}, {
                            preserveState: true,
                            replace: true,
                        });
                    }}
                    className="flex gap-2"
                >
                    <input
                        aria-label="Search staff"
                        value={search}
                        onChange={(event) => setSearch(event.target.value)}
                        placeholder="Search staff, position, or department…"
                        className="min-w-0 flex-1 rounded-lg border border-line px-3 py-2 text-sm"
                    />
                    <button className="rounded-lg border border-line bg-white px-4 py-2 text-sm font-semibold">
                        Search
                    </button>
                </form>
                <div className="overflow-hidden rounded-xl border border-line-soft bg-white">
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[760px] text-left text-sm">
                            <thead className="bg-canvas text-ink-muted">
                                <tr>
                                    <th className="p-4">Staff member</th>
                                    <th className="p-4">Position</th>
                                    <th className="p-4">Department</th>
                                    <th className="p-4">Status</th>
                                    <th className="p-4">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                {assignments.data.map((assignment) => (
                                    <tr
                                        key={assignment.id}
                                        className="border-t border-line-soft"
                                    >
                                        <td className="p-4">
                                            <p className="font-semibold text-ink-strong">
                                                {assignment.resident.name}
                                            </p>
                                            <p className="mt-1 text-xs text-ink-muted">
                                                {assignment.user_email}
                                            </p>
                                            <p className="text-xs text-ink-muted">
                                                {
                                                    assignment.resident
                                                        .resident_number
                                                }
                                            </p>
                                        </td>
                                        <td className="p-4 font-medium">
                                            {assignment.position}
                                        </td>
                                        <td className="p-4">
                                            {assignment.department_label ??
                                                assignment.department}
                                        </td>
                                        <td className="p-4">
                                            <span
                                                className={`rounded-full px-2.5 py-1 text-xs font-semibold ${assignment.is_active ? 'bg-green-50 text-green-800' : 'bg-secondary text-ink-muted'}`}
                                            >
                                                {assignment.is_active
                                                    ? 'Active'
                                                    : 'Inactive'}
                                            </span>
                                        </td>
                                        <td className="p-4 whitespace-nowrap">
                                            <button
                                                type="button"
                                                onClick={() => edit(assignment)}
                                                className="mr-4 font-semibold text-brand"
                                            >
                                                Edit
                                            </button>
                                            {assignment.is_active ? (
                                                <button
                                                    type="button"
                                                    onClick={() =>
                                                        router.patch(
                                                            `/admin/staff/${assignment.id}/deactivate`,
                                                            {},
                                                            {
                                                                preserveScroll: true,
                                                            },
                                                        )
                                                    }
                                                    className="text-red-700"
                                                >
                                                    Deactivate
                                                </button>
                                            ) : (
                                                <button
                                                    type="button"
                                                    onClick={() =>
                                                        router.patch(
                                                            `/admin/staff/${assignment.id}/activate`,
                                                            {},
                                                            {
                                                                preserveScroll: true,
                                                            },
                                                        )
                                                    }
                                                    className="font-semibold text-brand"
                                                >
                                                    Activate
                                                </button>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    {assignments.data.length === 0 && (
                        <p className="p-8 text-center text-sm text-ink-muted">
                            No staff assignments found.
                        </p>
                    )}
                    <div className="flex flex-wrap items-center justify-between gap-3 border-t border-line-soft px-4 py-3 text-sm text-ink-muted">
                        <span>
                            {assignments.total
                                ? `Showing ${assignments.from}–${assignments.to} of ${assignments.total} assignments`
                                : '0 assignments'}
                        </span>
                        <div className="flex items-center gap-3">
                            <button
                                disabled={!assignments.prev_page_url}
                                onClick={() =>
                                    assignments.prev_page_url &&
                                    router.get(
                                        assignments.prev_page_url,
                                        {},
                                        {
                                            preserveState: true,
                                            preserveScroll: true,
                                        },
                                    )
                                }
                                className="rounded border px-3 py-1.5 disabled:opacity-40"
                            >
                                Previous
                            </button>
                            <span>
                                Page {assignments.current_page} of{' '}
                                {assignments.last_page}
                            </span>
                            <button
                                disabled={!assignments.next_page_url}
                                onClick={() =>
                                    assignments.next_page_url &&
                                    router.get(
                                        assignments.next_page_url,
                                        {},
                                        {
                                            preserveState: true,
                                            preserveScroll: true,
                                        },
                                    )
                                }
                                className="rounded border px-3 py-1.5 disabled:opacity-40"
                            >
                                Next
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            <dialog
                ref={dialog}
                onCancel={(event) => {
                    event.preventDefault();
                    close();
                }}
                aria-labelledby="staff-dialog-title"
                className="m-auto max-h-[90dvh] w-[calc(100%-2rem)] max-w-xl overflow-y-auto rounded-xl border-0 bg-white p-0 shadow-xl backdrop:bg-black/40"
            >
                <div className="flex items-center justify-between border-b border-line-soft px-5 py-3">
                    <h2 id="staff-dialog-title" className="text-lg font-semibold">
                        {editing ? 'Edit staff assignment' : 'Assign new staff'}
                    </h2>
                    <button
                        type="button"
                        onClick={close}
                        disabled={form.processing}
                        aria-label="Close staff form"
                        className="rounded p-2 text-ink-muted"
                    >
                        
                    </button>
                </div>
                <form onSubmit={submit} className="space-y-5 p-6">
                    <div>
                        <label
                            htmlFor="staff-resident"
                            className="text-sm font-semibold"
                        >
                            Resident
                        </label>
                        <input
                            autoFocus
                            id="staff-resident"
                            autoComplete="off"
                            value={residentSearch}
                            disabled={!!editing}
                            onChange={(event) => {
                                lookupVersion.current++;
                                setResidentSearch(event.target.value);
                                setSelectedResident(null);
                                setResidents([]);
                                setSearched(false);
                                setLookupError('');
                                form.setData('resident_id', '');
                            }}
                            placeholder="Type a name or resident number"
                            className={inputClass}
                            required
                        />
                        {!selectedResident && searching && (
                            <p
                                role="status"
                                className="mt-2 text-xs text-ink-muted"
                            >
                                Searching residents…
                            </p>
                        )}
                        {!selectedResident && residents.length > 0 && (
                            <div
                                aria-label="Resident suggestions"
                                className="mt-1 max-h-48 overflow-y-auto rounded-lg border border-line-soft"
                            >
                                {residents.map((resident) => (
                                    <button
                                        type="button"
                                        key={resident.id}
                                        onClick={() => selectResident(resident)}
                                        className="block w-full border-b border-line-soft px-3 py-2 text-left text-sm hover:bg-green-50"
                                    >
                                        <span className="block font-medium">
                                            {resident.name}
                                        </span>
                                        <span className="text-xs text-ink-muted">
                                            {resident.resident_number}
                                        </span>
                                    </button>
                                ))}
                            </div>
                        )}
                        {!selectedResident &&
                            searched &&
                            !searching &&
                            residents.length === 0 && (
                                <p className="mt-2 text-xs text-ink-muted">
                                    No eligible resident found. An active
                                    resident account and linked user are
                                    required.
                                </p>
                            )}
                        {selectedResident && (
                            <p className="mt-2 text-xs font-medium text-green-800">
                                Selected: {selectedResident.name} ·{' '}
                                {selectedResident.resident_number}
                            </p>
                        )}
                        {form.errors.resident_id && (
                            <p
                                role="alert"
                                className="mt-1 text-xs text-red-600"
                            >
                                {form.errors.resident_id}
                            </p>
                        )}
                        {!editing && (
                            <button
                                type="button"
                                onClick={() => {
                                    lookupVersion.current++;
                                    setScanning(!scanning);
                                    setLookupError('');
                                }}
                                className="mt-3 rounded-lg border border-brand px-3 py-2 text-sm font-semibold text-brand-deep"
                            >
                                {scanning
                                    ? 'Close scanner'
                                    : 'Scan resident QR'}
                            </button>
                        )}
                        {scanning && (
                            <QrCamera
                                onScan={scan}
                                onClose={() => {
                                    lookupVersion.current++;
                                    setScanning(false);
                                }}
                            />
                        )}
                        {lookupError && (
                            <p
                                role="alert"
                                className="mt-2 text-sm text-red-700"
                            >
                                {lookupError}
                            </p>
                        )}
                    </div>
                    <div>
                        <label
                            htmlFor="staff-department"
                            className="text-sm font-semibold"
                        >
                            Department
                        </label>
                        <select
                            id="staff-department"
                            value={form.data.department}
                            onChange={(event) => {
                                const department = departments.find(
                                    (item) => item.value === event.target.value,
                                );
                                form.setData({
                                    ...form.data,
                                    department: event.target.value,
                                    position: department?.position ?? '',
                                });
                            }}
                            className={inputClass}
                            required
                        >
                            <option value="">Select department</option>
                            {departments.map((department) => (
                                <option
                                    key={department.value}
                                    value={department.value}
                                >
                                    {department.label}
                                </option>
                            ))}
                            {editing &&
                                !departments.some(
                                    (department) =>
                                        department.value === editing.department,
                                ) && (
                                    <option value={editing.department}>
                                        {editing.department_label ??
                                            editing.department}
                                    </option>
                                )}
                        </select>
                        {form.errors.department && (
                            <p
                                role="alert"
                                className="mt-1 text-xs text-red-600"
                            >
                                {form.errors.department}
                            </p>
                        )}
                    </div>
                    <div>
                        <label
                            htmlFor="staff-position"
                            className="text-sm font-semibold"
                        >
                            Position
                        </label>
                        <input
                            id="staff-position"
                            value={form.data.position}
                            onChange={(event) =>
                                form.setData('position', event.target.value)
                            }
                            placeholder="e.g. Barangay Clerk"
                            maxLength={100}
                            className={inputClass}
                            required
                        />
                        {form.errors.position && (
                            <p
                                role="alert"
                                className="mt-1 text-xs text-red-600"
                            >
                                {form.errors.position}
                            </p>
                        )}
                    </div>
                    <div className="flex justify-end gap-3 border-t border-line-soft pt-4">
                        <button
                            type="button"
                            onClick={close}
                            disabled={form.processing}
                            className="rounded-lg border border-line px-4 py-2 text-sm font-semibold"
                        >
                            Cancel
                        </button>
                        <button
                            disabled={form.processing}
                            className="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white disabled:opacity-50"
                        >
                            {form.processing
                                ? 'Saving…'
                                : editing
                                  ? 'Save assignment'
                                  : 'Assign staff'}
                        </button>
                    </div>
                </form>
            </dialog>
        </AdminLayout>
    );
}
