import { router, useForm, usePage } from '@inertiajs/react';
import { FormEvent, useEffect, useRef, useState } from 'react';
import {
    defaultResidentSettings,
    readResidentSettings,
    RESIDENT_SETTINGS_EVENT,
    SETTINGS_STORAGE_KEY,
} from '@/lib/residentSettings';
import type {
    ResidentSettings,
    TextSize,
    ThemeMode,
} from '@/lib/residentSettings';
import ResidentLayout from '@/layouts/ResidentLayout';

export type { ResidentSettings, TextSize, ThemeMode };
export { defaultResidentSettings, SETTINGS_STORAGE_KEY };

const CHAT_STORAGE_KEY = 'kabarangay_assistant_state';

const CERTIFICATE_FLOW_KEY = 'kabarangay_certificate_flow';

const ASSISTANT_CLEARED_EVENT = 'kabarangay-assistant-cleared';

export default function Settings() {
    const { flash } = usePage<{
        flash?: { success?: string | null };
    }>().props;

    const [settings, setSettings] = useState<ResidentSettings>(
        defaultResidentSettings,
    );

    const [loaded, setLoaded] = useState(false);

    const [showSaved, setShowSaved] = useState(false);

    const [showClearChat, setShowClearChat] = useState(false);

    const [showPasswordModal, setShowPasswordModal] = useState(false);

    const initialSettingsLoad = useRef(true);

    const passwordForm = useForm({
        current_password: '',
        password: '',
        password_confirmation: '',
    });

    /*
    |--------------------------------------------------------------------------
    | Load Saved Settings
    |--------------------------------------------------------------------------
    */

    useEffect(() => {
        setSettings(readResidentSettings());
        setLoaded(true);
    }, []);

    /*
    |--------------------------------------------------------------------------
    | Save + Apply Immediately
    |--------------------------------------------------------------------------
    */

    useEffect(() => {
        if (!loaded) {
            return;
        }

        try {
            window.localStorage.setItem(
                SETTINGS_STORAGE_KEY,
                JSON.stringify(settings),
            );
        } catch (error) {
            console.error('Unable to save resident settings.', error);
        }

        window.dispatchEvent(
            new CustomEvent(RESIDENT_SETTINGS_EVENT, {
                detail: settings,
            }),
        );

        if (initialSettingsLoad.current) {
            initialSettingsLoad.current = false;

            return;
        }

        setShowSaved(true);

        const timer = window.setTimeout(() => {
            setShowSaved(false);
        }, 1000);

        return () => {
            window.clearTimeout(timer);
        };
    }, [settings, loaded]);

    /*
    |--------------------------------------------------------------------------
    | Update
    |--------------------------------------------------------------------------
    */

    const updateSetting = <K extends keyof ResidentSettings>(
        key: K,
        value: ResidentSettings[K],
    ) => {
        setSettings((current) => ({
            ...current,
            [key]: value,
        }));
    };

    /*
    |--------------------------------------------------------------------------
    | Reset
    |--------------------------------------------------------------------------
    */

    const resetSettings = () => {
        const confirmed = window.confirm(
            'Reset all accessibility and appearance settings?',
        );

        if (!confirmed) {
            return;
        }

        setSettings(defaultResidentSettings);
    };

    /*
    |--------------------------------------------------------------------------
    | Clear Chat
    |--------------------------------------------------------------------------
    */

    const clearChatHistory = () => {
        try {
            window.localStorage.removeItem(CHAT_STORAGE_KEY);

            window.localStorage.removeItem(CERTIFICATE_FLOW_KEY);
        } catch (error) {
            console.error('Unable to clear chatbot history.', error);
        }

        window.dispatchEvent(new Event(ASSISTANT_CLEARED_EVENT));

        setShowClearChat(false);
    };

    /*
    |--------------------------------------------------------------------------
    | Change Password
    |--------------------------------------------------------------------------
    */

    const openPasswordModal = () => {
        passwordForm.reset();
        passwordForm.clearErrors();
        setShowPasswordModal(true);
    };

    const closePasswordModal = () => {
        if (passwordForm.processing) {
            return;
        }

        setShowPasswordModal(false);
        passwordForm.reset();
        passwordForm.clearErrors();
    };

    const submitPassword = (event: FormEvent) => {
        event.preventDefault();

        passwordForm.post('/resident/settings/password', {
            preserveScroll: true,
            onSuccess: () => {
                setShowPasswordModal(false);
                passwordForm.reset();
            },
        });
    };

    /*
    |--------------------------------------------------------------------------
    | Logout
    |--------------------------------------------------------------------------
    */

    const handleLogout = () => {
        const confirmed = window.confirm('Are you sure you want to log out?');

        if (!confirmed) {
            return;
        }

        router.post('/resident/logout');
    };

    return (
        <ResidentLayout active="settings">
            <div className="mx-auto w-full max-w-4xl">
                {/* ========================================================
                    HEADER
                ======================================================== */}

                <div className="mb-6">
                    <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                        <div className="min-w-0">
                            <h1 className="text-xl font-semibold tracking-tight text-ink-strong sm:text-2xl">
                                Settings
                            </h1>

                            <p className="mt-1 max-w-xl text-sm leading-6 text-ink-muted">
                                Personalize KaBarangay for a more comfortable
                                and accessible experience.
                            </p>
                        </div>

                        <div className="min-h-7">
                            {showSaved && (
                                <span className="inline-flex items-center gap-1.5 rounded-full border border-brand-muted bg-brand-soft px-3 py-1 text-xs font-semibold text-brand-deep">
                                    <CheckIcon />
                                    Changes saved
                                </span>
                            )}
                        </div>
                    </div>
                </div>

                {flash?.success && (
                    <div
                        role="status"
                        className="mb-4 rounded-xl border border-brand-muted bg-brand-soft px-4 py-3 text-sm font-semibold text-brand-deep"
                    >
                        {flash.success}
                    </div>
                )}

                {/* ========================================================
                    APPEARANCE
                ======================================================== */}

                <SettingCard
                    icon={<AppearanceIcon />}
                    title="Appearance"
                    description="Changes apply immediately across the Resident Portal."
                >
                    <SettingLabel
                        title="Color theme"
                        description="Choose the appearance that is comfortable for your eyes."
                    />

                    <div className="mt-3 grid grid-cols-3 gap-2 sm:gap-3">
                        <ThemeButton
                            label="Light"
                            icon={<SunIcon />}
                            selected={settings.theme === 'light'}
                            onClick={() => updateSetting('theme', 'light')}
                        />

                        <ThemeButton
                            label="Dark"
                            icon={<MoonIcon />}
                            selected={settings.theme === 'dark'}
                            onClick={() => updateSetting('theme', 'dark')}
                        />

                        <ThemeButton
                            label="System"
                            icon={<SystemIcon />}
                            selected={settings.theme === 'system'}
                            onClick={() => updateSetting('theme', 'system')}
                        />
                    </div>
                </SettingCard>

                {/* ========================================================
                    ACCESSIBILITY
                ======================================================== */}

                <div className="mt-4">
                    <SettingCard
                        icon={<AccessibilityIcon />}
                        title="Accessibility"
                        description="Helpful options for seniors, PWD users, and residents who prefer easier controls."
                    >
                        {/* TEXT SIZE */}

                        <SettingLabel
                            title="Text size"
                            description="Adjust text throughout the Resident Portal."
                        />

                        <div className="mt-3 grid grid-cols-3 gap-2 sm:gap-3">
                            <TextSizeButton
                                label="Default"
                                sampleClass="text-sm"
                                selected={settings.textSize === 'default'}
                                onClick={() =>
                                    updateSetting('textSize', 'default')
                                }
                            />

                            <TextSizeButton
                                label="Large"
                                sampleClass="text-lg"
                                selected={settings.textSize === 'large'}
                                onClick={() =>
                                    updateSetting('textSize', 'large')
                                }
                            />

                            <TextSizeButton
                                label="Extra Large"
                                sampleClass="text-xl"
                                selected={settings.textSize === 'extra-large'}
                                onClick={() =>
                                    updateSetting('textSize', 'extra-large')
                                }
                            />
                        </div>

                        <SectionDivider />

                        <SwitchRow
                            title="Large Controls"
                            description="Makes buttons and important controls easier to tap."
                            checked={settings.largeControls}
                            onChange={(value) =>
                                updateSetting('largeControls', value)
                            }
                        />

                        <SectionDivider />

                        <SwitchRow
                            title="High Contrast"
                            description="Strengthens text, borders, and visual separation."
                            checked={settings.highContrast}
                            onChange={(value) =>
                                updateSetting('highContrast', value)
                            }
                        />

                        <SectionDivider />

                        <SwitchRow
                            title="Reduce Motion"
                            description="Minimizes animations and moving transitions."
                            checked={settings.reduceMotion}
                            onChange={(value) =>
                                updateSetting('reduceMotion', value)
                            }
                        />

                        <SectionDivider />

                        <SwitchRow
                            title="Simplified View"
                            description="Removes extra shadows and decorative effects for a cleaner interface."
                            checked={settings.simplifiedView}
                            onChange={(value) =>
                                updateSetting('simplifiedView', value)
                            }
                        />
                    </SettingCard>
                </div>

                {/* ========================================================
                    LIVE ACCESSIBILITY STATUS
                ======================================================== */}

                <div className="mt-4 rounded-xl border border-line-soft bg-surface p-4 sm:p-5">
                    <div className="flex items-start gap-3">
                        <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-brand-muted text-brand">
                            <EyeIcon />
                        </div>

                        <div className="min-w-0">
                            <p className="text-sm font-semibold text-ink-strong">
                                Accessibility Preview
                            </p>

                            <p className="mt-1 text-sm leading-6 text-ink-muted">
                                This panel changes together with your settings.
                                Try changing text size, contrast, or motion
                                options.
                            </p>

                            <div className="mt-3 flex flex-wrap gap-2">
                                <StatusChip
                                    label="Text"
                                    value={
                                        settings.textSize === 'default'
                                            ? 'Default'
                                            : settings.textSize === 'large'
                                              ? 'Large'
                                              : 'Extra Large'
                                    }
                                />

                                <StatusChip
                                    label="Controls"
                                    value={
                                        settings.largeControls
                                            ? 'Large'
                                            : 'Default'
                                    }
                                />

                                <StatusChip
                                    label="Contrast"
                                    value={
                                        settings.highContrast
                                            ? 'High'
                                            : 'Normal'
                                    }
                                />

                                <StatusChip
                                    label="Motion"
                                    value={
                                        settings.reduceMotion
                                            ? 'Reduced'
                                            : 'Normal'
                                    }
                                />
                            </div>
                        </div>
                    </div>
                </div>

                {/* ========================================================
                    NOTIFICATIONS
                ======================================================== */}

                <div className="mt-4">
                    <SettingCard
                        icon={<BellIcon />}
                        title="Notifications"
                        description="Choose which resident updates you want to receive."
                    >
                        <SwitchRow
                            title="Announcements"
                            description="Barangay advisories, events, and important public notices."
                            checked={settings.announcementNotifications}
                            onChange={(value) =>
                                updateSetting(
                                    'announcementNotifications',
                                    value,
                                )
                            }
                        />

                        <SectionDivider />

                        <SwitchRow
                            title="Certificate Updates"
                            description="Changes to your certificate request status."
                            checked={settings.certificateNotifications}
                            onChange={(value) =>
                                updateSetting('certificateNotifications', value)
                            }
                        />

                        <SectionDivider />

                        <SwitchRow
                            title="Lupon Updates"
                            description="Complaint status and hearing schedule updates."
                            checked={settings.luponNotifications}
                            onChange={(value) =>
                                updateSetting('luponNotifications', value)
                            }
                        />

                        <SectionDivider />

                        <SwitchRow
                            title="Disbursement Alerts"
                            description="Know when you are included or when assistance is ready for claim."
                            checked={settings.disbursementNotifications}
                            onChange={(value) =>
                                updateSetting(
                                    'disbursementNotifications',
                                    value,
                                )
                            }
                        />

                        <p className="mt-4 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs leading-5 text-amber-800">
                            These preferences are saved on this device. Push,
                            email, and SMS notification delivery is not
                            available in KaBarangay yet.
                        </p>
                    </SettingCard>
                </div>

                {/* ========================================================
                    PRIVACY
                ======================================================== */}

                <div className="mt-4 grid gap-4 lg:grid-cols-2">
                    <SettingCard
                        icon={<PrivacyIcon />}
                        title="Privacy"
                        description="Manage locally stored information."
                    >
                        <ActionRow
                            title="KaBarangay Assistant History"
                            description="Delete the saved chatbot conversation on this device."
                            button="Clear"
                            danger
                            onClick={() => setShowClearChat(true)}
                        />
                    </SettingCard>

                    <SettingCard
                        icon={<SecurityIcon />}
                        title="Account & Security"
                        description="Manage your resident account."
                    >
                        <ActionRow
                            title="Change Password"
                            description="Update your resident account password."
                            button="Change"
                            onClick={openPasswordModal}
                        />

                        <SectionDivider />

                        <ActionRow
                            title="Log Out"
                            description="Sign out of this resident account."
                            button="Log Out"
                            danger
                            onClick={handleLogout}
                        />
                    </SettingCard>
                </div>

                {/* ========================================================
                    RESET
                ======================================================== */}

                <div className="mt-5 flex justify-center sm:justify-end">
                    <button
                        type="button"
                        onClick={resetSettings}
                        className="min-h-10 rounded-lg px-3 text-sm font-semibold text-ink-muted transition hover:bg-secondary hover:text-ink-strong"
                    >
                        Reset accessibility settings
                    </button>
                </div>
            </div>

            {/* ============================================================
                CLEAR CHAT MODAL
            ============================================================ */}

            {showClearChat && (
                <Modal onClose={() => setShowClearChat(false)}>
                    <div className="text-center">
                        <div className="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-red-50 text-red-600">
                            <TrashIcon />
                        </div>

                        <h2 className="mt-4 text-base font-semibold tracking-tight text-ink-strong">
                            Clear chatbot history?
                        </h2>

                        <p className="mx-auto mt-2 max-w-xs text-sm leading-6 text-ink-muted">
                            Your saved KaBarangay Assistant conversation on this
                            device will be removed.
                        </p>

                        <div className="mt-5 grid grid-cols-2 gap-3">
                            <button
                                type="button"
                                onClick={() => setShowClearChat(false)}
                                className="min-h-11 rounded-xl border border-line bg-white px-4 text-sm font-semibold text-ink transition hover:bg-canvas"
                            >
                                Cancel
                            </button>

                            <button
                                type="button"
                                onClick={clearChatHistory}
                                className="min-h-11 rounded-xl bg-red-600 px-4 text-sm font-semibold text-white transition hover:bg-red-700"
                            >
                                Clear
                            </button>
                        </div>
                    </div>
                </Modal>
            )}

            {/* ============================================================
                PASSWORD MODAL
            ============================================================ */}

            {showPasswordModal && (
                <Modal onClose={closePasswordModal}>
                    <div>
                        <div className="flex items-start justify-between gap-3">
                            <div>
                                <h2 className="text-base font-semibold tracking-tight text-ink-strong">
                                    Change Password
                                </h2>

                                <p className="mt-1 text-sm leading-5 text-ink-muted">
                                    Enter your current and new password.
                                </p>
                            </div>

                            <button
                                type="button"
                                onClick={closePasswordModal}
                                disabled={passwordForm.processing}
                                className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-ink-muted transition hover:bg-secondary disabled:opacity-50"
                                aria-label="Close change password dialog"
                            >
                                <CloseIcon />
                            </button>
                        </div>

                        <form onSubmit={submitPassword}>
                            <div className="mt-5 space-y-4">
                                <PasswordField
                                    id="current-password"
                                    label="Current Password"
                                    autoComplete="current-password"
                                    value={passwordForm.data.current_password}
                                    onChange={(value) =>
                                        passwordForm.setData(
                                            'current_password',
                                            value,
                                        )
                                    }
                                    error={passwordForm.errors.current_password}
                                />

                                <PasswordField
                                    id="new-password"
                                    label="New Password"
                                    autoComplete="new-password"
                                    value={passwordForm.data.password}
                                    onChange={(value) =>
                                        passwordForm.setData('password', value)
                                    }
                                    error={passwordForm.errors.password}
                                    hint="Use at least 8 characters."
                                />

                                <PasswordField
                                    id="confirm-new-password"
                                    label="Confirm New Password"
                                    autoComplete="new-password"
                                    value={
                                        passwordForm.data.password_confirmation
                                    }
                                    onChange={(value) =>
                                        passwordForm.setData(
                                            'password_confirmation',
                                            value,
                                        )
                                    }
                                    error={
                                        passwordForm.errors
                                            .password_confirmation
                                    }
                                />
                            </div>

                            <button
                                type="submit"
                                disabled={passwordForm.processing}
                                className="mt-5 min-h-11 w-full rounded-xl bg-brand px-4 text-sm font-semibold text-white transition hover:bg-brand-deep disabled:cursor-not-allowed disabled:opacity-60"
                            >
                                {passwordForm.processing
                                    ? 'Updating…'
                                    : 'Update Password'}
                            </button>
                        </form>
                    </div>
                </Modal>
            )}
        </ResidentLayout>
    );
}

