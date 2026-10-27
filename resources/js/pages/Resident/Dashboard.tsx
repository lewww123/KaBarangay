import { Head, Link } from '@inertiajs/react';
import { ReactNode, useState } from 'react';
import ResidentLayout from '@/layouts/ResidentLayout';

type ModalType = 'tanod' | 'emergency' | null;

type ResidentDashboardResident = {
    id: number;
    first_name: string;
    middle_name: string | null;
    last_name: string;
    suffix: string | null;
    full_name: string;
    resident_number: string;
    barangay: {
        id: number | null;
        name: string | null;
        code: string | null;
        city_municipality: string | null;
        province: string | null;
    };
    sectors: {
        id: number;
        name: string;
        slug: string;
    }[];
};

type LatestAnnouncement = {
    id: number;
    title: string;
    created_at: string;
} | null;

export type ResidentContact = {
    id: number;
    category: 'barangay_hotline' | 'emergency_hotline';
    sub_category: string | null;
    name: string;
    label: string | null;
    phone_number: string;
    can_call: boolean;
    can_sms: boolean;
    tel_uri: string;
    sms_uri: string;
};

type DashboardProps = {
    resident: ResidentDashboardResident;
    latestAnnouncement?: LatestAnnouncement;
    sharedEmergencyContacts?: ResidentContact[];
    barangayHotlines?: ResidentContact[];
};

