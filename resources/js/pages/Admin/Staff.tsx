import { Head } from '@inertiajs/react';
import { useState } from 'react';
import AdminLayout from '@/layouts/AdminLayout';

type StaffMember = {
    id: number;
    name: string;
    username: string;
    role: string;
    status: 'Active' | 'Inactive';
};

const roles = ['Admin', 'Lupon', 'Senior', 'PWD', 'Clerk'];

const initialStaff: StaffMember[] = [
    {
        id: 1,
        name: 'San Jose Administrator',
        username: 'admin.sanjose@kabarangay.local',
        role: 'Admin',
        status: 'Active',
    },
];

export default function Staff() {
    const [staff, setStaff] = useState<StaffMember[]>(initialStaff);
    const [showModal, setShowModal] = useState(false);

    const [name, setName] = useState('');
    const [username, setUsername] = useState('');
    const [role, setRole] = useState('');
    const [password, setPassword] = useState('');

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();

        if (!name || !username || !role || !password) return;

        setStaff((current) => [
            ...current,
            {
                id: Date.now(),
                name,
                username,
                role,
                status: 'Active',
            },
        ]);

        setName('');
        setUsername('');
        setRole('');
        setPassword('');
        setShowModal(false);
    };

    const toggleStatus = (id: number) => {
        setStaff((current) =>
            current.map((member) =>
                member.id === id
                    ? {
                          ...member,
                          status:
                              member.status === 'Active'
                                  ? 'Inactive'
                                  : 'Active',
                      }
                    : member,
            ),
        );
    };

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
                        onClick={() => setShowModal(true)}
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
                            {
                                staff.filter(
                                    (member) => member.status === 'Active',
                                ).length
                            }
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
                            {staff.length}
                        </p>

                        <p className="mt-1 text-xs text-gray-500">
                            Staff accounts in the system
                        </p>
                    </div>
                </div>

                <div className="overflow-hidden rounded-lg border border-gray-200 bg-white">
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[700px]">
                            <thead className="bg-[#fafaf7]">
                                <tr className="border-b border-gray-200">
                                    <th className="px-5 py-3 text-left text-xs text-gray-400">
                                        REAL NAME
                                    </th>
                                    <th className="px-5 py-3 text-left text-xs text-gray-400">
                                        USERNAME
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
                                {staff.map((member) => (
                                    <tr
                                        key={member.id}
                                        className="border-b border-gray-100 last:border-0"
                                    >
                                        <td className="px-5 py-4 text-sm font-medium">
                                            {member.name}
                                        </td>

                                        <td className="px-5 py-4 text-sm text-gray-600">
                                            {member.username}
                                        </td>

                                        <td className="px-5 py-4">
                                            <span className="rounded-full bg-[#edf4ea] px-3 py-1 text-xs text-[#3f6f3d]">
                                                {member.role}
                                            </span>
                                        </td>

                                        <td className="px-5 py-4">
                                            <span
                                                className={`rounded-full px-3 py-1 text-xs ${
                                                    member.status === 'Active'
                                                        ? 'bg-[#edf4ea] text-[#3f6f3d]'
                                                        : 'bg-gray-100 text-gray-500'
                                                }`}
                                            >
                                                {member.status}
                                            </span>
                                        </td>

                                        <td className="px-5 py-4 text-right">
                                            <button
                                                type="button"
                                                onClick={() =>
                                                    toggleStatus(member.id)
                                                }
                                                className="text-xs font-medium text-[#3f6f3d] hover:underline"
                                            >
                                                {member.status === 'Active'
                                                    ? 'Disable'
                                                    : 'Enable'}
                                            </button>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>

                {showModal && (
                    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
                        <div className="w-full max-w-lg rounded-xl bg-white shadow-xl">
                            <div className="border-b border-gray-200 px-5 py-4">
                                <h2 className="text-lg font-semibold">
                                    Add Staff
                                </h2>
                            </div>

                            <form
                                onSubmit={handleSubmit}
                                className="space-y-4 p-5"
                            >
                                <input
                                    value={name}
                                    onChange={(e) =>
                                        setName(e.target.value)
                                    }
                                    placeholder="Real Name"
                                    className="w-full rounded-md border border-gray-300 px-3 py-2.5 text-sm"
                                    required
                                />

                                <input
                                    value={username}
                                    onChange={(e) =>
                                        setUsername(e.target.value)
                                    }
                                    placeholder="Username"
                                    className="w-full rounded-md border border-gray-300 px-3 py-2.5 text-sm"
                                    required
                                />

                                <select
                                    value={role}
                                    onChange={(e) =>
                                        setRole(e.target.value)
                                    }
                                    className="w-full rounded-md border border-gray-300 px-3 py-2.5 text-sm"
                                    required
                                >
                                    <option value="">Select Role</option>

                                    {roles.map((item) => (
                                        <option key={item} value={item}>
                                            {item}
                                        </option>
                                    ))}
                                </select>

                                <input
                                    type="password"
                                    value={password}
                                    onChange={(e) =>
                                        setPassword(e.target.value)
                                    }
                                    placeholder="Initial Password"
                                    className="w-full rounded-md border border-gray-300 px-3 py-2.5 text-sm"
                                    required
                                />

                                <div className="flex justify-end gap-2 pt-2">
                                    <button
                                        type="button"
                                        onClick={() => setShowModal(false)}
                                        className="rounded-md border border-gray-300 px-4 py-2.5 text-sm"
                                    >
                                        Cancel
                                    </button>

                                    <button
                                        type="submit"
                                        className="rounded-md bg-[#3f6f3d] px-4 py-2.5 text-sm text-white"
                                    >
                                        Create Staff
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