/*
|--------------------------------------------------------------------------
| Setting Card
|--------------------------------------------------------------------------
*/

function SettingCard({
    icon,
    title,
    description,
    children,
}: {
    icon: React.ReactNode;
    title: string;
    description: string;
    children: React.ReactNode;
}) {
    return (
        <section className="rounded-xl border border-line-soft bg-white p-4 shadow-card sm:p-5">
            <div className="flex items-start gap-3">
                <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-brand-soft text-brand">
                    {icon}
                </div>

                <div className="min-w-0">
                    <h2 className="text-base font-semibold tracking-tight text-ink-strong">
                        {title}
                    </h2>

                    <p className="mt-0.5 max-w-lg text-xs leading-5 text-ink-muted sm:text-sm">
                        {description}
                    </p>
                </div>
            </div>

            <div className="mt-5">{children}</div>
        </section>
    );
}

/*
|--------------------------------------------------------------------------
| Setting Label
|--------------------------------------------------------------------------
*/

function SettingLabel({
    title,
    description,
}: {
    title: string;
    description: string;
}) {
    return (
        <div>
            <p className="text-sm font-semibold text-ink-strong">{title}</p>

            <p className="mt-0.5 max-w-lg text-xs leading-5 text-ink-muted">
                {description}
            </p>
        </div>
    );
}

