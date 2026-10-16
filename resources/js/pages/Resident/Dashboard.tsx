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

type DashboardProps = {
    resident: ResidentDashboardResident;
    latestAnnouncement?: LatestAnnouncement;
};

type TanodContact = {
    id: number;
    name: string;
    position: string;
    phone: string;
};

const barangayContact = {
    barangayName: 'Barangay San Jose',
    tanodHotline: '09394596081',
    tanodHotlineDisplay: '0939 459 6081',
};

const publicTanodContacts: TanodContact[] = [
    {
        id: 1,
        name: 'Cenon Mabutin Bocacao',
        position: 'Chief Tanod',
        phone: '09262235797',
    },
    {
        id: 2,
        name: 'Romy Dangan Quiray',
        position: 'Summon Officer',
        phone: '09050847008',
    },
    {
        id: 3,
        name: 'Ronie Herrera Nazon',
        position: 'Team Leader',
        phone: '09661961005',
    },
    {
        id: 4,
        name: 'Tito Paduga Jagmis',
        position: 'Team Leader',
        phone: '09550717360',
    },
    {
        id: 5,
        name: 'Elmer Magbura Martinez',
        position: 'Team Leader',
        phone: '09050578797',
    },
];

type EmergencyContact = {
    id: number;
    category: string;
    agency: string;
    label: string;
    phone: string;
};

const emergencyContacts: EmergencyContact[] = [
    {
        id: 1,
        category: 'City Emergency',
        agency: 'Puerto Princesa City - 911',
        label: 'Emergency Hotline 1',
        phone: '09277972009',
    },
    {
        id: 2,
        category: 'City Emergency',
        agency: 'Puerto Princesa City - 911',
        label: 'Emergency Hotline 2',
        phone: '09204305378',
    },
    {
        id: 3,
        category: 'City Emergency',
        agency: 'Puerto Princesa City - 911',
        label: 'Emergency Hotline 3',
        phone: '09171120324',
    },
    {
        id: 4,
        category: 'Ambulance',
        agency: 'HERO',
        label: 'Ambulance Hotline',
        phone: '09504912599',
    },
    {
        id: 5,
        category: 'Ambulance',
        agency: 'HERO',
        label: 'Ambulance Hotline',
        phone: '09513126727',
    },
    {
        id: 6,
        category: 'Ambulance',
        agency: 'HERO',
        label: 'Ambulance Hotline',
        phone: '09953322488',
    },
    {
        id: 7,
        category: 'Ambulance',
        agency: 'HERO',
        label: 'Health Emergency Response Operations',
        phone: '09103254577',
    },
    {
        id: 8,
        category: 'Fire',
        agency: 'PPC BFP',
        label: 'Fire Emergency',
        phone: '09649452971',
    },
    {
        id: 9,
        category: 'Police',
        agency: 'PNP Station 1 - Mendoza',
        label: 'Police',
        phone: '09173115746',
    },
    {
        id: 10,
        category: 'Police',
        agency: 'PNP Station 2 - Irawan',
        label: 'Police',
        phone: '09271624065',
    },
    {
        id: 11,
        category: 'Police',
        agency: 'PNP Station 3 - Luzviminda',
        label: 'Police',
        phone: '09272347443',
    },
    {
        id: 12,
        category: 'Police',
        agency: 'PNP Station 4 - Macarascas',
        label: 'Police',
        phone: '09282006155',
    },
    {
        id: 13,
        category: 'Police',
        agency: 'PNP Station 5 - San Rafael',
        label: 'Police',
        phone: '09993802689',
    },
    {
        id: 14,
        category: 'Hospital',
        agency: 'ONP',
        label: 'Hospital',
        phone: '09271338635',
    },
    {
        id: 15,
        category: 'Hospital',
        agency: 'Adventist Hospital',
        label: 'Hospital',
        phone: '09455097723',
    },
    {
        id: 16,
        category: 'Hospital',
        agency: 'ACE',
        label: 'Hospital',
        phone: '09176323122',
    },
    {
        id: 17,
        category: 'Hospital',
        agency: 'PMMG-COOP',
        label: 'Hospital',
        phone: '09064487439',
    },
    {
        id: 18,
        category: 'Disaster',
        agency: 'CDRRMO',
        label: 'Bagyo / Baha / Guho',
        phone: '09653148399',
    },
    {
        id: 19,
        category: 'Disaster',
        agency: 'CDRRMO',
        label: 'Bagyo / Baha / Guho',
        phone: '09387944004',
    },
];

const emergencyCategories = [
    'City Emergency',
    'Ambulance',
    'Fire',
    'Police',
    'Hospital',
    'Disaster',
] as const;

