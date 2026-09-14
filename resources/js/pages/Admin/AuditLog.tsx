import { Head } from '@inertiajs/react';
import AdminLayout from '@/layouts/AdminLayout';

const activityLogs = [
    {
        id: 1,
        timestamp: '05/05/2026, 09:37:46 AM',
        sector: 'Admin / Superuser',
        staff: '@admin',
        action: 'Logged in',
    },
    {
        id: 2,
        timestamp: '2026-04-11 03:15 PM',
        sector: 'Residents',
        staff: '@admin',
        action: 'Created resident',
    },
    {
        id: 3,
        timestamp: '2026-04-11 11:15 AM',
        sector: 'Certificates',
        staff: '@ila.r',
        action: 'Issued certificate',
    },
    {
        id: 4,
        timestamp: '2026-04-10 04:00 PM',
        sector: 'Lupon',
        staff: '@jose.s',
        action: 'Filed complaint',
    },
];

const loginAttempts = [
    { username: 'admin', time: '10:30 AM', status: 'Success' },
    { username: 'staff1', time: '10:35 AM', status: 'Failed' },
    { username: 'staff1', time: '10:36 AM', status: 'Failed' },
];

export default function AuditLog() {
    return (
        <>
            <Head title="Audit Log" />

            <AdminLayout active="audit-log">
                <div className="mb-6">
                    <h1 className="text-2xl font-semibold text-gray-800">
                        Audit Log
                    </h1>

                    <p className="mt-1 text-sm text-gray-500">
                        System activity and security monitoring
                    </p>
                </div>

                <section>
                    <h2 className="mb-4 text-base font-semibold">
                        Activity Log
                    </h2>

                    <div className="overflow-hidden rounded-lg border border-gray-200 bg-white">
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[700px]">
                                <thead className="bg-[#fafaf7]">
                                    <tr className="border-b">
                                        <th className="px-5 py-3 text-left text-xs text-gray-400">
                                            TIMESTAMP
                                        </th>
                                        <th className="px-5 py-3 text-left text-xs text-gray-400">
                                            SECTOR
                                        </th>
                                        <th className="px-5 py-3 text-left text-xs text-gray-400">
                                            STAFF
                                        </th>
                                        <th className="px-5 py-3 text-left text-xs text-gray-400">
                                            ACTION
                                        </th>
                                    </tr>
                                </thead>

                                <tbody>
                                    {activityLogs.map((log) => (
                                        <tr
                                            key={log.id}
                                            className="border-b border-gray-100 last:border-0"
                                        >
                                            <td className="px-5 py-4 text-sm text-gray-600">
                                                {log.timestamp}
                                            </td>

                                            <td className="px-5 py-4">
                                                <span className="rounded-full bg-[#e2f0fc] px-3 py-1 text-xs text-[#21679d]">
                                                    {log.sector}
                                                </span>
                                            </td>

                                            <td className="px-5 py-4 text-sm">
                                                {log.staff}
                                            </td>

                                            <td className="px-5 py-4 text-sm">
                                                {log.action}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </div>
                </section>

                <section className="mt-7">
                    <h2 className="mb-4 text-base font-semibold text-[#315b30]">
                        Security Logs
                    </h2>

                    <div className="grid grid-cols-1 gap-5 xl:grid-cols-2">
                        <div className="rounded-lg border border-gray-200 bg-white p-5">
                            <h3 className="mb-4 text-sm font-semibold">
                                Login Attempts
                            </h3>

                            <div className="overflow-hidden rounded-md border">
                                <table className="w-full">
                                    <thead className="bg-[#e8dfd1]">
                                        <tr>
                                            <th className="px-4 py-3 text-left text-xs">
                                                Username
                                            </th>
                                            <th className="px-4 py-3 text-left text-xs">
                                                Time
                                            </th>
                                            <th className="px-4 py-3 text-left text-xs">
                                                Status
                                            </th>
                                        </tr>
                                    </thead>

                                    <tbody>
                                        {loginAttempts.map(
                                            (attempt, index) => (
                                                <tr
                                                    key={index}
                                                    className="border-b last:border-0"
                                                >
                                                    <td className="px-4 py-3 text-sm">
                                                        {attempt.username}
                                                    </td>

                                                    <td className="px-4 py-3 text-xs text-gray-500">
                                                        {attempt.time}
                                                    </td>

                                                    <td className="px-4 py-3">
                                                        <span
                                                            className={`rounded-full px-3 py-1 text-xs ${
                                                                attempt.status ===
                                                                'Success'
                                                                    ? 'bg-[#e3f5e7] text-[#26733a]'
                                                                    : 'bg-[#f9dddd] text-[#a33b3b]'
                                                            }`}
                                                        >
                                                            {attempt.status}
                                                        </span>
                                                    </td>
                                                </tr>
                                            ),
                                        )}
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <div className="rounded-lg border border-gray-200 bg-white p-5">
                            <h3 className="text-sm font-semibold">
                                Suspicious Activity
                            </h3>

                            <ul className="mt-4 space-y-3 text-sm text-gray-700">
                                <li>
                                    • Multiple failed login attempts detected
                                </li>

                                <li>
                                    • Login from unknown device
                                </li>
                            </ul>
                        </div>
                    </div>
                </section>
            </AdminLayout>
        </>
    );
}