/*
|--------------------------------------------------------------------------
| Switch Row
|--------------------------------------------------------------------------
*/

function SwitchRow({
    title,
    description,
    checked,
    onChange,
}: {
    title: string;
    description: string;
    checked: boolean;
    onChange: (value: boolean) => void;
}) {
    return (
        <div className="flex items-center justify-between gap-4 py-0.5">
            <div className="min-w-0 pr-2">
                <div className="flex flex-wrap items-center gap-2">
                    <p className="text-sm font-semibold text-ink-strong">
                        {title}
                    </p>

                    <span
                        className={`rounded-full px-2 py-0.5 text-[10px] font-semibold ${
                            checked
                                ? 'bg-brand-soft text-brand-deep'
                                : 'bg-secondary text-ink-muted'
                        }`}
                    >
                        {checked ? 'ON' : 'OFF'}
                    </span>
                </div>

                <p className="mt-1 max-w-lg text-xs leading-5 text-ink-muted">
                    {description}
                </p>
            </div>

            <button
                type="button"
                role="switch"
                aria-checked={checked}
                onClick={() => onChange(!checked)}
                className={`kb-setting-switch relative h-8 w-[54px] shrink-0 rounded-full border transition-all duration-200 ${
                    checked
                        ? 'border-brand bg-brand shadow-card'
                        : 'border-line bg-line-soft'
                }`}
            >
                <span
                    className={`absolute top-[3px] flex h-6 w-6 items-center justify-center rounded-full bg-white shadow transition-transform duration-200 ${
                        checked ? 'translate-x-[25px]' : 'translate-x-[3px]'
                    }`}
                >
                    {checked && (
                        <span className="text-brand">
                            <CheckIcon />
                        </span>
                    )}
                </span>
            </button>
        </div>
    );
}

