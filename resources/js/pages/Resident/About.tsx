import ResidentLayout from '@/layouts/ResidentLayout';

export default function About() {
    return (
        <ResidentLayout active="about">
            <div className="mx-auto w-full max-w-4xl">

                {/* ========================================================
                    PAGE HEADER
                ======================================================== */}

                <div className="mb-5 sm:mb-6">
                    <h1 className="text-xl font-bold text-gray-900 sm:text-2xl">
                        About KaBarangay
                    </h1>

                    <p className="mt-1 max-w-2xl text-sm leading-6 text-gray-500">
                        Learn more about KaBarangay and the services
                        available through the Resident Portal.
                    </p>
                </div>

                {/* ========================================================
                    INTRO
                ======================================================== */}

                <div className="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm sm:p-6">

                    <div className="flex flex-col gap-4 sm:flex-row sm:items-start">

                        <div className="flex h-14 w-14 shrink-0 items-center justify-center rounded-2xl bg-[#e8f1e4] text-[#3f6f3d]">
                            <BarangayIcon />
                        </div>

                        <div className="min-w-0">

                            <h2 className="text-lg font-bold text-gray-900 sm:text-xl">
                                KaBarangay Resident Portal
                            </h2>

                            <p className="mt-2 max-w-2xl text-sm leading-6 text-gray-600">
                                KaBarangay is a digital resident portal
                                designed to make barangay services easier,
                                faster, and more accessible. Residents can
                                access selected barangay services, monitor
                                requests, receive updates, and manage their
                                resident account in one place.
                            </p>

                        </div>

                    </div>

                </div>

                {/* ========================================================
                    SERVICES
                ======================================================== */}

                <div className="mt-4 rounded-2xl border border-gray-200 bg-white p-5 shadow-sm sm:p-6">

                    <div className="mb-5">
                        <h2 className="text-base font-bold text-gray-900">
                            What You Can Do
                        </h2>

                        <p className="mt-1 text-sm leading-5 text-gray-500">
                            Services currently available in the Resident Portal.
                        </p>
                    </div>

                    <div className="grid gap-3 sm:grid-cols-2">

                        <ServiceItem
                            icon={<CertificateIcon />}
                            title="Request Certificates"
                            description="Request barangay certificates and track their status."
                        />

                        <ServiceItem
                            icon={<ComplaintIcon />}
                            title="File a Complaint"
                            description="Submit a Lupon complaint and monitor case progress."
                        />

                        <ServiceItem
                            icon={<AnnouncementIcon />}
                            title="View Announcements"
                            description="Stay updated with barangay news, advisories, and events."
                        />

                        <ServiceItem
                            icon={<PesoIcon />}
                            title="Track Disbursement"
                            description="View assistance or benefits assigned to your account."
                        />

                        <ServiceItem
                            icon={<ProfileIcon />}
                            title="Access My Profile"
                            description="View resident information and your digital QR code."
                        />

                        <ServiceItem
                            icon={<ChatIcon />}
                            title="KaBarangay Assistant"
                            description="Get guided help with supported resident services."
                        />

                    </div>

                </div>

                {/* ========================================================
                    BARANGAY + PRIVACY
                ======================================================== */}

                <div className="mt-4 grid gap-4 lg:grid-cols-2">

                    {/* BARANGAY INFORMATION */}

                    <InfoCard
                        icon={<LocationIcon />}
                        title="Barangay Information"
                    >
                        <InfoRow
                            label="Barangay"
                            value="Barangay San Jose"
                        />

                        <InfoRow
                            label="City / Municipality"
                            value="Puerto Princesa City"
                        />

                        <InfoRow
                            label="Province"
                            value="Palawan"
                        />
                    </InfoCard>

                    {/* PRIVACY */}

                    <InfoCard
                        icon={<ShieldIcon />}
                        title="Privacy & Security"
                    >
                        <p className="text-sm leading-6 text-gray-600">
                            Resident information is intended only for
                            authorized barangay services, verification,
                            and official transactions. Access to personal
                            records should be limited to authorized users.
                        </p>

                        <div className="mt-3 rounded-xl border border-[#dce8d8] bg-[#f5f9f3] p-3">

                            <div className="flex items-start gap-2">

                                <div className="mt-0.5 shrink-0 text-[#3f6f3d]">
                                    <CheckIcon />
                                </div>

                                <p className="text-xs leading-5 text-gray-600">
                                    Your QR code should use a secure resident
                                    identifier instead of directly storing
                                    sensitive personal details.
                                </p>

                            </div>

                        </div>
                    </InfoCard>

                </div>

                {/* ========================================================
                    HELP
                ======================================================== */}

                <div className="mt-4 rounded-2xl border border-gray-200 bg-white p-5 shadow-sm sm:p-6">

                    <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">

                        <div className="flex items-start gap-3">

                            <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-[#e8f1e4] text-[#3f6f3d]">
                                <HelpIcon />
                            </div>

                            <div>

                                <h2 className="text-base font-bold text-gray-900">
                                    Need Help?
                                </h2>

                                <p className="mt-1 max-w-xl text-sm leading-6 text-gray-500">
                                    You may visit the barangay office for
                                    assistance or use KaBarangay Assistant
                                    while using the Resident Portal.
                                </p>

                            </div>

                        </div>

                    </div>

                </div>

                {/* ========================================================
                    SYSTEM INFO
                ======================================================== */}

                <div className="mt-4 rounded-xl border border-gray-200 bg-white px-4 py-3 shadow-sm">

                    <div className="flex flex-col gap-2 text-xs text-gray-500 sm:flex-row sm:items-center sm:justify-between">

                        <p>
                            KaBarangay Resident Portal
                        </p>

                        <p>
                            Version 1.0
                        </p>

                    </div>

                </div>

            </div>
        </ResidentLayout>
    );
}

/*
|--------------------------------------------------------------------------
| Service Item
|--------------------------------------------------------------------------
*/

function ServiceItem({
    icon,
    title,
    description,
}: {
    icon: React.ReactNode;
    title: string;
    description: string;
}) {
    return (
        <div className="rounded-xl border border-gray-200 bg-gray-50/60 p-4">

            <div className="flex items-start gap-3">

                <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-white text-[#3f6f3d] shadow-sm">
                    {icon}
                </div>

                <div className="min-w-0">

                    <p className="text-sm font-bold text-gray-900">
                        {title}
                    </p>

                    <p className="mt-1 text-xs leading-5 text-gray-500">
                        {description}
                    </p>

                </div>

            </div>

        </div>
    );
}

/*
|--------------------------------------------------------------------------
| Info Card
|--------------------------------------------------------------------------
*/

function InfoCard({
    icon,
    title,
    children,
}: {
    icon: React.ReactNode;
    title: string;
    children: React.ReactNode;
}) {
    return (
        <div className="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">

            <div className="flex items-center gap-3">

                <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-[#e8f1e4] text-[#3f6f3d]">
                    {icon}
                </div>

                <h2 className="text-base font-bold text-gray-900">
                    {title}
                </h2>

            </div>

            <div className="mt-4 space-y-3">
                {children}
            </div>

        </div>
    );
}

/*
|--------------------------------------------------------------------------
| Info Row
|--------------------------------------------------------------------------
*/

function InfoRow({
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

            <p className="mt-1 text-sm font-semibold text-gray-700">
                {value}
            </p>

        </div>
    );
}

/*
|--------------------------------------------------------------------------
| Icons
|--------------------------------------------------------------------------
*/

function BarangayIcon() {
    return (
        <svg
            viewBox="0 0 24 24"
            fill="none"
            className="h-7 w-7"
            stroke="currentColor"
            strokeWidth="1.8"
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
        >
            <path d="M3 21h18" />
            <path d="M5 21V9l7-5 7 5v12" />
            <path d="M9 21v-6h6v6" />
        </svg>
    );
}

function CertificateIcon() {
    return (
        <svg
            viewBox="0 0 24 24"
            fill="none"
            className="h-5 w-5"
            stroke="currentColor"
            strokeWidth="1.9"
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
        >
            <path d="M7 3h7l4 4v14H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2Z" />
            <path d="M14 3v5h4" />
            <path d="M9 13h6" />
            <path d="M9 17h4" />
        </svg>
    );
}

function ComplaintIcon() {
    return (
        <svg
            viewBox="0 0 24 24"
            fill="none"
            className="h-5 w-5"
            stroke="currentColor"
            strokeWidth="1.9"
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
        >
            <path d="M12 3v18" />
            <path d="M5 7h14" />
            <path d="m7 7-3 6h6L7 7Z" />
            <path d="m17 7-3 6h6l-3-6Z" />
            <path d="M8 21h8" />
        </svg>
    );
}

function AnnouncementIcon() {
    return (
        <svg
            viewBox="0 0 24 24"
            fill="none"
            className="h-5 w-5"
            stroke="currentColor"
            strokeWidth="1.9"
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
        >
            <path d="M4 13V9a2 2 0 0 1 2-2h3l8-3v14l-8-3H6a2 2 0 0 1-2-2Z" />
            <path d="M8 15l1 5h3l-1-5" />
            <path d="M20 8v6" />
        </svg>
    );
}

function PesoIcon() {
    return (
        <span className="text-lg font-semibold leading-none">
            ₱
        </span>
    );
}

function ProfileIcon() {
    return (
        <svg
            viewBox="0 0 24 24"
            fill="none"
            className="h-5 w-5"
            stroke="currentColor"
            strokeWidth="1.9"
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

function ChatIcon() {
    return (
        <svg
            viewBox="0 0 24 24"
            fill="none"
            className="h-5 w-5"
            stroke="currentColor"
            strokeWidth="1.9"
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
        >
            <path d="M21 12a8 8 0 0 1-8 8H6l-4 2 1.4-4.2A8 8 0 1 1 21 12Z" />
            <path d="M8 12h.01" />
            <path d="M12 12h.01" />
            <path d="M16 12h.01" />
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
            strokeWidth="1.9"
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

function ShieldIcon() {
    return (
        <svg
            viewBox="0 0 24 24"
            fill="none"
            className="h-5 w-5"
            stroke="currentColor"
            strokeWidth="1.9"
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
        >
            <path d="M12 3 5 6v5c0 5 3 8 7 10 4-2 7-5 7-10V6Z" />
        </svg>
    );
}

function HelpIcon() {
    return (
        <svg
            viewBox="0 0 24 24"
            fill="none"
            className="h-5 w-5"
            stroke="currentColor"
            strokeWidth="1.9"
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
        >
            <circle
                cx="12"
                cy="12"
                r="9"
            />

            <path d="M9.7 9a2.5 2.5 0 1 1 4.6 1.4c-.9 1.1-2.3 1.5-2.3 3.1" />

            <path d="M12 17h.01" />
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