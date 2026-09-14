import { Head } from '@inertiajs/react';
import { FormEvent, useState } from 'react';
import AdminLayout from '@/layouts/AdminLayout';

export default function Settings() {
    const [barangayName, setBarangayName] = useState('Barangay San Jose');
    const [barangayCode, setBarangayCode] = useState('SJ');
    const [municipality, setMunicipality] = useState(
        'Puerto Princesa City',
    );

    const [adminName, setAdminName] = useState(
        'San Jose Administrator',
    );

    const [adminUsername, setAdminUsername] = useState(
        'admin.sanjose@kabarangay.local',
    );

    const [currentPassword, setCurrentPassword] = useState('');
    const [newPassword, setNewPassword] = useState('');
    const [confirmPassword, setConfirmPassword] = useState('');

    const [loginAlerts, setLoginAlerts] = useState(true);
    const [auditLogging, setAuditLogging] = useState(true);
    const [autoLogout, setAutoLogout] = useState(true);

    const [profileMessage, setProfileMessage] = useState('');
    const [passwordMessage, setPasswordMessage] = useState('');

    const handleBarangaySave = (event: FormEvent) => {
        event.preventDefault();

        setProfileMessage('Barangay settings saved.');

        window.setTimeout(() => {
            setProfileMessage('');
        }, 3000);
    };

    const handleAccountSave = (event: FormEvent) => {
        event.preventDefault();

        setProfileMessage('Administrator information saved.');

        window.setTimeout(() => {
            setProfileMessage('');
        }, 3000);
    };

    const handlePasswordChange = (event: FormEvent) => {
        event.preventDefault();

        if (!currentPassword || !newPassword || !confirmPassword) {
            setPasswordMessage('Please complete all password fields.');
            return;
        }

        if (newPassword !== confirmPassword) {
            setPasswordMessage('New passwords do not match.');
            return;
        }

        if (newPassword.length < 8) {
            setPasswordMessage(
                'New password must contain at least 8 characters.',
            );
            return;
        }

        setPasswordMessage('Password updated successfully.');

        setCurrentPassword('');
        setNewPassword('');
        setConfirmPassword('');

        window.setTimeout(() => {
            setPasswordMessage('');
        }, 3000);
    };

    return (
        <>
            <Head title="Settings" />

            <AdminLayout active="settings">
                {/* PAGE HEADER */}
                <div className="mb-6">
                    <h1 className="text-2xl font-semibold text-gray-800">
                        Settings
                    </h1>

                    <p className="mt-1 text-sm text-gray-500">
                        Manage barangay information, administrator account,
                        and system security
                    </p>
                </div>

                {profileMessage && (
                    <div className="mb-5 rounded-lg border border-[#b7d8b2] bg-[#edf7ea] px-4 py-3 text-sm font-medium text-[#315b30]">
                        {profileMessage}
                    </div>
                )}

                {/* BARANGAY INFORMATION */}
                <section className="mb-6">
                    <div className="mb-4">
                        <h2 className="text-base font-semibold text-gray-800">
                            Barangay Information
                        </h2>

                        <p className="mt-1 text-xs text-gray-500">
                            Basic information displayed throughout the
                            system
                        </p>
                    </div>

                    <form
                        onSubmit={handleBarangaySave}
                        className="rounded-lg border border-gray-200 bg-white"
                    >
                        <div className="grid grid-cols-1 gap-5 p-5 md:grid-cols-2">
                            <FormField label="Barangay Name">
                                <input
                                    type="text"
                                    value={barangayName}
                                    onChange={(event) =>
                                        setBarangayName(event.target.value)
                                    }
                                    className="form-control"
                                    required
                                />
                            </FormField>

                            <FormField label="Barangay Code">
                                <input
                                    type="text"
                                    value={barangayCode}
                                    onChange={(event) =>
                                        setBarangayCode(
                                            event.target.value.toUpperCase(),
                                        )
                                    }
                                    maxLength={10}
                                    className="form-control"
                                    required
                                />
                            </FormField>

                            <div className="md:col-span-2">
                                <FormField label="City / Municipality">
                                    <input
                                        type="text"
                                        value={municipality}
                                        onChange={(event) =>
                                            setMunicipality(
                                                event.target.value,
                                            )
                                        }
                                        className="form-control"
                                        required
                                    />
                                </FormField>
                            </div>
                        </div>

                        <div className="flex justify-end border-t border-gray-100 px-5 py-4">
                            <button
                                type="submit"
                                className="rounded-md bg-[#3f6f3d] px-4 py-2.5 text-sm font-medium text-white transition hover:bg-[#315b30]"
                            >
                                Save Barangay Settings
                            </button>
                        </div>
                    </form>
                </section>

                {/* ADMIN ACCOUNT */}
                <section className="mb-6">
                    <div className="mb-4">
                        <h2 className="text-base font-semibold text-gray-800">
                            Administrator Account
                        </h2>

                        <p className="mt-1 text-xs text-gray-500">
                            Account information for the current
                            administrator
                        </p>
                    </div>

                    <form
                        onSubmit={handleAccountSave}
                        className="rounded-lg border border-gray-200 bg-white"
                    >
                        <div className="grid grid-cols-1 gap-5 p-5 md:grid-cols-2">
                            <FormField label="Administrator Name">
                                <input
                                    type="text"
                                    value={adminName}
                                    onChange={(event) =>
                                        setAdminName(event.target.value)
                                    }
                                    className="form-control"
                                    required
                                />
                            </FormField>

                            <FormField label="Username">
                                <input
                                    type="text"
                                    value={adminUsername}
                                    onChange={(event) =>
                                        setAdminUsername(
                                            event.target.value,
                                        )
                                    }
                                    className="form-control"
                                    required
                                />
                            </FormField>

                            <div>
                                <p className="mb-1.5 text-xs font-medium text-gray-700">
                                    Role
                                </p>

                                <div className="flex min-h-[42px] items-center rounded-md border border-gray-200 bg-[#fafaf7] px-3">
                                    <span className="rounded-full bg-[#edf4ea] px-3 py-1 text-xs font-medium text-[#3f6f3d]">
                                        Admin / Superuser
                                    </span>
                                </div>
                            </div>

                            <div>
                                <p className="mb-1.5 text-xs font-medium text-gray-700">
                                    Account Status
                                </p>

                                <div className="flex min-h-[42px] items-center rounded-md border border-gray-200 bg-[#fafaf7] px-3">
                                    <span className="rounded-full bg-[#edf4ea] px-3 py-1 text-xs font-medium text-[#3f6f3d]">
                                        Active
                                    </span>
                                </div>
                            </div>
                        </div>

                        <div className="flex justify-end border-t border-gray-100 px-5 py-4">
                            <button
                                type="submit"
                                className="rounded-md bg-[#3f6f3d] px-4 py-2.5 text-sm font-medium text-white transition hover:bg-[#315b30]"
                            >
                                Save Account Information
                            </button>
                        </div>
                    </form>
                </section>

                {/* PASSWORD */}
                <section className="mb-6">
                    <div className="mb-4">
                        <h2 className="text-base font-semibold text-gray-800">
                            Change Password
                        </h2>

                        <p className="mt-1 text-xs text-gray-500">
                            Update the password used for this
                            administrator account
                        </p>
                    </div>

                    <form
                        onSubmit={handlePasswordChange}
                        className="rounded-lg border border-gray-200 bg-white"
                    >
                        <div className="space-y-5 p-5">
                            {passwordMessage && (
                                <div
                                    className={`rounded-md px-4 py-3 text-xs font-medium ${
                                        passwordMessage.includes(
                                            'successfully',
                                        )
                                            ? 'bg-[#edf4ea] text-[#315b30]'
                                            : 'bg-[#fff1f0] text-[#a33b3b]'
                                    }`}
                                >
                                    {passwordMessage}
                                </div>
                            )}

                            <FormField label="Current Password">
                                <input
                                    type="password"
                                    value={currentPassword}
                                    onChange={(event) =>
                                        setCurrentPassword(
                                            event.target.value,
                                        )
                                    }
                                    placeholder="Enter current password"
                                    className="form-control"
                                />
                            </FormField>

                            <div className="grid grid-cols-1 gap-5 md:grid-cols-2">
                                <FormField label="New Password">
                                    <input
                                        type="password"
                                        value={newPassword}
                                        onChange={(event) =>
                                            setNewPassword(
                                                event.target.value,
                                            )
                                        }
                                        placeholder="Enter new password"
                                        className="form-control"
                                    />
                                </FormField>

                                <FormField label="Confirm New Password">
                                    <input
                                        type="password"
                                        value={confirmPassword}
                                        onChange={(event) =>
                                            setConfirmPassword(
                                                event.target.value,
                                            )
                                        }
                                        placeholder="Confirm new password"
                                        className="form-control"
                                    />
                                </FormField>
                            </div>

                            <p className="text-[11px] leading-5 text-gray-400">
                                Use at least 8 characters. For production,
                                the backend should enforce stronger password
                                validation and securely hash all passwords.
                            </p>
                        </div>

                        <div className="flex justify-end border-t border-gray-100 px-5 py-4">
                            <button
                                type="submit"
                                className="rounded-md bg-[#3f6f3d] px-4 py-2.5 text-sm font-medium text-white transition hover:bg-[#315b30]"
                            >
                                Change Password
                            </button>
                        </div>
                    </form>
                </section>

                {/* SECURITY */}
                <section>
                    <div className="mb-4">
                        <h2 className="text-base font-semibold text-gray-800">
                            Security & Activity
                        </h2>

                        <p className="mt-1 text-xs text-gray-500">
                            Configure administrative security monitoring
                        </p>
                    </div>

                    <div className="overflow-hidden rounded-lg border border-gray-200 bg-white">
                        <SettingToggle
                            title="Login Security Alerts"
                            description="Show security alerts for failed and suspicious login attempts."
                            enabled={loginAlerts}
                            onChange={() =>
                                setLoginAlerts((current) => !current)
                            }
                        />

                        <SettingToggle
                            title="Audit Logging"
                            description="Record administrator and staff actions in the Audit Log."
                            enabled={auditLogging}
                            onChange={() =>
                                setAuditLogging((current) => !current)
                            }
                        />

                        <SettingToggle
                            title="Automatic Session Logout"
                            description="Automatically end inactive administrator sessions for security."
                            enabled={autoLogout}
                            onChange={() =>
                                setAutoLogout((current) => !current)
                            }
                            last
                        />
                    </div>
                </section>
            </AdminLayout>
        </>
    );
}