/*
|--------------------------------------------------------------------------
| Theme Button
|--------------------------------------------------------------------------
*/

function ThemeButton({
    label,
    icon,
    selected,
    onClick,
}: {
    label: string;
    icon: React.ReactNode;
    selected: boolean;
    onClick: () => void;
}) {
    return (
        <button
            type="button"
            onClick={onClick}
            className={`min-h-[74px] rounded-xl border px-2 py-3 transition ${
                selected
                    ? 'border-[#6e747c] bg-canvas text-brand-deep ring-1 ring-[#6e747c]'
                    : 'border-line-soft bg-white text-ink-muted hover:border-line hover:bg-canvas'
            }`}
        >
            <div className="flex flex-col items-center gap-2">
                {icon}

                <span className="text-xs font-semibold sm:text-sm">
                    {label}
                </span>
            </div>
        </button>
    );
}

/*
|--------------------------------------------------------------------------
| Text Size
|--------------------------------------------------------------------------
*/

function TextSizeButton({
    label,
    sampleClass,
    selected,
    onClick,
}: {
    label: string;
    sampleClass: string;
    selected: boolean;
    onClick: () => void;
}) {
    return (
        <button
            type="button"
            onClick={onClick}
            className={`min-h-[74px] rounded-xl border p-2 transition ${
                selected
                    ? 'border-[#6e747c] bg-canvas ring-1 ring-[#6e747c]'
                    : 'border-line-soft bg-white hover:border-line hover:bg-canvas'
            }`}
        >
            <div className="flex flex-col items-center justify-center">
                <span
                    className={`font-semibold ${
                        selected ? 'text-brand-deep' : 'text-ink'
                    } ${sampleClass}`}
                >
                    Aa
                </span>

                <span className="mt-1 text-[10px] font-semibold text-ink-muted sm:text-xs">
                    {label}
                </span>
            </div>
        </button>
    );
}

