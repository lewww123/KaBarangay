import { Head, router, useForm } from '@inertiajs/react';
import { FormEvent, useEffect, useState } from 'react';
import AdminLayout from '@/layouts/AdminLayout';

type Resident = { id: number; name: string; resident_number: string | null };
type Assignment = {
    id: number;
    resident: Resident;
    user_email: string;
    department: string;
    position: string;
    is_active: boolean;
};
type Props = {
    assignments: { data: Assignment[] };
    filters: { search: string };
};

export default function Staff({ assignments, filters }: Props) {
    const [search, setSearch] = useState(filters.search);
    const [residentSearch, setResidentSearch] = useState('');
    const [residents, setResidents] = useState<Resident[]>([]);
    const [editing, setEditing] = useState<Assignment | null>(null);
    const form = useForm({ resident_id: '', department: '', position: '' });

    useEffect(() => {
        if (residentSearch.trim().length < 2) {
            setResidents([]);
            return;
        }

        const controller = new AbortController();
        const timer = window.setTimeout(async () => {
            const response = await fetch(`/admin/staff/residents?search=${encodeURIComponent(residentSearch)}`, {
                headers: { Accept: 'application/json' },
                signal: controller.signal,
            });
            if (response.ok) setResidents((await response.json()).residents);
        }, 250);

        return () => {
            controller.abort();
            window.clearTimeout(timer);
        };
    }, [residentSearch]);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        const options = { preserveScroll: true, onSuccess: () => {
            form.reset();
            setEditing(null);
            setResidentSearch('');
            setResidents([]);
        } };
        if (editing) form.put(`/admin/staff/${editing.id}`, options);
        else form.post('/admin/staff', options);
    };

    const edit = (assignment: Assignment) => {
        setEditing(assignment);
        setResidentSearch(assignment.resident.name);
        form.setData({ resident_id: String(assignment.resident.id), department: assignment.department, position: assignment.position });
        form.clearErrors();
    };

    return (
        <AdminLayout active="staff">
            <Head title="Staff Assignments" />
            <div className="mx-auto max-w-5xl space-y-6">
                <div><h1 className="text-2xl font-semibold">Staff Assignments</h1><p className="text-sm text-gray-500">Assign eligible resident accounts to barangay positions.</p></div>
                <form onSubmit={(event) => { event.preventDefault(); router.get('/admin/staff', search ? { search } : {}, { preserveState: true, replace: true }); }} className="flex gap-2">
                    <input value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Search assignments" className="flex-1 rounded border px-3 py-2" />
                    <button className="rounded bg-[#3f6f3d] px-4 py-2 text-white">Search</button>
                </form>
                <div className="rounded border bg-white p-4">
                    <h2 className="mb-3 font-medium">{editing ? 'Edit assignment' : 'New assignment'}</h2>
                    <form onSubmit={submit} className="grid gap-3 sm:grid-cols-3">
                        <div className="relative">
                            <input value={residentSearch} disabled={!!editing} onChange={(event) => { setResidentSearch(event.target.value); form.setData('resident_id', ''); }} placeholder="Find resident" className="w-full rounded border px-3 py-2 disabled:bg-gray-100" required />
                            {!editing && residents.length > 0 && <div className="absolute z-10 w-full border bg-white">{residents.map((resident) => <button type="button" key={resident.id} onClick={() => { setResidentSearch(resident.name); form.setData('resident_id', String(resident.id)); setResidents([]); }} className="block w-full px-3 py-2 text-left text-sm hover:bg-gray-50">{resident.name} ({resident.resident_number ?? 'No number'})</button>)}</div>}
                            {form.errors.resident_id && <p className="text-xs text-red-600">{form.errors.resident_id}</p>}
                        </div>
                        <input value={form.data.department} onChange={(event) => form.setData('department', event.target.value)} placeholder="Department" className="rounded border px-3 py-2" required />
                        <input value={form.data.position} onChange={(event) => form.setData('position', event.target.value)} placeholder="Position" className="rounded border px-3 py-2" required />
                        <div className="sm:col-span-3"><button disabled={form.processing} className="rounded bg-[#3f6f3d] px-4 py-2 text-white disabled:opacity-50">{editing ? 'Save assignment' : 'Create assignment'}</button>{editing && <button type="button" onClick={() => { setEditing(null); form.reset(); setResidentSearch(''); }} className="ml-2 rounded border px-4 py-2">Cancel</button>}</div>
                    </form>
                </div>
                <div className="overflow-hidden rounded border bg-white"><table className="w-full text-left text-sm"><thead className="bg-gray-50"><tr><th className="p-3">Resident</th><th className="p-3">Account</th><th className="p-3">Department</th><th className="p-3">Position</th><th className="p-3">Status</th><th className="p-3" /></tr></thead><tbody>{assignments.data.map((assignment) => <tr key={assignment.id} className="border-t"><td className="p-3">{assignment.resident.name}</td><td className="p-3">{assignment.user_email}</td><td className="p-3">{assignment.department}</td><td className="p-3">{assignment.position}</td><td className="p-3">{assignment.is_active ? 'Active' : 'Inactive'}</td><td className="p-3 whitespace-nowrap"><button onClick={() => edit(assignment)} className="mr-3 text-[#3f6f3d]">Edit</button>{assignment.is_active && <button onClick={() => router.patch(`/admin/staff/${assignment.id}/deactivate`, {}, { preserveScroll: true })} className="text-red-700">Deactivate</button>}</td></tr>)}</tbody></table>{assignments.data.length === 0 && <p className="p-6 text-center text-sm text-gray-500">No staff assignments found.</p>}</div>
            </div>
        </AdminLayout>
    );
}