/* -------------------------------------------------------------------------- */
/* Form Field                                                                 */
/* -------------------------------------------------------------------------- */

function FormField({
    label,
    children,
}: {
    label: string;
    children: React.ReactNode;
}) {
    return (
        <div>
            <label className="mb-1.5 block text-xs font-medium text-gray-700">
                {label}
            </label>

            {children}
        </div>
    );
}

/* -------------------------------------------------------------------------- */
/* Setting Toggle                                                             */
/* -------------------------------------------------------------------------- */

function SettingToggle({
    title,
    description,
    enabled,
    onChange,
    last = false,
}: {
    title: string;
    description: string;
    enabled: boolean;
    onChange: () => void;
    last?: boolean;
}) {
    return (
        <div
            className={`flex flex-col gap-4 px-5 py-4 sm:flex-row sm:items-center sm:justify-between ${
                !last ? 'border-b border-gray-100' : ''
            }`}
        >
            <div>
                <p className="text-sm font-medium text-gray-800">
                    {title}
                </p>

                <p className="mt-1 max-w-2xl text-xs leading-5 text-gray-500">
                    {description}
                </p>
            </div>

            <button
                type="button"
                onClick={onChange}
                className={`relative h-6 w-11 shrink-0 rounded-full transition ${
                    enabled ? 'bg-[#3f6f3d]' : 'bg-gray-300'
                }`}
                aria-pressed={enabled}
            >
                <span
                    className={`absolute top-1 h-4 w-4 rounded-full bg-white transition-all ${
                        enabled ? 'left-6' : 'left-1'
                    }`}
                />
            </button>
        </div>
    );
}