/*
|--------------------------------------------------------------------------
| Status Chip
|--------------------------------------------------------------------------
*/

function StatusChip({ label, value }: { label: string; value: string }) {
    return (
        <span className="inline-flex items-center gap-1 rounded-lg border border-line-soft bg-white px-2.5 py-1.5 text-xs">
            <span className="text-ink-faint">{label}:</span>

            <span className="font-semibold text-brand">{value}</span>
        </span>
    );
}

/*
|--------------------------------------------------------------------------
| Action Row
|--------------------------------------------------------------------------
*/

function ActionRow({
    title,
    description,
    button,
    danger = false,
    onClick,
}: {
    title: string;
    description: string;
    button: string;
    danger?: boolean;
    onClick: () => void;
}) {
    return (
        <div className="flex items-center justify-between gap-4">
            <div className="min-w-0 pr-2">
                <p className="text-sm font-semibold text-ink-strong">{title}</p>

                <p className="mt-1 text-xs leading-5 text-ink-muted">
                    {description}
                </p>
            </div>

            <button
                type="button"
                onClick={onClick}
                className={`min-h-10 shrink-0 rounded-lg border px-3 text-xs font-semibold transition sm:text-sm ${
                    danger
                        ? 'border-red-200 bg-white text-red-600 hover:bg-red-50'
                        : 'border-line bg-white text-ink hover:bg-canvas'
                }`}
            >
                {button}
            </button>
        </div>
    );
}