export default function Dashboard({
    resident,
    latestAnnouncement,
    sharedEmergencyContacts = [],
    barangayHotlines = [],
}: DashboardProps) {
    const [activeModal, setActiveModal] = useState<ModalType>(null);

    return (
        <>
            <Head title="Resident Dashboard" />

            <ResidentLayout active="home">
                {/* WELCOME */}
                <section className="mb-5 sm:mb-6">
                    <h1 className="text-2xl font-semibold tracking-tight text-gray-900 sm:text-3xl">
                        Good day, {resident.first_name}!
                    </h1>

                    <p className="mt-2 text-sm leading-6 text-gray-500 sm:text-base">
                        How can we help you today?
                    </p>
                </section>

                {/* QUICK SERVICES */}
                <section
                    aria-labelledby="quick-services-heading"
                    className="mb-7"
                >
                    <h2 id="quick-services-heading" className="sr-only">
                        Quick Services
                    </h2>

                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 sm:gap-4">
                        <QuickServiceCard
                            href="/resident/certificates"
                            icon={<CertificateIcon />}
                            title="Request Certificate"
                            description="Request barangay documents and track your request."
                        />

                        <QuickServiceCard
                            href="/resident/lupon"
                            icon={<ComplaintIcon />}
                            title="File a Complaint"
                            description="Submit a concern or view your Lupon case."
                        />
                    </div>
                </section>

                {/* LATEST ANNOUNCEMENT (REAL DATA FROM BACKEND) */}
                <section
                    aria-labelledby="latest-announcement-heading"
                    className="mb-7"
                >
                    <div className="mb-3 flex items-center justify-between gap-4">
                        <h2
                            id="latest-announcement-heading"
                            className="text-base font-semibold text-gray-900 sm:text-lg"
                        >
                            Latest Announcement
                        </h2>

                        <Link
                            href="/resident/announcements"
                            className="shrink-0 rounded-md px-2 py-1 text-sm font-semibold text-[#3f6f3d] transition hover:bg-[#edf4ea] focus:ring-2 focus:ring-[#3f6f3d]/30 focus:outline-none"
                        >
                            View All
                        </Link>
                    </div>

                    {latestAnnouncement ? (
                        <Link
                            href="/resident/announcements"
                            className="flex min-h-[72px] items-center gap-3 rounded-xl border border-gray-200 bg-white p-4 shadow-sm transition hover:border-[#cfddca] hover:shadow-md focus:ring-2 focus:ring-[#3f6f3d]/25 focus:outline-none sm:gap-4"
                        >
                            <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-[#e8f1e4] text-[#3f6f3d]">
                                <AnnouncementIcon />
                            </div>

                            <div className="min-w-0">
                                <p className="truncate text-sm font-semibold text-gray-900 sm:text-base">
                                    {latestAnnouncement.title}
                                </p>

                                <p className="mt-1 text-xs text-gray-500 sm:text-sm">
                                    {latestAnnouncement.created_at}
                                </p>
                            </div>
                        </Link>
                    ) : (
                        <div className="flex min-h-[72px] items-center justify-center rounded-xl border border-gray-200 bg-white p-4 text-sm text-gray-500 shadow-sm">
                            No active announcements at this time.
                        </div>
                    )}
                </section>

                {/* BARANGAY ASSISTANCE */}
                <section
                    aria-labelledby="barangay-assistance-heading"
                    className="rounded-2xl border border-[#d6e3d2] bg-white p-4 shadow-sm sm:p-5 lg:p-6"
                >
                    <div className="mb-4">
                        <h2
                            id="barangay-assistance-heading"
                            className="text-lg font-semibold text-gray-900 sm:text-xl"
                        >
                            Need barangay assistance?
                        </h2>

                        <p className="mt-1.5 max-w-3xl text-sm leading-6 text-gray-500 sm:text-base">
                            For concerns that need help from the barangay or
                            Barangay Tanod.
                        </p>
                    </div>

                    <div className="grid grid-cols-1 gap-3 md:grid-cols-2 md:gap-4">
                        <button
                            type="button"
                            onClick={() => setActiveModal('tanod')}
                            className="group w-full overflow-hidden rounded-2xl border border-[#cbdcc7] bg-white text-left shadow-sm transition hover:border-[#9fbd99] hover:shadow-md focus:ring-2 focus:ring-[#3f6f3d]/25 focus:outline-none active:scale-[0.995]"
                        >
                            <div className="flex min-h-[118px] items-center gap-4 p-4 sm:p-5">
                                <span className="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-[#e8f1e4] text-[#3f6f3d] sm:h-14 sm:w-14">
                                    <ShieldIcon />
                                </span>

                                <span className="min-w-0 flex-1">
                                    <span className="mb-1 inline-flex rounded-full bg-[#edf5ea] px-2.5 py-1 text-[11px] font-semibold tracking-wide text-[#3f6f3d] uppercase">
                                        Non-emergency
                                    </span>

                                    <span className="block text-base font-semibold text-gray-900 sm:text-lg">
                                        Tanod Assistance
                                    </span>

                                    <span className="mt-1 block text-sm leading-5 text-gray-500">
                                        For disturbances, suspicious activity,
                                        neighborhood concerns, or barangay help.
                                    </span>
                                </span>

                                <span className="hidden shrink-0 text-[#3f6f3d] sm:block">
                                    <ChevronRightIcon />
                                </span>
                            </div>

                            <div className="border-t border-[#dce8d8] bg-[#f7faf6] px-4 py-2.5 text-xs font-medium text-[#466343] sm:px-5 sm:text-sm">
                                Barangay Tanod contacts available inside
                            </div>
                        </button>

                        <button
                            type="button"
                            onClick={() => setActiveModal('emergency')}
                            className="group w-full overflow-hidden rounded-2xl border border-red-200 bg-white text-left shadow-sm transition hover:border-red-300 hover:shadow-md focus:ring-2 focus:ring-red-300 focus:outline-none active:scale-[0.995]"
                        >
                            <div className="flex min-h-[118px] items-center gap-4 p-4 sm:p-5">
                                <span className="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-red-100 text-red-700 sm:h-14 sm:w-14">
                                    <EmergencyIcon />
                                </span>

                                <span className="min-w-0 flex-1">
                                    <span className="mb-1 inline-flex rounded-full bg-red-100 px-2.5 py-1 text-[11px] font-semibold tracking-wide text-red-700 uppercase">
                                        Urgent
                                    </span>

                                    <span className="block text-base font-semibold text-red-700 sm:text-lg">
                                        Emergency Help
                                    </span>

                                    <span className="mt-1 block text-sm leading-5 text-gray-600">
                                        Quickly find ambulance, police, fire,
                                        hospital, and disaster hotlines.
                                    </span>
                                </span>

                                <span className="hidden shrink-0 text-red-600 sm:block">
                                    <ChevronRightIcon />
                                </span>
                            </div>

                            <div className="border-t border-red-100 bg-red-50 px-4 py-2.5 text-xs font-medium text-red-700 sm:px-5 sm:text-sm">
                                Verified emergency service contacts available
                                inside
                            </div>
                        </button>
                    </div>

                    <p className="mt-4 text-xs leading-5 text-gray-500 sm:text-sm">
                        For life-threatening emergencies, contact the
                        appropriate emergency service directly.
                    </p>
                </section>

                {/* MODALS */}
                {activeModal === 'tanod' && (
                    <ModalContainer
                        title="Request Tanod Assistance"
                        description="Tell us what happened so the barangay can review your concern."
                        icon={
                            <span className="flex h-11 w-11 items-center justify-center rounded-xl bg-[#e8f1e4] text-[#3f6f3d]">
                                <ShieldIcon />
                            </span>
                        }
                        onClose={() => setActiveModal(null)}
                    >
                        <TanodAssistanceForm
                            onCancel={() => setActiveModal(null)}
                            hotlines={barangayHotlines}
                        />
                    </ModalContainer>
                )}

                {activeModal === 'emergency' && (
                    <ModalContainer
                        title="Emergency Help"
                        description="Use this option only for urgent situations that need immediate attention."
                        icon={
                            <span className="flex h-11 w-11 items-center justify-center rounded-xl bg-red-100 text-red-700">
                                <EmergencyIcon />
                            </span>
                        }
                        onClose={() => setActiveModal(null)}
                    >
                        <EmergencyPanel
                            onCancel={() => setActiveModal(null)}
                            hotlines={sharedEmergencyContacts}
                        />
                    </ModalContainer>
                )}
            </ResidentLayout>
        </>
    );
}

function QuickServiceCard({
    href,
    icon,
    title,
    description,
}: {
    href: string;
    icon: ReactNode;
    title: string;
    description: string;
}) {
    return (
        <Link
            href={href}
            className="group flex min-h-[112px] items-center gap-4 rounded-xl border border-gray-200 bg-white p-4 shadow-sm transition hover:border-[#cfddca] hover:shadow-md focus:ring-2 focus:ring-[#3f6f3d]/25 focus:outline-none sm:min-h-[126px] sm:flex-col sm:justify-center sm:p-5 sm:text-center"
        >
            <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-[#e8f1e4] text-[#3f6f3d] sm:h-12 sm:w-12">
                {icon}
            </span>

            <span className="min-w-0">
                <span className="block text-base font-semibold text-gray-900">
                    {title}
                </span>

                <span className="mt-1 block text-sm leading-5 text-gray-500 sm:max-w-sm">
                    {description}
                </span>
            </span>
        </Link>
    );
}

function ContactActionButtons({
    contact,
    theme = 'green',
}: {
    contact: ResidentContact;
    theme?: 'green' | 'red';
}) {
    const [copied, setCopied] = useState(false);

    const handleCopy = () => {
        if (navigator.clipboard) {
            void navigator.clipboard.writeText(contact.phone_number);
            setCopied(true);
            setTimeout(() => setCopied(false), 2000);
        }
    };

    return (
        <div className="flex shrink-0 flex-wrap items-center gap-1.5">
            {contact.can_call && (
                <a
                    href={contact.tel_uri}
                    className={`inline-flex min-h-10 items-center justify-center gap-1.5 rounded-xl px-4 text-xs font-semibold text-white shadow-sm transition ${
                        theme === 'green'
                            ? 'bg-[#3f6f3d] hover:bg-[#315b30]'
                            : 'bg-red-700 hover:bg-red-800'
                    }`}
                    title="Call contact number"
                >
                    <PhoneIcon />
                    Call
                </a>
            )}

            {contact.can_sms && (
                <a
                    href={contact.sms_uri}
                    className="inline-flex min-h-10 items-center justify-center gap-1.5 rounded-xl border border-gray-300 bg-white px-3 text-xs font-semibold text-gray-700 shadow-sm transition hover:bg-gray-50"
                    title="Send SMS"
                >
                    💬 Text
                </a>
            )}

            <button
                type="button"
                onClick={handleCopy}
                className="inline-flex min-h-10 items-center justify-center gap-1 rounded-xl border border-gray-200 bg-gray-50 px-2.5 text-xs font-medium text-gray-600 transition hover:bg-gray-100"
                title="Copy phone number to clipboard"
            >
                {copied ? '✓ Copied' : '📋 Copy'}
            </button>
        </div>
    );
}

function TanodAssistanceForm({
    onCancel,
    hotlines = [],
}: {
    onCancel: () => void;
    hotlines?: ResidentContact[];
}) {
    const [showOtherContacts, setShowOtherContacts] = useState(false);
    const [concernType, setConcernType] = useState('');
    const [location, setLocation] = useState('');
    const [details, setDetails] = useState('');

    const handleSubmit = () => {
        window.alert('Tanod assistance request preview submitted.');
        onCancel();
    };

    const showEmptyState = hotlines.length === 0;
    const officialContact =
        hotlines.find((h) => h.sub_category === 'Official') ??
        hotlines[0] ??
        null;
    const otherContacts = officialContact
        ? hotlines.filter((h) => h.id !== officialContact.id)
        : [];

    const officialName = officialContact?.name ?? '';
    const officialPhoneDisplay = officialContact
        ? formatPhoneNumber(officialContact.phone_number)
        : '';

    return (
        <div className="space-y-5">
            {showEmptyState ? (
                <section className="rounded-2xl border border-gray-200 bg-gray-50 p-6 text-center">
                    <p className="text-sm font-semibold text-gray-900">
                        No Tanod hotlines configured
                    </p>
                    <p className="mt-1 text-sm leading-6 text-gray-500">
                        Your barangay has not added Tanod contacts yet.
                    </p>
                </section>
            ) : (
                <section className="overflow-hidden rounded-2xl border border-[#cdddc9] bg-white shadow-sm">
                    <div className="flex flex-col gap-4 p-4 sm:flex-row sm:items-center sm:justify-between sm:p-5">
                        <div className="flex min-w-0 items-start gap-3">
                            <div className="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-[#e8f1e4] text-[#3f6f3d]">
                                <PhoneIcon />
                            </div>

                            <div className="min-w-0">
                                <p className="text-xs font-semibold tracking-wide text-[#5d7c59] uppercase">
                                    Official Hotline
                                </p>

                                <h3 className="mt-1 text-base font-semibold text-gray-900">
                                    {officialName}
                                </h3>

                                <p className="mt-1 text-2xl font-bold tracking-wide text-[#315b30]">
                                    {officialPhoneDisplay}
                                </p>
                            </div>
                        </div>

                        {officialContact && (
                            <ContactActionButtons
                                contact={officialContact}
                                theme="green"
                            />
                        )}
                    </div>

                    <button
                        type="button"
                        onClick={() =>
                            setShowOtherContacts((current) => !current)
                        }
                        className="flex min-h-12 w-full items-center justify-between gap-3 border-t border-[#dce8d8] bg-[#f7faf6] px-4 py-3 text-left text-sm font-semibold text-[#315b30] transition hover:bg-[#edf4ea] sm:px-5"
                        aria-expanded={showOtherContacts}
                    >
                        <span>
                            {showOtherContacts
                                ? 'Hide other Tanod contacts'
                                : 'View other Tanod contacts'}
                        </span>

                        <span
                            className={`transition-transform ${showOtherContacts ? 'rotate-90' : ''}`}
                        >
                            <ChevronRightIcon />
                        </span>
                    </button>
                </section>
            )}

            {showOtherContacts && !showEmptyState && (
                <section>
                    <div className="mb-3">
                        <h3 className="text-sm font-semibold text-gray-900 sm:text-base">
                            Other Tanod Contacts
                        </h3>

                        <p className="mt-1 text-xs leading-5 text-gray-500 sm:text-sm">
                            These are backup contacts for key Tanod personnel.
                        </p>
                    </div>

                    <div className="space-y-2">
                        {otherContacts.length === 0 ? (
                            <p className="py-2 text-xs text-gray-500">
                                No additional Tanod contacts listed.
                            </p>
                        ) : (
                            otherContacts.map((contact) => (
                                <div
                                    key={contact.id}
                                    className="flex flex-col gap-3 rounded-xl border border-gray-200 bg-white p-3.5 sm:flex-row sm:items-center sm:justify-between"
                                >
                                    <div className="min-w-0">
                                        <p className="font-semibold text-gray-900">
                                            {contact.name}
                                        </p>
                                        {contact.label && (
                                            <p className="mt-0.5 text-sm text-gray-500">
                                                {contact.label}
                                            </p>
                                        )}
                                        <p className="mt-1 text-sm font-semibold tracking-wide text-[#315b30]">
                                            {formatPhoneNumber(
                                                contact.phone_number,
                                            )}
                                        </p>
                                    </div>

                                    <ContactActionButtons
                                        contact={contact}
                                        theme="green"
                                    />
                                </div>
                            ))
                        )}
                    </div>
                </section>
            )}

            <div className="border-t border-gray-100 pt-5">
                <h3 className="text-base font-semibold text-gray-900">
                    Send a Tanod Assistance Request
                </h3>

                <p className="mt-1 text-sm leading-5 text-gray-500">
                    For non-urgent concerns, you can also send a request through
                    KaBarangay.
                </p>
            </div>

            <div>
                <label
                    htmlFor="tanod-concern"
                    className="mb-2 block text-sm font-semibold text-gray-700"
                >
                    Concern Type
                </label>

                <select
                    id="tanod-concern"
                    value={concernType}
                    onChange={(e) => setConcernType(e.target.value)}
                    className="min-h-12 w-full rounded-xl border border-gray-300 bg-white px-3 text-base text-gray-800 transition outline-none focus:border-[#3f6f3d] focus:ring-2 focus:ring-[#3f6f3d]/15 sm:text-sm"
                >
                    <option value="">Select concern</option>
                    <option value="noise">Noise disturbance</option>
                    <option value="conflict">Neighborhood conflict</option>
                    <option value="suspicious">Suspicious activity</option>
                    <option value="assistance">Assistance needed</option>
                    <option value="other">Other concern</option>
                </select>
            </div>

            <div>
                <label
                    htmlFor="tanod-location"
                    className="mb-2 block text-sm font-semibold text-gray-700"
                >
                    Where is it happening?
                </label>

                <input
                    id="tanod-location"
                    type="text"
                    value={location}
                    onChange={(e) => setLocation(e.target.value)}
                    placeholder="Enter purok, street, or nearby landmark"
                    className="min-h-12 w-full rounded-xl border border-gray-300 bg-white px-3 text-base text-gray-800 transition outline-none placeholder:text-gray-400 focus:border-[#3f6f3d] focus:ring-2 focus:ring-[#3f6f3d]/15 sm:text-sm"
                />
            </div>

            <div>
                <label
                    htmlFor="tanod-details"
                    className="mb-2 block text-sm font-semibold text-gray-700"
                >
                    Tell us what happened
                </label>

                <textarea
                    id="tanod-details"
                    rows={4}
                    value={details}
                    onChange={(e) => setDetails(e.target.value)}
                    placeholder="Describe the concern clearly..."
                    className="w-full resize-y rounded-xl border border-gray-300 bg-white p-3 text-base text-gray-800 transition outline-none placeholder:text-gray-400 focus:border-[#3f6f3d] focus:ring-2 focus:ring-[#3f6f3d]/15 sm:text-sm"
                />
            </div>

            <div className="flex flex-col-reverse gap-2 pt-2 sm:flex-row sm:justify-end">
                <button
                    type="button"
                    onClick={onCancel}
                    className="min-h-12 rounded-xl border border-gray-300 bg-white px-5 text-sm font-semibold text-gray-700 transition hover:bg-gray-50"
                >
                    Cancel
                </button>

                <button
                    type="button"
                    onClick={handleSubmit}
                    className="min-h-12 rounded-xl bg-[#3f6f3d] px-5 text-sm font-semibold text-white transition hover:bg-[#315b30]"
                >
                    Send Request
                </button>
            </div>
        </div>
    );
}

function formatPhoneNumber(phone: string) {
    if (phone.length === 11 && phone.startsWith('09')) {
        return `${phone.slice(0, 4)} ${phone.slice(4, 7)} ${phone.slice(7)}`;
    }
    return phone;
}

function EmergencyPanel({
    onCancel,
    hotlines = [],
}: {
    onCancel: () => void;
    hotlines?: ResidentContact[];
}) {
    const showEmptyState = hotlines.length === 0;
    const dynamicCategories = Array.from(
        new Set(hotlines.map((h) => h.sub_category || 'General')),
    );

    const [expandedCategory, setExpandedCategory] = useState<string>(
        dynamicCategories[0] || 'City Emergency',
    );

    const visibleQuickCalls = [
        {
            icon: '🆘',
            title: 'City Emergency',
            contact:
                hotlines.find((h) => h.sub_category === 'City Emergency') ??
                null,
        },
        {
            icon: '🚑',
            title: 'Ambulance',
            contact:
                hotlines.find((h) => h.sub_category === 'Ambulance') ?? null,
        },
        {
            icon: '🔥',
            title: 'Fire',
            contact: hotlines.find((h) => h.sub_category === 'Fire') ?? null,
        },
        {
            icon: '👮',
            title: 'Police',
            contact: hotlines.find((h) => h.sub_category === 'Police') ?? null,
        },
    ].filter(
        (
            call,
        ): call is { icon: string; title: string; contact: ResidentContact } =>
            call.contact !== null && call.contact.can_call,
    );

    if (showEmptyState) {
        return (
            <div className="space-y-5">
                <div className="rounded-xl border border-gray-200 bg-gray-50 p-6 text-center">
                    <p className="text-sm font-semibold text-gray-900">
                        No verified emergency contacts available
                    </p>
                    <p className="mt-1 text-sm leading-6 text-gray-500">
                        Verified emergency service contacts have not been added
                        yet.
                    </p>
                </div>

                <div className="flex justify-end pt-1">
                    <button
                        type="button"
                        onClick={onCancel}
                        className="min-h-12 w-full rounded-xl border border-gray-300 bg-white px-5 text-sm font-semibold text-gray-700 transition hover:bg-gray-50 sm:w-auto"
                    >
                        Close
                    </button>
                </div>
            </div>
        );
    }

    return (
        <div className="space-y-5">
            <div
                role="alert"
                className="rounded-xl border border-red-200 bg-red-50 p-4"
            >
                <p className="text-sm font-semibold text-red-800">
                    Need urgent help?
                </p>
                <p className="mt-1 text-sm leading-6 text-red-700">
                    Tap a phone number below to call.
                </p>
            </div>

            {visibleQuickCalls.length > 0 && (
                <section>
                    <h3 className="text-base font-semibold text-gray-900">
                        Quick Call
                    </h3>
                    <p className="mt-1 text-sm leading-5 text-gray-500">
                        Common emergency contacts are placed first.
                    </p>

                    <div className="mt-3 grid grid-cols-1 gap-2 sm:grid-cols-2">
                        {visibleQuickCalls.map((call) => (
                            <EmergencyQuickCall
                                key={call.title}
                                icon={call.icon}
                                title={call.title}
                                contact={call.contact}
                            />
                        ))}
                    </div>
                </section>
            )}

            <section className="border-t border-gray-100 pt-5">
                <h3 className="text-base font-semibold text-gray-900">
                    All Emergency Contacts
                </h3>

                <div className="mt-3 space-y-2">
                    {dynamicCategories.map((category) => {
                        const contacts = hotlines.filter(
                            (c) => (c.sub_category || 'General') === category,
                        );
                        const isOpen = expandedCategory === category;

                        return (
                            <div
                                key={category}
                                className="overflow-hidden rounded-xl border border-gray-200 bg-white"
                            >
                                <button
                                    type="button"
                                    onClick={() =>
                                        setExpandedCategory(
                                            isOpen ? '' : category,
                                        )
                                    }
                                    className="flex min-h-12 w-full items-center justify-between gap-3 px-4 py-3 text-left transition hover:bg-gray-50"
                                    aria-expanded={isOpen}
                                >
                                    <span className="flex items-center gap-3">
                                        <span className="text-lg">
                                            {emergencyCategoryIcon(category)}
                                        </span>

                                        <span>
                                            <span className="block text-sm font-semibold text-gray-900">
                                                {category}
                                            </span>
                                            <span className="block text-xs text-gray-500">
                                                {contacts.length} contact
                                                {contacts.length > 1 ? 's' : ''}
                                            </span>
                                        </span>
                                    </span>

                                    <span
                                        className={`text-gray-400 transition-transform ${isOpen ? 'rotate-90' : ''}`}
                                    >
                                        <ChevronRightIcon />
                                    </span>
                                </button>

                                {isOpen && (
                                    <div className="border-t border-gray-100 bg-gray-50/60 p-2">
                                        {contacts.map((contact) => (
                                            <div
                                                key={contact.id}
                                                className="mb-2 flex flex-col gap-3 rounded-lg bg-white p-3 last:mb-0 sm:flex-row sm:items-center sm:justify-between"
                                            >
                                                <div className="min-w-0">
                                                    <p className="text-sm font-semibold text-gray-900">
                                                        {contact.name}
                                                    </p>
                                                    {contact.label && (
                                                        <p className="mt-0.5 text-xs text-gray-500">
                                                            {contact.label}
                                                        </p>
                                                    )}
                                                    <p className="mt-1 text-sm font-bold tracking-wide text-red-700">
                                                        {formatPhoneNumber(
                                                            contact.phone_number,
                                                        )}
                                                    </p>
                                                </div>

                                                <ContactActionButtons
                                                    contact={contact}
                                                    theme="red"
                                                />
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </div>
                        );
                    })}
                </div>
            </section>

            <div className="flex justify-end pt-1">
                <button
                    type="button"
                    onClick={onCancel}
                    className="min-h-12 w-full rounded-xl border border-gray-300 bg-white px-5 text-sm font-semibold text-gray-700 transition hover:bg-gray-50 sm:w-auto"
                >
                    Close
                </button>
            </div>
        </div>
    );
}

function EmergencyQuickCall({
    icon,
    title,
    contact,
}: {
    icon: string;
    title: string;
    contact: ResidentContact;
}) {
    return (
        <a
            href={contact.tel_uri}
            className="flex min-h-[86px] items-center gap-3 rounded-xl border border-red-100 bg-red-50 p-3.5 transition hover:border-red-200 hover:bg-red-100/70"
        >
            <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-white text-xl shadow-sm">
                {icon}
            </span>

            <span className="min-w-0 flex-1">
                <span className="block text-sm font-semibold text-red-800">
                    {title}
                </span>

                <span className="mt-0.5 block truncate text-xs text-gray-500">
                    {contact.label || contact.name}
                </span>

                <span className="mt-1 block text-sm font-bold tracking-wide text-red-700">
                    {formatPhoneNumber(contact.phone_number)}
                </span>
            </span>

            <span className="text-red-700">
                <PhoneIcon />
            </span>
        </a>
    );
}

function emergencyCategoryIcon(category: string) {
    switch (category) {
        case 'City Emergency':
            return '🆘';
        case 'Ambulance':
            return '🚑';
        case 'Fire':
            return '🔥';
        case 'Police':
            return '👮';
        case 'Hospital':
            return '🏥';
        case 'Disaster':
            return '🌧️';
        default:
            return '☎️';
    }
}

function ModalContainer({
    title,
    description,
    icon,
    children,
    onClose,
}: {
    title: string;
    description: string;
    icon: ReactNode;
    children: ReactNode;
    onClose: () => void;
}) {
    return (
        <div
            className="fixed inset-0 z-[80] flex items-center justify-center bg-black/45 p-3 sm:p-4"
            role="dialog"
            aria-modal="true"
            aria-labelledby="resident-dashboard-modal-title"
            onMouseDown={(event) => {
                if (event.target === event.currentTarget) {
                    onClose();
                }
            }}
        >
            <div className="flex max-h-[92vh] w-full flex-col overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-2xl sm:max-w-xl">
                <div className="flex shrink-0 items-start justify-between gap-4 border-b border-gray-100 bg-white p-5 sm:p-6">
                    <div className="flex min-w-0 items-start gap-3">
                        {icon}

                        <div className="min-w-0">
                            <h2
                                id="resident-dashboard-modal-title"
                                className="text-xl font-semibold text-gray-900"
                            >
                                {title}
                            </h2>

                            <p className="mt-1 text-sm leading-5 text-gray-500">
                                {description}
                            </p>
                        </div>
                    </div>

                    <button
                        type="button"
                        onClick={onClose}
                        aria-label="Close"
                        className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full text-gray-500 transition hover:bg-gray-100 focus:ring-2 focus:ring-gray-200 focus:outline-none"
                    >
                        <CloseIcon />
                    </button>
                </div>

                <div className="min-h-0 flex-1 overflow-y-auto overscroll-contain">
                    <div className="p-5 sm:p-6">{children}</div>
                </div>
            </div>
        </div>
    );
}

function CertificateIcon() {
    return (
        <svg
            viewBox="0 0 24 24"
            fill="none"
            className="h-6 w-6"
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
            className="h-6 w-6"
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

function ShieldIcon() {
    return (
        <svg
            viewBox="0 0 24 24"
            fill="none"
            className="h-6 w-6"
            stroke="currentColor"
            strokeWidth="1.9"
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
        >
            <path d="M12 3 5 6v5c0 5 3 8 7 10 4-2 7-5 7-10V6Z" />
            <path d="m9 12 2 2 4-4" />
        </svg>
    );
}

function EmergencyIcon() {
    return (
        <svg
            viewBox="0 0 24 24"
            fill="none"
            className="h-6 w-6"
            stroke="currentColor"
            strokeWidth="1.9"
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
        >
            <path d="M12 3v3" />
            <path d="M5.6 5.6 7.7 7.7" />
            <path d="M3 12h3" />
            <path d="M18 12h3" />
            <path d="m16.3 7.7 2.1-2.1" />
            <path d="M8 18h8" />
            <path d="M9 18v-5a3 3 0 0 1 6 0v5" />
            <path d="M7 21h10" />
        </svg>
    );
}

function PhoneIcon() {
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
            <path d="M5 4h3l2 5-2 2a16 16 0 0 0 5 5l2-2 5 2v3a2 2 0 0 1-2 2C10 21 3 14 3 6a2 2 0 0 1 2-2Z" />
        </svg>
    );
}

function ChevronRightIcon() {
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
            <path d="m9 18 6-6-6-6" />
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
