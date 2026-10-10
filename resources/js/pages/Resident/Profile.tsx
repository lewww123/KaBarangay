import {
    useEffect,
    useRef,
    useState,
    type FormEvent,
    type ReactNode,
} from 'react';
import { router, usePage } from '@inertiajs/react';
import { QRCodeSVG } from 'qrcode.react';
import ResidentLayout from '@/layouts/ResidentLayout';

type ProfileData = {
    fullName: string;
    residentId: string;
    birthdate: string;
    age: number;
    sex: string;
    civilStatus: string;
    mobileNumber: string;
    email: string;
    purok: string;
    barangay: string;
    city: string;
    province: string;
    accountStatus: string;
    registeredDate: string;
    classification: string;
    qrToken: string | null;
};

type PageProps = {
    profile: ProfileData;
};

type NotificationType =
    | 'success'
    | 'error'
    | 'warning';

type Notification = {
    type: NotificationType;
    title: string;
    message: string;
};

export default function Profile() {
    const { profile: serverProfile } =
        usePage<PageProps>().props;

    const [profile, setProfile] =
        useState<ProfileData>(serverProfile);

    const [editingContact, setEditingContact] =
        useState(false);

    const [mobileNumber, setMobileNumber] =
        useState(profile.mobileNumber);

    const [email, setEmail] =
        useState(profile.email);

    const [notification, setNotification] =
        useState<Notification | null>(null);

    const [savingContact, setSavingContact] =
        useState(false);

    const [showQrModal, setShowQrModal] =
        useState(false);

    const notificationTimer =
        useRef<number | null>(null);

    const classification =
        profile.classification;

    /*
    |--------------------------------------------------------------------------
    | QR Verification URL
    |--------------------------------------------------------------------------
    |
    | The QR code contains the public verification URL instead of
    | exposing only the raw KBQR token.
    |
    */

    const qrVerificationUrl = profile.qrToken
        ? `${window.location.origin}/verify/resident/${encodeURIComponent(profile.qrToken)}`
        : '';


    /*
    |--------------------------------------------------------------------------
    | Notification System
    |--------------------------------------------------------------------------
    */

    const showNotification = (
        type: NotificationType,
        title: string,
        message: string,
    ) => {

        if (notificationTimer.current) {
            window.clearTimeout(
                notificationTimer.current,
            );
        }

        setNotification({
            type,
            title,
            message,
        });

        notificationTimer.current =
            window.setTimeout(() => {
                setNotification(null);
            }, 4000);
    };


    const closeNotification = () => {

        if (notificationTimer.current) {
            window.clearTimeout(
                notificationTimer.current,
            );
        }

        setNotification(null);
    };


    useEffect(() => {
        return () => {

            if (notificationTimer.current) {
                window.clearTimeout(
                    notificationTimer.current,
                );
            }

        };
    }, []);


    /*
    |--------------------------------------------------------------------------
    | Save Contact Information
    |--------------------------------------------------------------------------
    */

    const handleSaveContact = (
        event: FormEvent<HTMLFormElement>,
    ) => {

        event.preventDefault();


        /*
        |--------------------------------------------------------------------------
        | Warning - No Changes
        |--------------------------------------------------------------------------
        */

        if (
            mobileNumber.trim() ===
                profile.mobileNumber.trim() &&
            email.trim() ===
                profile.email.trim()
        ) {

            showNotification(
                'warning',
                'No changes made.',
                'Your contact information is already up to date.',
            );

            return;
        }


        setSavingContact(true);


        router.post(
            '/resident/profile/contact',
            {
                mobileNumber,
                email,
            },
            {
                preserveScroll: true,

                onSuccess: () => {

                    setProfile((current) => ({
                        ...current,
                        mobileNumber,
                        email,
                    }));

                    setEditingContact(false);

                    showNotification(
                        'success',
                        'Successfully updated!',
                        'Your contact information has been successfully updated.',
                    );
                },

                onError: (errors) => {

                    const firstError =
                        Object.values(errors)[0];

                    showNotification(
                        'error',
                        'Update failed.',
                        firstError ||
                            'Unable to update your contact information. Please try again.',
                    );
                },

                onFinish: () => {
                    setSavingContact(false);
                },
            },
        );
    };


    /*
    |--------------------------------------------------------------------------
    | Cancel Contact Edit
    |--------------------------------------------------------------------------
    */

    const handleCancelEdit = () => {

        setMobileNumber(
            profile.mobileNumber,
        );

        setEmail(
            profile.email,
        );

        setEditingContact(false);
    };


    return (
        <ResidentLayout active="profile">

            <div className="mx-auto w-full max-w-6xl">

                {/* PAGE HEADER */}

                <div className="mb-5">

                    <h1 className="text-xl font-bold text-gray-900 sm:text-2xl">
                        My Profile
                    </h1>

                    <p className="mt-1 text-sm text-gray-500">
                        View your resident information and manage
                        your contact details.
                    </p>

                </div>


                {/* NOTIFICATION */}

                {notification && (
                    <NotificationAlert
                        notification={notification}
                        onClose={closeNotification}
                    />
                )}


                {/* PROFILE SUMMARY */}

                <div className="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm sm:p-5">

                    <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">

                        <div className="flex items-center gap-4">

                            <div className="flex h-16 w-16 shrink-0 items-center justify-center rounded-full bg-[#e8f1e4] text-[#3f6f3d]">
                                <ProfileLargeIcon />
                            </div>

                            <div className="min-w-0">

                                <div className="flex flex-wrap items-center gap-2">

                                    <h2 className="text-lg font-bold text-gray-900 sm:text-xl">
                                        {profile.fullName}
                                    </h2>

                                    <span className="inline-flex items-center gap-1 rounded-full bg-[#e8f1e4] px-2.5 py-1 text-[11px] font-semibold text-[#3f6f3d]">
                                        <SmallCheckIcon />
                                        Verified
                                    </span>

                                </div>

                                <p className="mt-1 text-sm font-medium text-gray-500">
                                    {profile.residentId}
                                </p>

                                <p className="mt-1 text-sm text-gray-500">
                                    {profile.purok}, {profile.barangay}
                                </p>

                                <p className="mt-1 text-sm font-semibold text-[#3f6f3d]">
                                    {classification}
                                </p>

                            </div>

                        </div>


                        {/* QR BUTTON */}

                        <button
                            type="button"
                            onClick={() =>
                                setShowQrModal(true)
                            }
                            className="
                                inline-flex
                                min-h-11
                                items-center
                                justify-center
                                gap-2
                                rounded-xl
                                bg-[#3f6f3d]
                                px-4
                                py-2.5
                                text-sm
                                font-semibold
                                text-white
                                transition
                                hover:bg-[#315b30]
                            "
                        >
                            <QrIcon />
                            Show My QR
                        </button>

                    </div>

                </div>


                {/* INFO GRID */}

                <div className="mt-4 grid gap-4 lg:grid-cols-2">

                    {/* PERSONAL INFORMATION */}

                    <CompactCard
                        title="Personal Information"
                        icon={<UserIcon />}
                    >

                        <CompactRow
                            label="Full Name"
                            value={profile.fullName}
                        />

                        <CompactRow
                            label="Birthdate"
                            value={profile.birthdate}
                        />

                        <CompactRow
                            label="Age"
                            value={
                                profile.age > 0
                                    ? `${profile.age} years old`
                                    : 'Not provided'
                            }
                        />

                        <CompactRow
                            label="Sex"
                            value={profile.sex}
                        />

                        <CompactRow
                            label="Civil Status"
                            value={profile.civilStatus}
                        />

                        <CompactRow
                            label="Classification"
                            value={classification}
                        />

                    </CompactCard>


                    {/* ADDRESS */}

                    <CompactCard
                        title="Address"
                        icon={<LocationIcon />}
                    >

                        <CompactRow
                            label="Purok"
                            value={profile.purok}
                        />

                        <CompactRow
                            label="Barangay"
                            value={profile.barangay}
                        />

                        <CompactRow
                            label="City / Municipality"
                            value={profile.city}
                        />

                        <CompactRow
                            label="Province"
                            value={profile.province}
                        />

                    </CompactCard>

                </div>


                {/* CONTACT + ACCOUNT */}

                <div className="mt-4 grid gap-4 lg:grid-cols-2">

                    {/* CONTACT INFORMATION */}

                    <div className="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm sm:p-5">

                        <div className="flex items-start justify-between gap-3">

                            <div className="flex items-center gap-3">

                                <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-[#e8f1e4] text-[#3f6f3d]">
                                    <ContactIcon />
                                </div>

                                <div>

                                    <h2 className="text-sm font-bold text-gray-900 sm:text-base">
                                        Contact Information
                                    </h2>

                                    <p className="mt-0.5 text-xs text-gray-500">
                                        Editable contact details
                                    </p>

                                </div>

                            </div>


                            {!editingContact && (
                                <button
                                    type="button"
                                    onClick={() =>
                                        setEditingContact(true)
                                    }
                                    className="text-sm font-semibold text-[#3f6f3d] hover:text-[#315b30]"
                                >
                                    Edit
                                </button>
                            )}

                        </div>


                        {!editingContact ? (

                            <div className="mt-4 space-y-3">

                                <CompactRow
                                    label="Mobile Number"
                                    value={
                                        profile.mobileNumber ||
                                        'Not provided'
                                    }
                                />

                                <CompactRow
                                    label="Email Address"
                                    value={
                                        profile.email ||
                                        'Not provided'
                                    }
                                />

                            </div>

                        ) : (

                            <form
                                onSubmit={handleSaveContact}
                                className="mt-4"
                            >

                                <div className="space-y-3">

                                    <Field label="Mobile Number">

                                        <input
                                            type="tel"
                                            value={mobileNumber}
                                            onChange={(event) =>
                                                setMobileNumber(
                                                    event.target.value,
                                                )
                                            }
                                            className={inputClass}
                                            disabled={
                                                savingContact
                                            }
                                        />

                                    </Field>


                                    <Field label="Email Address">

                                        <input
                                            type="email"
                                            value={email}
                                            onChange={(event) =>
                                                setEmail(
                                                    event.target.value,
                                                )
                                            }
                                            className={inputClass}
                                            disabled={
                                                savingContact
                                            }
                                        />

                                    </Field>

                                </div>


                                <div className="mt-4 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">

                                    <button
                                        type="button"
                                        onClick={
                                            handleCancelEdit
                                        }
                                        disabled={
                                            savingContact
                                        }
                                        className="min-h-10 rounded-xl border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 transition hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-60"
                                    >
                                        Cancel
                                    </button>


                                    <button
                                        type="submit"
                                        disabled={
                                            savingContact
                                        }
                                        className="min-h-10 rounded-xl bg-[#3f6f3d] px-4 py-2 text-sm font-semibold text-white transition hover:bg-[#315b30] disabled:cursor-not-allowed disabled:opacity-60"
                                    >
                                        {savingContact
                                            ? 'Saving...'
                                            : 'Save'}
                                    </button>

                                </div>

                            </form>

                        )}

                    </div>


                    {/* ACCOUNT INFORMATION */}

                    <CompactCard
                        title="Account Information"
                        icon={<AccountIcon />}
                    >

                        <CompactRow
                            label="Resident ID"
                            value={profile.residentId}
                        />

                        <div>

                            <p className="text-xs text-gray-400">
                                Account Status
                            </p>

                            <div className="mt-1">

                                <span className="inline-flex items-center gap-1 rounded-full bg-green-50 px-2.5 py-1 text-xs font-semibold text-green-700">
                                    <SmallCheckIcon />
                                    {profile.accountStatus}
                                </span>

                            </div>

                        </div>


                        <CompactRow
                            label="Date Registered"
                            value={profile.registeredDate}
                        />

                    </CompactCard>

                </div>


                {/* OFFICIAL UPDATE NOTICE */}

                <div className="mt-4 rounded-xl border border-amber-200 bg-amber-50 p-4">

                    <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">

                        <div className="flex items-start gap-3">

                            <div className="mt-0.5 shrink-0 text-amber-700">
                                <InfoIcon />
                            </div>

                            <div>

                                <p className="text-sm font-semibold text-amber-900">
                                    Need to change official information?
                                </p>

                                <p className="mt-1 text-xs leading-5 text-amber-800">
                                    Name, birthdate, civil status,
                                    sex, or address changes require
                                    barangay verification.
                                </p>

                            </div>

                        </div>


                        <button
                            type="button"
                            className="min-h-10 shrink-0 rounded-xl border border-amber-300 bg-white px-4 py-2 text-sm font-semibold text-amber-800 transition hover:bg-amber-100"
                        >
                            Request Update
                        </button>

                    </div>

                </div>

            </div>


            {/* QR MODAL */}

            {showQrModal && (

                <div className="fixed inset-0 z-[100] flex items-center justify-center bg-black/50 p-4">

                    <button
                        type="button"
                        className="absolute inset-0"
                        aria-label="Close QR modal"
                        onClick={() =>
                            setShowQrModal(false)
                        }
                    />


                    <div className="relative z-10 w-full max-w-sm rounded-2xl bg-white p-5 shadow-2xl">

                        <div className="flex items-start justify-between gap-3">

                            <div>

                                <h2 className="text-lg font-bold text-gray-900">
                                    My QR Code
                                </h2>

                                <p className="mt-1 text-xs leading-5 text-gray-500">
                                    Present this code to authorized
                                    barangay staff.
                                </p>

                            </div>


                            <button
                                type="button"
                                onClick={() =>
                                    setShowQrModal(false)
                                }
                                className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-gray-500 transition hover:bg-gray-100"
                                aria-label="Close QR"
                            >
                                <CloseIcon />
                            </button>

                        </div>


                        {/* REAL QR CODE */}

                        <div className="mt-5 flex justify-center">

                            <div className="rounded-xl border border-gray-200 bg-white p-3 shadow-sm">

                                {profile.qrToken ? (

                                    <QRCodeSVG
                                        value={
                                            qrVerificationUrl
                                        }
                                        size={230}
                                        level="H"
                                        includeMargin={true}
                                        bgColor="#ffffff"
                                        fgColor="#000000"
                                        aria-label="Resident QR Code"
                                    />

                                ) : (

                                    <div
                                        className="flex h-[230px] w-[230px] items-center justify-center rounded-lg bg-gray-50 p-6 text-center"
                                    >
                                        <div>

                                            <p className="text-sm font-semibold text-gray-700">
                                                QR Code unavailable
                                            </p>

                                            <p className="mt-1 text-xs leading-5 text-gray-500">
                                                Please contact the barangay
                                                office.
                                            </p>

                                        </div>
                                    </div>

                                )}

                            </div>

                        </div>


                        <div className="mt-4 text-center">

                            <p className="font-bold text-gray-900">
                                {profile.fullName}
                            </p>

                            <p className="mt-1 text-sm text-gray-500">
                                {profile.residentId}
                            </p>

                            <p className="mt-2 text-sm font-semibold text-[#3f6f3d]">
                                {classification}
                            </p>

                            <span className="mt-3 inline-flex items-center gap-1 rounded-full bg-[#e8f1e4] px-2.5 py-1 text-xs font-semibold text-[#3f6f3d]">
                                <SmallCheckIcon />
                                Verified Resident
                            </span>

                        </div>

                    </div>

                </div>

            )}

        </ResidentLayout>
    );
}


/*
|--------------------------------------------------------------------------
| Notification Alert
|--------------------------------------------------------------------------
*/

function NotificationAlert({
    notification,
    onClose,
}: {
    notification: Notification;
    onClose: () => void;
}) {

    const styles = {
        success: {
            container:
                'border-green-200 bg-green-50',
            icon:
                'bg-green-100 text-green-700',
            title:
                'text-green-900',
            message:
                'text-green-800',
        },

        error: {
            container:
                'border-red-200 bg-red-50',
            icon:
                'bg-red-100 text-red-700',
            title:
                'text-red-900',
            message:
                'text-red-800',
        },

        warning: {
            container:
                'border-amber-200 bg-amber-50',
            icon:
                'bg-amber-100 text-amber-700',
            title:
                'text-amber-900',
            message:
                'text-amber-800',
        },
    };

    const style =
        styles[notification.type];


    return (
        <div
            role="alert"
            className={`
                mb-4
                rounded-xl
                border
                px-4
                py-3
                ${style.container}
            `}
        >

            <div className="flex items-start gap-3">

                {/* ICON */}

                <div
                    className={`
                        flex
                        h-8
                        w-8
                        shrink-0
                        items-center
                        justify-center
                        rounded-full
                        ${style.icon}
                    `}
                >

                    {notification.type ===
                        'success' && (
                        <CheckIcon />
                    )}

                    {notification.type ===
                        'error' && (
                        <ErrorIcon />
                    )}

                    {notification.type ===
                        'warning' && (
                        <WarningIcon />
                    )}

                </div>


                {/* MESSAGE */}

                <div className="min-w-0 flex-1">

                    <p
                        className={`
                            text-sm
                            font-bold
                            ${style.title}
                        `}
                    >
                        {notification.title}
                    </p>

                    <p
                        className={`
                            mt-0.5
                            text-xs
                            leading-5
                            ${style.message}
                        `}
                    >
                        {notification.message}
                    </p>

                </div>


                {/* CLOSE */}

                <button
                    type="button"
                    onClick={onClose}
                    className={`
                        flex
                        h-7
                        w-7
                        shrink-0
                        items-center
                        justify-center
                        rounded-full
                        transition
                        hover:bg-black/5
                        ${style.message}
                    `}
                    aria-label="Close notification"
                >
                    <CloseIcon />
                </button>

            </div>

        </div>
    );
}


/*
|--------------------------------------------------------------------------
| Compact Card
|--------------------------------------------------------------------------
*/

function CompactCard({
    title,
    icon,
    children,
}: {
    title: string;
    icon: ReactNode;
    children: ReactNode;
}) {
    return (
        <div className="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm sm:p-5">

            <div className="flex items-center gap-3">

                <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-[#e8f1e4] text-[#3f6f3d]">
                    {icon}
                </div>

                <h2 className="text-sm font-bold text-gray-900 sm:text-base">
                    {title}
                </h2>

            </div>


            <div className="mt-4 grid gap-3 sm:grid-cols-2">
                {children}
            </div>

        </div>
    );
}


/*
|--------------------------------------------------------------------------
| Compact Row
|--------------------------------------------------------------------------
*/

function CompactRow({
    label,
    value,
}: {
    label: string;
    value: string;
}) {
    return (
        <div>

            <p className="text-xs text-gray-400">
                {label}
            </p>

            <p className="mt-1 break-words text-sm font-semibold leading-5 text-gray-700">
                {value}
            </p>

        </div>
    );
}


/*
|--------------------------------------------------------------------------
| Field
|--------------------------------------------------------------------------
*/

function Field({
    label,
    children,
}: {
    label: string;
    children: ReactNode;
}) {
    return (
        <div>

            <label className="mb-1.5 block text-sm font-semibold text-gray-700">
                {label}
            </label>

            {children}

        </div>
    );
}


/*
|--------------------------------------------------------------------------
| Input Style
|--------------------------------------------------------------------------
*/

const inputClass = `
    h-10
    w-full
    rounded-xl
    border
    border-gray-300
    bg-white
    px-3
    text-base
    text-gray-800
    outline-none
    transition
    focus:border-[#3f6f3d]
    focus:ring-2
    focus:ring-[#3f6f3d]/15
    disabled:bg-gray-50
    disabled:text-gray-400
    sm:text-sm
`;


/*
|--------------------------------------------------------------------------
| Icons
|--------------------------------------------------------------------------
*/

function ProfileLargeIcon() {
    return (
        <svg
            viewBox="0 0 24 24"
            fill="none"
            className="h-8 w-8"
            stroke="currentColor"
            strokeWidth="1.8"
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
        >
            <circle
                cx="12"
                cy="8"
                r="4"
            />

            <path d="M4 21c.8-4.2 3.5-6 8-6s7.2 1.8 8 6" />
        </svg>
    );
}


function UserIcon() {
    return (
        <svg
            viewBox="0 0 24 24"
            fill="none"
            className="h-5 w-5"
            stroke="currentColor"
            strokeWidth="2"
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
        >
            <circle
                cx="12"
                cy="8"
                r="4"
            />

            <path d="M4 21c.8-4.2 3.5-6 8-6s7.2 1.8 8 6" />
        </svg>
    );
}


function LocationIcon() {
    return (
        <svg
            viewBox="0 0 24 24"
            fill="none"
            className="h-5 w-5"
            stroke="currentColor"
            strokeWidth="2"
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
        >
            <path d="M20 10c0 5-8 11-8 11S4 15 4 10a8 8 0 1 1 16 0Z" />

            <circle
                cx="12"
                cy="10"
                r="2.5"
            />
        </svg>
    );
}


function ContactIcon() {
    return (
        <svg
            viewBox="0 0 24 24"
            fill="none"
            className="h-5 w-5"
            stroke="currentColor"
            strokeWidth="2"
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
        >
            <rect
                x="3"
                y="5"
                width="18"
                height="14"
                rx="2"
            />

            <path d="m3 7 9 6 9-6" />
        </svg>
    );
}


function AccountIcon() {
    return (
        <svg
            viewBox="0 0 24 24"
            fill="none"
            className="h-5 w-5"
            stroke="currentColor"
            strokeWidth="2"
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
        >
            <rect
                x="3"
                y="5"
                width="18"
                height="14"
                rx="2"
            />

            <circle
                cx="8"
                cy="11"
                r="2"
            />

            <path d="M6 16c.4-1.5 1.2-2 2-2s1.6.5 2 2" />

            <path d="M13 10h5" />

            <path d="M13 14h4" />
        </svg>
    );
}


function QrIcon() {
    return (
        <svg
            viewBox="0 0 24 24"
            fill="none"
            className="h-5 w-5"
            stroke="currentColor"
            strokeWidth="2"
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
        >
            <rect
                x="3"
                y="3"
                width="6"
                height="6"
            />

            <rect
                x="15"
                y="3"
                width="6"
                height="6"
            />

            <rect
                x="3"
                y="15"
                width="6"
                height="6"
            />

            <path d="M15 15h2v2h-2z" />

            <path d="M19 15h2v6h-2" />

            <path d="M15 19h2v2h-2z" />
        </svg>
    );
}


function InfoIcon() {
    return (
        <svg
            viewBox="0 0 24 24"
            fill="none"
            className="h-5 w-5"
            stroke="currentColor"
            strokeWidth="2"
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
        >
            <circle
                cx="12"
                cy="12"
                r="9"
            />

            <path d="M12 11v6" />

            <path d="M12 7h.01" />
        </svg>
    );
}


function CheckIcon() {
    return (
        <svg
            viewBox="0 0 24 24"
            fill="none"
            className="h-4 w-4"
            stroke="currentColor"
            strokeWidth="3"
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
        >
            <path d="m5 12 4 4L19 6" />
        </svg>
    );
}


function ErrorIcon() {
    return (
        <svg
            viewBox="0 0 24 24"
            fill="none"
            className="h-4 w-4"
            stroke="currentColor"
            strokeWidth="2.5"
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
        >
            <circle
                cx="12"
                cy="12"
                r="9"
            />

            <path d="M9 9l6 6" />

            <path d="M15 9l-6 6" />
        </svg>
    );
}


function WarningIcon() {
    return (
        <svg
            viewBox="0 0 24 24"
            fill="none"
            className="h-4 w-4"
            stroke="currentColor"
            strokeWidth="2.5"
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
        >
            <path d="M12 3 21 20H3L12 3Z" />

            <path d="M12 9v5" />

            <path d="M12 17h.01" />
        </svg>
    );
}


function SmallCheckIcon() {
    return (
        <svg
            viewBox="0 0 24 24"
            fill="none"
            className="h-3 w-3"
            stroke="currentColor"
            strokeWidth="3"
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
        >
            <path d="m5 12 4 4L19 6" />
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
            aria-hidden="true"
        >
            <path d="M6 6l12 12" />

            <path d="M18 6 6 18" />
        </svg>
    );
}