function SectionDivider() {
    return <div className="my-4 border-t border-line-soft" />;
}

/*
|--------------------------------------------------------------------------
| Password
|--------------------------------------------------------------------------
*/

function PasswordField({
    id,
    label,
    value,
    onChange,
    error,
    autoComplete,
    hint,
}: {
    id: string;
    label: string;
    value: string;
    onChange: (value: string) => void;
    error?: string;
    autoComplete: string;
    hint?: string;
}) {
    return (
        <div>
            <label
                htmlFor={id}
                className="mb-1.5 block text-sm font-semibold text-ink"
            >
                {label}
            </label>

            <input
                id={id}
                type="password"
                value={value}
                onChange={(event) => onChange(event.target.value)}
                autoComplete={autoComplete}
                required
                aria-invalid={Boolean(error)}
                aria-describedby={
                    error ? `${id}-error` : hint ? `${id}-hint` : undefined
                }
                className="h-11 w-full rounded-xl border border-line bg-white px-3.5 text-base text-ink-strong transition outline-none focus:border-brand focus:ring-2 focus:ring-brand/15 sm:text-sm"
            />

            {error ? (
                <p
                    id={`${id}-error`}
                    className="mt-1 text-xs font-medium text-red-600"
                >
                    {error}
                </p>
            ) : hint ? (
                <p id={`${id}-hint`} className="mt-1 text-xs text-ink-muted">
                    {hint}
                </p>
            ) : null}
        </div>
    );
}

