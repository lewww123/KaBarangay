import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import AdminLayout from '@/layouts/AdminLayout';

type StaffMember = {
    id: number;
    name: string;
    email: string;
    staff_id: string | null;
    department: string | null;
    role: string;
    status: 'active' | 'inactive';
    is_self: boolean;
};

type StaffForm = {
    name: string;
    email: string;
    staff_id: string;
    role: string;
    department: string;
    password: string;
};

type StaffPaginatorLink = {
    url: string | null;
    label: string;
    active: boolean;
};

type StaffPaginator = {
    data: StaffMember[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
    links: StaffPaginatorLink[];
};

type Filters = {
    search: string;
    role: string;
    status: string;
};

type Props = {
    auth: { user: { id: number } };
    staff: StaffPaginator;
    stats: { active: number; total: number };
    roles: string[];
    filters: Filters;
};

const emptyForm: StaffForm = {
    name: '',
    email: '',
    staff_id: '',
    role: '',
    department: '',
    password: '',
};

export default function Staff({ staff, stats, roles, filters }: Props) {
    const [showModal, setShowModal] = useState(false);
    const [editing, setEditing] = useState<StaffMember | null>(null);
    const [search, setSearch] = useState(filters.search);
    const [roleFilter, setRoleFilter] = useState(filters.role);
    const [statusFilter, setStatusFilter] = useState(filters.status);

    const form = useForm<StaffForm>({ ...emptyForm });

    const applyFilters = (page?: number) => {
        const params: Record<string, string> = {};
        if (search) params.search = search;
        if (roleFilter) params.role = roleFilter;
        if (statusFilter) params.status = statusFilter;
        if (page && page > 1) params.page = String(page);
        router.get('/admin/staff', params, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    };

    const clearFilters = () => {
        setSearch('');
        setRoleFilter('');
        setStatusFilter('');
        router.get(
            '/admin/staff',
            {},
            {
                preserveState: true,
                preserveScroll: true,
                replace: true,
            },
        );
    };

    const navigateToPage = (url: string | null) => {
        if (!url) return;
        router.get(url, {}, { preserveState: true, preserveScroll: true });
    };

    const submitSearch = (e: React.FormEvent) => {
        e.preventDefault();
        applyFilters();
    };

    const openCreate = () => {
        setEditing(null);
        form.reset();
        form.clearErrors();
        setShowModal(true);
    };

    const openEdit = (member: StaffMember) => {
        setEditing(member);
        form.setData({
            name: member.name,
            email: member.email,
            staff_id: member.staff_id ?? '',
            role: member.role,
            department: member.department ?? '',
            password: '',
        });
        form.clearErrors();
        setShowModal(true);
    };

    const closeModal = () => {
        setShowModal(false);
        setEditing(null);
        form.reset();
        form.clearErrors();
    };

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();

        const options = {
            preserveScroll: true,
            onSuccess: () => closeModal(),
        };

        if (editing) {
            form.put(`/admin/staff/${editing.id}`, options);
        } else {
            form.post('/admin/staff', options);
        }
    };

    const toggleStatus = (member: StaffMember) => {
        if (member.is_self) return;

        router.patch(
            `/admin/staff/${member.id}/status`,
            { status: member.status === 'active' ? 'inactive' : 'active' },
            { preserveScroll: true },
        );
    };

    const errorFor = (field: keyof StaffForm) => form.errors[field];

    return (
        <>
            <Head title="Staff Accounts" />

            <AdminLayout active="staff">
                <div className="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <h1 className="text-2xl font-semibold text-gray-800">
                            Staff Accounts
                        </h1>

                        <p className="mt-1 text-sm text-gray-500">
                            Manage authorized staff accounts
                        </p>
                    </div>

                    <button
                        type="button"
                        onClick={openCreate}
                        className="rounded-md bg-[#3f6f3d] px-4 py-2.5 text-sm font-medium text-white hover:bg-[#315b30]"
                    >
                        + Add Staff
                    </button>
                </div>

                <div className="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div className="rounded-lg bg-[#3f6f3d] p-5 text-white">
                        <p className="text-xs uppercase text-white/80">
                            Active Staff
                        </p>

                        <p className="mt-3 text-3xl font-semibold">
                            {stats.active}
                        </p>

                        <p className="mt-1 text-xs text-white/70">
                            Authorized accounts
                        </p>
                    </div>

                    <div className="rounded-lg border border-gray-200 bg-white p-5">
                        <p className="text-xs uppercase text-gray-400">
                            Total Accounts
                        </p>

                        <p className="mt-3 text-3xl font-semibold text-gray-800">
                            {stats.total}
                        </p>

                        <p className="mt-1 text-xs text-gray-500">
                            Staff accounts in the system
                        </p>
                    </div>
                </div>

                <div className="mb-4 flex flex-col gap-3 rounded-lg border border-gray-200 bg-white p-4 sm:flex-row sm:flex-wrap sm:items-center">
                    <form
                        onSubmit={submitSearch}
                        className="flex flex-1 items-center gap-2"
                        aria-label="Search staff accounts"
                    >
                        <input
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            placeholder="Search by name, email, staff ID, department, or role"
                            className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm"
                        />
                        <button
                            type="submit"
                            className="rounded-md bg-[#3f6f3d] px-4 py-2 text-sm font-medium text-white hover:bg-[#315b30]"
                        >
                            Search
                        </button>
                    </form>

                    <select
                        value={roleFilter}
                        onChange={(e) => {
                            setRoleFilter(e.target.value);
                        }}
                        className="rounded-md border border-gray-300 px-3 py-2 text-sm"
                        aria-label="Filter by role"
                    >
                        <option value="">All Roles</option>
                        {roles.map((item) => (
                            <option key={item} value={item}>
                                {item}
                            </option>
                        ))}
                    </select>

                    <select
                        value={statusFilter}
                        onChange={(e) => {
                            setStatusFilter(e.target.value);
                        }}
                        className="rounded-md border border-gray-300 px-3 py-2 text-sm"
                        aria-label="Filter by status"
                    >
                        <option value="">All Statuses</option>
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>

                    <button
                        type="button"
                        onClick={clearFilters}
                        className="rounded-md border border-gray-300 px-4 py-2 text-sm hover:bg-gray-50"
                    >
                        Clear
                    </button>
                </div>

                <div className="overflow-hidden rounded-lg border border-gray-200 bg-white">
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[900px]">
                            <thead className="bg-[#fafaf7]">
                                <tr className="border-b border-gray-200">
                                    <th className="px-5 py-3 text-left text-xs text-gray-400">
                                        REAL NAME
                                    </th>
                                    <th className="px-5 py-3 text-left text-xs text-gray-400">
                                        EMAIL
                                    </th>
                                    <th className="px-5 py-3 text-left text-xs text-gray-400">
                                        STAFF ID
                                    </th>
                                    <th className="px-5 py-3 text-left text-xs text-gray-400">
                                        DEPARTMENT
                                    </th>
                                    <th className="px-5 py-3 text-left text-xs text-gray-400">
                                        ROLE
                                    </th>
                                    <th className="px-5 py-3 text-left text-xs text-gray-400">
                                        STATUS
                                    </th>
                                    <th className="px-5 py-3 text-right text-xs text-gray-400">
                                        ACTION
                                    </th>
                                </tr>
                            </thead>

                            <tbody>
                                {staff.data.map((member) => (
                                    <tr
                                        key={member.id}
                                        className="border-b border-gray-100 last:border-0"
                                    >
                                        <td className="px-5 py-4 text-sm font-medium">
                                            {member.name}

                                            {member.is_self && (
                                                <span className="ml-2 rounded-full bg-[#edf4ea] px-2 py-0.5 text-[10px] text-[#3f6f3d]">
                                                    You
                                                </span>
                                            )}
                                        </td>

                                        <td className="px-5 py-4 text-sm text-gray-600">
                                            {member.email}
                                        </td>

                                        <td className="px-5 py-4 text-sm text-gray-600">
                                            {member.staff_id ?? '—'}
                                        </td>

                                        <td className="px-5 py-4 text-sm text-gray-600">
                                            {member.department ?? '—'}
                                        </td>

                                        <td className="px-5 py-4">
                                            <span className="rounded-full bg-[#edf4ea] px-3 py-1 text-xs text-[#3f6f3d]">
                                                {member.role}
                                            </span>
                                        </td>

                                        <td className="px-5 py-4">
                                            <span
                                                className={`rounded-full px-3 py-1 text-xs ${
                                                    member.status === 'active'
                                                        ? 'bg-[#edf4ea] text-[#3f6f3d]'
                                                        : 'bg-gray-100 text-gray-500'
                                                }`}
                                            >
                                                {member.status === 'active'
                                                    ? 'Active'
                                                    : 'Inactive'}
                                            </span>
                                        </td>

                                        <td className="px-5 py-4 text-right">
                                            <button
                                                type="button"
                                                onClick={() => openEdit(member)}
                                                className="mr-3 text-xs font-medium text-[#3f6f3d] hover:underline"
                                            >
                                                Edit
                                            </button>

                                            <button
                                                type="button"
                                                onClick={() =>
                                                    toggleStatus(member)
                                                }
                                                disabled={member.is_self}
                                                title={
                                                    member.is_self
                                                        ? 'You cannot disable your own account'
                                                        : undefined
                                                }
                                                className={`text-xs font-medium hover:underline ${
                                                    member.is_self
                                                        ? 'cursor-not-allowed text-gray-300'
                                                        : 'text-[#3f6f3d]'
                                                }`}
                                            >
                                                {member.status === 'active'
                                                    ? 'Disable'
                                                    : 'Enable'}
                                            </button>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>

                    {staff.data.length === 0 && (
                        <p className="px-5 py-10 text-center text-sm text-gray-400">
                            {search || roleFilter || statusFilter
                                ? 'No staff accounts match the selected filters.'
                                : 'No staff accounts found.'}
                        </p>
                    )}

                    {staff.last_page > 1 && (
                        <div className="flex flex-wrap items-center justify-between gap-3 px-5 py-4">
                            <p className="text-xs text-gray-500">
                                Showing {staff.from ?? 0} to {staff.to ?? 0} of{' '}
                                {staff.total} staff accounts
                            </p>

                            <div className="flex flex-wrap gap-1">
                                {staff.links.map((link, index) => (
                                    <button
                                        key={index}
                                        type="button"
                                        onClick={() => navigateToPage(link.url)}
                                        disabled={!link.url}
                                        className={`rounded-md px-3 py-1 text-xs ${
                                            link.active
                                                ? 'bg-[#3f6f3d] text-white'
                                                : link.url
                                                  ? 'border border-gray-300 text-gray-600 hover:bg-gray-50'
                                                  : 'cursor-not-allowed border border-gray-200 text-gray-300'
                                        }`}
                                        dangerouslySetInnerHTML={{
                                            __html: link.label,
                                        }}
                                    />
                                ))}
                            </div>
                        </div>
                    )}
                </div>

                {showModal && (
                    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
                        <div className="w-full max-w-lg rounded-xl bg-white shadow-xl">
                            <div className="border-b border-gray-200 px-5 py-4">
                                <h2 className="text-lg font-semibold">
                                    {editing ? 'Edit Staff' : 'Add Staff'}
                                </h2>
                            </div>

                            <form
                                onSubmit={handleSubmit}
                                className="space-y-4 p-5"
                            >
                                <div>
                                    <input
                                        value={form.data.name}
                                        onChange={(e) =>
                                            form.setData('name', e.target.value)
                                        }
                                        placeholder="Real Name"
                                        className="w-full rounded-md border border-gray-300 px-3 py-2.5 text-sm"
                                        required
                                    />

                                    {errorFor('name') && (
                                        <p className="mt-1 text-xs text-red-500">
                                            {errorFor('name')}
                                        </p>
                                    )}
                                </div>

                                <div>
                                    <input
                                        type="email"
                                        value={form.data.email}
                                        onChange={(e) =>
                                            form.setData('email', e.target.value)
                                        }
                                        placeholder="Email"
                                        className="w-full rounded-md border border-gray-300 px-3 py-2.5 text-sm"
                                        required
                                    />

                                    {errorFor('email') && (
                                        <p className="mt-1 text-xs text-red-500">
                                            {errorFor('email')}
                                        </p>
                                    )}
                                </div>

                                <div>
                                    <input
                                        value={form.data.staff_id}
                                        onChange={(e) =>
                                            form.setData(
                                                'staff_id',
                                                e.target.value,
                                            )
                                        }
                                        placeholder="Staff ID"
                                        className="w-full rounded-md border border-gray-300 px-3 py-2.5 text-sm"
                                        required
                                    />

                                    {errorFor('staff_id') && (
                                        <p className="mt-1 text-xs text-red-500">
                                            {errorFor('staff_id')}
                                        </p>
                                    )}
                                </div>

                                <div>
                                    <select
                                        value={form.data.role}
                                        onChange={(e) =>
                                            form.setData('role', e.target.value)
                                        }
                                        disabled={editing?.is_self}
                                        className="w-full rounded-md border border-gray-300 px-3 py-2.5 text-sm disabled:bg-gray-100 disabled:text-gray-400"
                                        required
                                    >
                                        <option value="">Select Role</option>

                                        {roles.map((item) => (
                                            <option key={item} value={item}>
                                                {item}
                                            </option>
                                        ))}
                                    </select>

                                    {editing?.is_self && (
                                        <p className="mt-1 text-xs text-gray-400">
                                            You cannot change your own role.
                                        </p>
                                    )}

                                    {errorFor('role') && (
                                        <p className="mt-1 text-xs text-red-500">
                                            {errorFor('role')}
                                        </p>
                                    )}
                                </div>

                                <div>
                                    <input
                                        value={form.data.department}
                                        onChange={(e) =>
                                            form.setData(
                                                'department',
                                                e.target.value,
                                            )
                                        }
                                        placeholder="Department (optional)"
                                        className="w-full rounded-md border border-gray-300 px-3 py-2.5 text-sm"
                                    />

                                    {errorFor('department') && (
                                        <p className="mt-1 text-xs text-red-500">
                                            {errorFor('department')}
                                        </p>
                                    )}
                                </div>

                                <div>
                                    <input
                                        type="password"
                                        value={form.data.password}
                                        onChange={(e) =>
                                            form.setData(
                                                'password',
                                                e.target.value,
                                            )
                                        }
                                        placeholder={
                                            editing
                                                ? 'New Password (leave blank to keep current)'
                                                : 'Initial Password'
                                        }
                                        className="w-full rounded-md border border-gray-300 px-3 py-2.5 text-sm"
                                        required={!editing}
                                    />

                                    {errorFor('password') && (
                                        <p className="mt-1 text-xs text-red-500">
                                            {errorFor('password')}
                                        </p>
                                    )}
                                </div>

                                <div className="flex justify-end gap-2 pt-2">
                                    <button
                                        type="button"
                                        onClick={closeModal}
                                        className="rounded-md border border-gray-300 px-4 py-2.5 text-sm"
                                    >
                                        Cancel
                                    </button>

                                    <button
                                        type="submit"
                                        disabled={form.processing}
                                        className="rounded-md bg-[#3f6f3d] px-4 py-2.5 text-sm text-white disabled:opacity-50"
                                    >
                                        {form.processing
                                            ? 'Saving...'
                                            : editing
                                              ? 'Save Changes'
                                              : 'Create Staff'}
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                )}
            </AdminLayout>
        </>
    );
}