export default function Dashboard({
    resident,
    latestAnnouncement,
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
                    <h2
                        id="quick-services-heading"
                        className="sr-only"
                    >
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
                            className="shrink-0 rounded-md px-2 py-1 text-sm font-semibold text-[#3f6f3d] transition hover:bg-[#edf4ea] focus:outline-none focus:ring-2 focus:ring-[#3f6f3d]/30"
                        >
                            View All
                        </Link>
                    </div>

                    {latestAnnouncement ? (
                        <Link
                            href="/resident/announcements"
                            className="flex min-h-[72px] items-center gap-3 rounded-xl border border-gray-200 bg-white p-4 shadow-sm transition hover:border-[#cfddca] hover:shadow-md focus:outline-none focus:ring-2 focus:ring-[#3f6f3d]/25 sm:gap-4"
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
                            For concerns that need help from the barangay
                            or Barangay Tanod.
                        </p>
                    </div>

                    <div className="grid grid-cols-1 gap-3 md:grid-cols-2 md:gap-4">
                        <button
                            type="button"
                            onClick={() => setActiveModal('tanod')}
                            className="group w-full overflow-hidden rounded-2xl border border-[#cbdcc7] bg-white text-left shadow-sm transition hover:border-[#9fbd99] hover:shadow-md focus:outline-none focus:ring-2 focus:ring-[#3f6f3d]/25 active:scale-[0.995]"
                        >
                            <div className="flex min-h-[118px] items-center gap-4 p-4 sm:p-5">
                                <span className="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-[#e8f1e4] text-[#3f6f3d] sm:h-14 sm:w-14">
                                    <ShieldIcon />
                                </span>

                                <span className="min-w-0 flex-1">
                                    <span className="mb-1 inline-flex rounded-full bg-[#edf5ea] px-2.5 py-1 text-[11px] font-semibold uppercase tracking-wide text-[#3f6f3d]">
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
                                Official San Jose Tanod hotline available inside
                            </div>
                        </button>

                        <button
                            type="button"
                            onClick={() => setActiveModal('emergency')}
                            className="group w-full overflow-hidden rounded-2xl border border-red-200 bg-white text-left shadow-sm transition hover:border-red-300 hover:shadow-md focus:outline-none focus:ring-2 focus:ring-red-300 active:scale-[0.995]"
                        >
                            <div className="flex min-h-[118px] items-center gap-4 p-4 sm:p-5">
                                <span className="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-red-100 text-red-700 sm:h-14 sm:w-14">
                                    <EmergencyIcon />
                                </span>

                                <span className="min-w-0 flex-1">
                                    <span className="mb-1 inline-flex rounded-full bg-red-100 px-2.5 py-1 text-[11px] font-semibold uppercase tracking-wide text-red-700">
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
                                Puerto Princesa City emergency contacts available inside
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
                        <TanodAssistanceForm onCancel={() => setActiveModal(null)} />
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
                        <EmergencyPanel onCancel={() => setActiveModal(null)} />
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
            className="group flex min-h-[112px] items-center gap-4 rounded-xl border border-gray-200 bg-white p-4 shadow-sm transition hover:border-[#cfddca] hover:shadow-md focus:outline-none focus:ring-2 focus:ring-[#3f6f3d]/25 sm:min-h-[126px] sm:flex-col sm:justify-center sm:p-5 sm:text-center"
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

function TanodAssistanceForm({ onCancel }: { onCancel: () => void }) {
    const [showOtherContacts, setShowOtherContacts] = useState(false);
    const [concernType, setConcernType] = useState('');
    const [location, setLocation] = useState('');
    const [details, setDetails] = useState('');

    const handleSubmit = () => {
        window.alert('Tanod assistance request preview submitted.');
        onCancel();
    };

    return (
        <div className="space-y-5">
            <section className="overflow-hidden rounded-2xl border border-[#cdddc9] bg-white shadow-sm">
                <div className="flex flex-col gap-4 p-4 sm:flex-row sm:items-center sm:justify-between sm:p-5">
                    <div className="flex min-w-0 items-start gap-3">
                        <div className="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-[#e8f1e4] text-[#3f6f3d]">
                            <PhoneIcon />
                        </div>

                        <div className="min-w-0">
                            <p className="text-xs font-semibold uppercase tracking-wide text-[#5d7c59]">
                                Official Hotline
                            </p>

                            <h3 className="mt-1 text-base font-semibold text-gray-900">
                                {barangayContact.barangayName} Tanod
                            </h3>

                            <p className="mt-1 text-2xl font-bold tracking-wide text-[#315b30]">
                                {barangayContact.tanodHotlineDisplay}
                            </p>
                        </div>
                    </div>

                    <a
                        href={`tel:${barangayContact.tanodHotline}`}
                        className="inline-flex min-h-12 shrink-0 items-center justify-center gap-2 rounded-xl bg-[#3f6f3d] px-5 text-sm font-semibold text-white transition hover:bg-[#315b30] focus:outline-none focus:ring-2 focus:ring-[#3f6f3d]/30"
                    >
                        <PhoneIcon />
                        Call Hotline
                    </a>
                </div>

                <button
                    type="button"
                    onClick={() => setShowOtherContacts((current) => !current)}
                    className="flex min-h-12 w-full items-center justify-between gap-3 border-t border-[#dce8d8] bg-[#f7faf6] px-4 py-3 text-left text-sm font-semibold text-[#315b30] transition hover:bg-[#edf4ea] sm:px-5"
                    aria-expanded={showOtherContacts}
                >
                    <span>
                        {showOtherContacts
                            ? 'Hide other Tanod contacts'
                            : 'View other Tanod contacts'}
                    </span>

                    <span className={`transition-transform ${showOtherContacts ? 'rotate-90' : ''}`}>
                        <ChevronRightIcon />
                    </span>
                </button>
            </section>

            {showOtherContacts && (
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
                        {publicTanodContacts.map((contact) => (
                            <div
                                key={contact.id}
                                className="flex flex-col gap-3 rounded-xl border border-gray-200 bg-white p-3.5 sm:flex-row sm:items-center sm:justify-between"
                            >
                                <div className="min-w-0">
                                    <p className="font-semibold text-gray-900">
                                        {contact.name}
                                    </p>

                                    <p className="mt-0.5 text-sm text-gray-500">
                                        {contact.position}
                                    </p>

                                    <p className="mt-1 text-sm font-semibold tracking-wide text-[#315b30]">
                                        {formatPhoneNumber(contact.phone)}
                                    </p>
                                </div>

                                <a
                                    href={`tel:${contact.phone}`}
                                    className="inline-flex min-h-11 shrink-0 items-center justify-center gap-2 rounded-xl border border-[#b9cfb5] bg-white px-4 text-sm font-semibold text-[#315b30] transition hover:bg-[#edf4ea]"
                                >
                                    <PhoneIcon />
                                    Call
                                </a>
                            </div>
                        ))}
                    </div>
                </section>
            )}

            <div className="border-t border-gray-100 pt-5">
                <h3 className="text-base font-semibold text-gray-900">
                    Send a Tanod Assistance Request
                </h3>

                <p className="mt-1 text-sm leading-5 text-gray-500">
                    For non-urgent concerns, you can also send a request through KaBarangay.
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
                    className="min-h-12 w-full rounded-xl border border-gray-300 bg-white px-3 text-base text-gray-800 outline-none transition focus:border-[#3f6f3d] focus:ring-2 focus:ring-[#3f6f3d]/15 sm:text-sm"
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
                    className="min-h-12 w-full rounded-xl border border-gray-300 bg-white px-3 text-base text-gray-800 outline-none transition placeholder:text-gray-400 focus:border-[#3f6f3d] focus:ring-2 focus:ring-[#3f6f3d]/15 sm:text-sm"
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
                    className="w-full resize-y rounded-xl border border-gray-300 bg-white p-3 text-base text-gray-800 outline-none transition placeholder:text-gray-400 focus:border-[#3f6f3d] focus:ring-2 focus:ring-[#3f6f3d]/15 sm:text-sm"
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

function EmergencyPanel({ onCancel }: { onCancel: () => void }) {
    const [expandedCategory, setExpandedCategory] = useState<string>('City Emergency');

    return (
        <div className="space-y-5">
            <div role="alert" className="rounded-xl border border-red-200 bg-red-50 p-4">
                <p className="text-sm font-semibold text-red-800">
                    Need urgent help?
                </p>
                <p className="mt-1 text-sm leading-6 text-red-700">
                    Tap a phone number below to call.
                </p>
            </div>

            <section>
                <h3 className="text-base font-semibold text-gray-900">
                    Quick Call
                </h3>
                <p className="mt-1 text-sm leading-5 text-gray-500">
                    Common emergency contacts are placed first.
                </p>

                <div className="mt-3 grid grid-cols-1 gap-2 sm:grid-cols-2">
                    <EmergencyQuickCall
                        icon="🆘"
                        title="City Emergency"
                        subtitle="Puerto Princesa City - 911"
                        phone="09277972009"
                    />
                    <EmergencyQuickCall
                        icon="🚑"
                        title="Ambulance"
                        subtitle="HERO"
                        phone="09504912599"
                    />
                    <EmergencyQuickCall
                        icon="🔥"
                        title="Fire"
                        subtitle="PPC BFP"
                        phone="09649452971"
                    />
                    <EmergencyQuickCall
                        icon="👮"
                        title="Police"
                        subtitle="PNP Station 1 - Mendoza"
                        phone="09173115746"
                    />
                </div>
            </section>

            <section className="border-t border-gray-100 pt-5">
                <h3 className="text-base font-semibold text-gray-900">
                    All Emergency Contacts
                </h3>

                <div className="mt-3 space-y-2">
                    {emergencyCategories.map((category) => {
                        const contacts = emergencyContacts.filter((c) => c.category === category);
                        const isOpen = expandedCategory === category;

                        return (
                            <div key={category} className="overflow-hidden rounded-xl border border-gray-200 bg-white">
                                <button
                                    type="button"
                                    onClick={() => setExpandedCategory(isOpen ? '' : category)}
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
                                                {contacts.length} contact{contacts.length > 1 ? 's' : ''}
                                            </span>
                                        </span>
                                    </span>

                                    <span className={`text-gray-400 transition-transform ${isOpen ? 'rotate-90' : ''}`}>
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
                                                        {contact.agency}
                                                    </p>
                                                    <p className="mt-0.5 text-xs text-gray-500">
                                                        {contact.label}
                                                    </p>
                                                    <p className="mt-1 text-sm font-bold tracking-wide text-red-700">
                                                        {formatPhoneNumber(contact.phone)}
                                                    </p>
                                                </div>

                                                <a
                                                    href={`tel:${contact.phone}`}
                                                    className="inline-flex min-h-11 shrink-0 items-center justify-center gap-2 rounded-xl bg-red-700 px-4 text-sm font-semibold text-white transition hover:bg-red-800"
                                                >
                                                    <PhoneIcon />
                                                    Call
                                                </a>
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
    subtitle,
    phone,
}: {
    icon: string;
    title: string;
    subtitle: string;
    phone: string;
}) {
    return (
        <a
            href={`tel:${phone}`}
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
                    {subtitle}
                </span>

                <span className="mt-1 block text-sm font-bold tracking-wide text-red-700">
                    {formatPhoneNumber(phone)}
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
                        className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full text-gray-500 transition hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-gray-200"
                    >
                        <CloseIcon />
                    </button>
                </div>

                <div className="min-h-0 flex-1 overflow-y-auto overscroll-contain">
                    <div className="p-5 sm:p-6">
                        {children}
                    </div>
                </div>
            </div>
        </div>
    );
}

function CertificateIcon() {
    return (
        <svg viewBox="0 0 24 24" fill="none" className="h-6 w-6" stroke="currentColor" strokeWidth="1.9" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
            <path d="M7 3h7l4 4v14H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2Z" />
            <path d="M14 3v5h4" />
            <path d="M9 13h6" />
            <path d="M9 17h4" />
        </svg>
    );
}

function ComplaintIcon() {
    return (
        <svg viewBox="0 0 24 24" fill="none" className="h-6 w-6" stroke="currentColor" strokeWidth="1.9" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
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
        <svg viewBox="0 0 24 24" fill="none" className="h-5 w-5" stroke="currentColor" strokeWidth="1.9" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
            <path d="M4 13V9a2 2 0 0 1 2-2h3l8-3v14l-8-3H6a2 2 0 0 1-2-2Z" />
            <path d="M8 15l1 5h3l-1-5" />
            <path d="M20 8v6" />
        </svg>
    );
}

function ShieldIcon() {
    return (
        <svg viewBox="0 0 24 24" fill="none" className="h-6 w-6" stroke="currentColor" strokeWidth="1.9" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
            <path d="M12 3 5 6v5c0 5 3 8 7 10 4-2 7-5 7-10V6Z" />
            <path d="m9 12 2 2 4-4" />
        </svg>
    );
}

function EmergencyIcon() {
    return (
        <svg viewBox="0 0 24 24" fill="none" className="h-6 w-6" stroke="currentColor" strokeWidth="1.9" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
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
        <svg viewBox="0 0 24 24" fill="none" className="h-5 w-5" stroke="currentColor" strokeWidth="1.9" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
            <path d="M5 4h3l2 5-2 2a16 16 0 0 0 5 5l2-2 5 2v3a2 2 0 0 1-2 2C10 21 3 14 3 6a2 2 0 0 1 2-2Z" />
        </svg>
    );
}

function ChevronRightIcon() {
    return (
        <svg viewBox="0 0 24 24" fill="none" className="h-5 w-5" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
            <path d="m9 18 6-6-6-6" />
        </svg>
    );
}

function CloseIcon() {
    return (
        <svg viewBox="0 0 24 24" fill="none" className="h-5 w-5" stroke="currentColor" strokeWidth="2" strokeLinecap="round" aria-hidden="true">
            <path d="M6 6l12 12" />
            <path d="M18 6 6 18" />
        </svg>
    );
}