/*
|--------------------------------------------------------------------------
| Modal
|--------------------------------------------------------------------------
*/

function Modal({
    children,
    onClose,
}: {
    children: React.ReactNode;
    onClose: () => void;
}) {
    return (
        <div className="fixed inset-0 z-[100] flex items-center justify-center bg-black/50 p-4">
            <button
                type="button"
                onClick={onClose}
                className="absolute inset-0"
                aria-label="Close modal"
            />

            <div className="relative z-10 w-full max-w-md rounded-xl border border-line-soft bg-white p-5 shadow-2xl sm:p-6">
                {children}
            </div>
        </div>
    );
}

/*
|--------------------------------------------------------------------------
| Icons
|--------------------------------------------------------------------------
*/

function AppearanceIcon() {
    return (
        <svg
            viewBox="0 0 24 24"
            fill="none"
            className="h-5 w-5"
            stroke="currentColor"
            strokeWidth="2"
            strokeLinecap="round"
            strokeLinejoin="round"
        >
            <circle cx="12" cy="12" r="9" />
            <path d="M12 3a9 9 0 0 0 0 18Z" />
        </svg>
    );
}

function AccessibilityIcon() {
    return (
        <svg
            viewBox="0 0 24 24"
            fill="none"
            className="h-5 w-5"
            stroke="currentColor"
            strokeWidth="2"
            strokeLinecap="round"
            strokeLinejoin="round"
        >
            <circle cx="12" cy="4" r="2" />
            <path d="M4 8h16" />
            <path d="M12 6v7" />
            <path d="m8 21 4-8 4 8" />
        </svg>
    );
}

function EyeIcon() {
    return (
        <svg
            viewBox="0 0 24 24"
            fill="none"
            className="h-5 w-5"
            stroke="currentColor"
            strokeWidth="2"
            strokeLinecap="round"
            strokeLinejoin="round"
        >
            <path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12Z" />
            <circle cx="12" cy="12" r="3" />
        </svg>
    );
}

function BellIcon() {
    return (
        <svg
            viewBox="0 0 24 24"
            fill="none"
            className="h-5 w-5"
            stroke="currentColor"
            strokeWidth="2"
            strokeLinecap="round"
            strokeLinejoin="round"
        >
            <path d="M18 8a6 6 0 1 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9" />
            <path d="M10 21h4" />
        </svg>
    );
}

function PrivacyIcon() {
    return (
        <svg
            viewBox="0 0 24 24"
            fill="none"
            className="h-5 w-5"
            stroke="currentColor"
            strokeWidth="2"
            strokeLinecap="round"
            strokeLinejoin="round"
        >
            <path d="M12 3 5 6v5c0 5 3 8 7 10 4-2 7-5 7-10V6Z" />
            <path d="m9 12 2 2 4-4" />
        </svg>
    );
}

function SecurityIcon() {
    return (
        <svg
            viewBox="0 0 24 24"
            fill="none"
            className="h-5 w-5"
            stroke="currentColor"
            strokeWidth="2"
            strokeLinecap="round"
            strokeLinejoin="round"
        >
            <rect x="5" y="10" width="14" height="10" rx="2" />
            <path d="M8 10V7a4 4 0 0 1 8 0v3" />
        </svg>
    );
}

function SunIcon() {
    return (
        <svg
            viewBox="0 0 24 24"
            fill="none"
            className="h-5 w-5"
            stroke="currentColor"
            strokeWidth="2"
            strokeLinecap="round"
        >
            <circle cx="12" cy="12" r="4" />
            <path d="M12 2v2" />
            <path d="M12 20v2" />
            <path d="M4 12H2" />
            <path d="M22 12h-2" />
        </svg>
    );
}

function MoonIcon() {
    return (
        <svg
            viewBox="0 0 24 24"
            fill="none"
            className="h-5 w-5"
            stroke="currentColor"
            strokeWidth="2"
            strokeLinecap="round"
            strokeLinejoin="round"
        >
            <path d="M21 12.8A8.5 8.5 0 1 1 11.2 3 6.5 6.5 0 0 0 21 12.8Z" />
        </svg>
    );
}

function SystemIcon() {
    return (
        <svg
            viewBox="0 0 24 24"
            fill="none"
            className="h-5 w-5"
            stroke="currentColor"
            strokeWidth="2"
            strokeLinecap="round"
            strokeLinejoin="round"
        >
            <rect x="3" y="4" width="18" height="13" rx="2" />
            <path d="M8 21h8" />
            <path d="M12 17v4" />
        </svg>
    );
}

function CheckIcon() {
    return (
        <svg
            viewBox="0 0 24 24"
            fill="none"
            className="h-3.5 w-3.5"
            stroke="currentColor"
            strokeWidth="3"
            strokeLinecap="round"
            strokeLinejoin="round"
        >
            <path d="m5 12 4 4L19 6" />
        </svg>
    );
}

function TrashIcon() {
    return (
        <svg
            viewBox="0 0 24 24"
            fill="none"
            className="h-5 w-5"
            stroke="currentColor"
            strokeWidth="2"
            strokeLinecap="round"
            strokeLinejoin="round"
        >
            <path d="M3 6h18" />
            <path d="M8 6V4h8v2" />
            <path d="M19 6l-1 14H6L5 6" />
        </svg>
    );
}

function CloseIcon() {
    return (
        <svg
            viewBox="0 0 24 24"
            fill="none"
            className="h-5 w-5"
            stroke="currentColor"
            strokeWidth="2"
            strokeLinecap="round"
        >
            <path d="M6 6l12 12" />
            <path d="M18 6 6 18" />
        </svg>
    );
}
