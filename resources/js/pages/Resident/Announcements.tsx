import { useMemo, useState } from 'react';
import ResidentLayout from '@/layouts/ResidentLayout';

type AnnouncementCategory =
    | 'Important'
    | 'Events'
    | 'Services';

type Announcement = {
    id: number;
    title: string;
    category: AnnouncementCategory;
    date: string;
    preview: string;
    content: string;
    important?: boolean;
};

type FilterType = 'All' | AnnouncementCategory;

/*
|--------------------------------------------------------------------------
| Mock Announcements
|--------------------------------------------------------------------------
*/

const announcements: Announcement[] = [
    {
        id: 1,
        title: 'Water Interruption Advisory',
        category: 'Important',
        date: 'September 5, 2026',
        preview:
            'Residents are advised of a temporary water interruption affecting selected areas of the barangay.',
        content:
            'Residents are advised that a temporary water interruption will affect selected areas of Barangay San Jose on September 5, 2026. Please store enough water for household use before the scheduled interruption. Normal water service is expected to resume after the maintenance activity is completed.',
        important: true,
    },
    {
        id: 2,
        title: 'Barangay Health Center Schedule',
        category: 'Services',
        date: 'September 4, 2026',
        preview:
            'Please be informed of the updated consultation schedule of the Barangay Health Center.',
        content:
            'The Barangay Health Center has updated its consultation schedule. Residents who need basic health services are encouraged to visit during the posted service hours. Please bring a valid identification card or barangay resident identification when available.',
    },
    {
        id: 3,
        title: 'Community Clean-Up Drive',
        category: 'Events',
        date: 'September 2, 2026',
        preview:
            'Residents are invited to participate in the upcoming community clean-up drive.',
        content:
            'Barangay San Jose invites residents to participate in the community clean-up drive. Participants are encouraged to bring cleaning materials such as brooms, sacks, gloves, and other appropriate equipment. The activity aims to promote cleanliness and community participation throughout the barangay.',
    },
    {
        id: 4,
        title: 'Certificate Processing Advisory',
        category: 'Services',
        date: 'August 30, 2026',
        preview:
            'Residents requesting barangay certificates are reminded to review their information before submitting.',
        content:
            'Residents requesting barangay certificates are reminded to provide complete and accurate information. Online requests can be monitored through the My Requests section of the KaBarangay Resident Portal. Residents will be informed when their requested certificate is ready for claiming.',
    },
    {
        id: 5,
        title: 'Scheduled Barangay Assembly',
        category: 'Events',
        date: 'August 28, 2026',
        preview:
            'A Barangay Assembly will be held for residents to receive updates about barangay programs and activities.',
        content:
            'Residents are invited to attend the scheduled Barangay Assembly. Updates regarding barangay programs, community activities, public services, and other important matters will be discussed during the assembly.',
    },
];

/*
|--------------------------------------------------------------------------
| Main Component
|--------------------------------------------------------------------------
*/

export default function Announcements() {
    const [filter, setFilter] = useState<FilterType>('All');
    const [search, setSearch] = useState('');

    const [selectedAnnouncement, setSelectedAnnouncement] =
        useState<Announcement | null>(null);

    const filteredAnnouncements = useMemo(() => {
        const query = search.trim().toLowerCase();

        return announcements.filter((announcement) => {
            const matchesFilter =
                filter === 'All' ||
                announcement.category === filter;

            const matchesSearch =
                query === '' ||
                announcement.title.toLowerCase().includes(query) ||
                announcement.preview.toLowerCase().includes(query) ||
                announcement.content.toLowerCase().includes(query);

            return matchesFilter && matchesSearch;
        });
    }, [filter, search]);

    const importantAnnouncement = announcements.find(
        (announcement) => announcement.important,
    );

    /*
    |--------------------------------------------------------------------------
    | Announcement Details
    |--------------------------------------------------------------------------
    */

    if (selectedAnnouncement) {
        return (
            <ResidentLayout active="announcements">
                <div className="mx-auto w-full max-w-5xl">

                    {/* BACK */}

                    <button
                        type="button"
                        onClick={() => {
                            setSelectedAnnouncement(null);

                            window.scrollTo({
                                top: 0,
                                behavior: 'smooth',
                            });
                        }}
                        className="
                            mb-4
                            inline-flex
                            min-h-10
                            items-center
                            gap-2
                            rounded-lg
                            px-2
                            text-sm
                            font-semibold
                            text-gray-600
                            transition
                            hover:bg-gray-100
                            hover:text-gray-900
                        "
                    >
                        <BackIcon />
                        Back to Announcements
                    </button>

                    {/* ANNOUNCEMENT */}

                    <article className="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm">

                        <div
                            className={`h-1.5 ${
                                selectedAnnouncement.important
                                    ? 'bg-amber-400'
                                    : 'bg-[#6f9868]'
                            }`}
                        />

                        <div className="p-5 sm:p-7 lg:p-8">

                            {/* CATEGORY */}

                            <div className="flex flex-wrap items-center gap-2">
                                <CategoryBadge
                                    category={
                                        selectedAnnouncement.category
                                    }
                                />

                                {selectedAnnouncement.important && (
                                    <span className="inline-flex items-center gap-1.5 rounded-full bg-amber-50 px-2.5 py-1 text-xs font-semibold text-amber-700">
                                        <PinIcon />
                                        Important
                                    </span>
                                )}
                            </div>

                            {/* TITLE */}

                            <h1 className="mt-4 text-xl font-bold leading-tight text-gray-900 sm:text-2xl lg:text-3xl">
                                {selectedAnnouncement.title}
                            </h1>

                            {/* DATE */}

                            <div className="mt-3 flex items-center gap-2 text-sm text-gray-500">
                                <CalendarIcon />

                                <span>
                                    {selectedAnnouncement.date}
                                </span>
                            </div>

                            <div className="my-6 border-t border-gray-100" />

                            {/* CONTENT */}

                            <div className="max-w-3xl">
                                <p className="whitespace-pre-line text-sm leading-7 text-gray-700 sm:text-[15px]">
                                    {selectedAnnouncement.content}
                                </p>
                            </div>

                            {/* SOURCE */}

                            <div className="mt-7 rounded-xl border border-[#dce8d8] bg-[#f5f9f3] p-4">
                                <div className="flex items-start gap-3">

                                    <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-[#e1eddd] text-[#3f6f3d]">
                                        <BarangayIcon />
                                    </div>

                                    <div>
                                        <p className="text-sm font-semibold text-gray-800">
                                            Barangay San Jose
                                        </p>

                                        <p className="mt-1 text-xs leading-5 text-gray-500">
                                            This announcement was posted
                                            through the KaBarangay
                                            information system.
                                        </p>
                                    </div>

                                </div>
                            </div>

                        </div>
                    </article>

                </div>
            </ResidentLayout>
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Announcement List
    |--------------------------------------------------------------------------
    */

    return (
        <ResidentLayout active="announcements">
            <div className="mx-auto w-full max-w-7xl">

                {/* ========================================================
                    HEADER
                ======================================================== */}

                <div className="mb-5 sm:mb-6">
                    <h1 className="text-xl font-bold text-gray-900 sm:text-2xl">
                        Announcements
                    </h1>

                    <p className="mt-1 max-w-2xl text-sm leading-6 text-gray-500">
                        Stay updated with the latest announcements,
                        events, and public service information from
                        the barangay.
                    </p>
                </div>

                {/* ========================================================
                    IMPORTANT ANNOUNCEMENT
                ======================================================== */}

                {!search &&
                    filter === 'All' &&
                    importantAnnouncement && (
                        <ImportantAnnouncement
                            announcement={importantAnnouncement}
                            onOpen={() =>
                                setSelectedAnnouncement(
                                    importantAnnouncement,
                                )
                            }
                        />
                    )}

                {/* ========================================================
                    SEARCH + FILTERS
                ======================================================== */}

                <div className="mt-5 rounded-2xl border border-gray-200 bg-white p-3 shadow-sm sm:p-4">

                    {/* SEARCH */}

                    <div
                        className="
                            flex
                            h-11
                            w-full
                            items-center
                            gap-3
                            rounded-xl
                            border
                            border-gray-300
                            bg-white
                            px-4
                            transition
                            focus-within:border-[#3f6f3d]
                            focus-within:ring-2
                            focus-within:ring-[#3f6f3d]/15
                        "
                    >
                        {/* ICON - SEPARATE FROM INPUT */}

                        <div className="flex h-5 w-5 shrink-0 items-center justify-center text-gray-400">
                            <SearchIcon />
                        </div>

                        {/* INPUT */}

                        <input
                            type="search"
                            value={search}
                            onChange={(event) =>
                                setSearch(event.target.value)
                            }
                            placeholder="Search announcements..."
                            className="
                                h-full
                                min-w-0
                                flex-1
                                appearance-none
                                border-0
                                bg-transparent
                                p-0
                                text-base
                                text-gray-800
                                outline-none
                                ring-0
                                placeholder:text-gray-400
                                focus:border-0
                                focus:outline-none
                                focus:ring-0
                                sm:text-sm
                            "
                        />

                    </div>

                    {/* FILTERS */}

                    <div className="mt-3 overflow-x-auto pb-1">
                        <div className="flex min-w-max gap-2">

                            {(
                                [
                                    'All',
                                    'Important',
                                    'Events',
                                    'Services',
                                ] as FilterType[]
                            ).map((item) => (
                                <button
                                    key={item}
                                    type="button"
                                    onClick={() =>
                                        setFilter(item)
                                    }
                                    className={`min-h-9 rounded-full px-4 py-2 text-xs font-semibold transition ${
                                        filter === item
                                            ? 'bg-[#3f6f3d] text-white'
                                            : 'border border-gray-200 bg-white text-gray-600 hover:bg-gray-50'
                                    }`}
                                >
                                    {item}
                                </button>
                            ))}

                        </div>
                    </div>

                </div>

                {/* ========================================================
                    LIST HEADER
                ======================================================== */}

                <div className="mb-3 mt-6 flex items-center justify-between gap-3">
                    <div>
                        <h2 className="font-bold text-gray-900">
                            {filter === 'All'
                                ? 'Latest Announcements'
                                : filter}
                        </h2>

                        <p className="mt-0.5 text-xs text-gray-400">
                            {filteredAnnouncements.length}{' '}
                            {filteredAnnouncements.length === 1
                                ? 'announcement'
                                : 'announcements'}
                        </p>
                    </div>
                </div>

                {/* ========================================================
                    ANNOUNCEMENT CARDS
                ======================================================== */}

                {filteredAnnouncements.length > 0 ? (
                    <div className="grid gap-4 lg:grid-cols-2">

                        {filteredAnnouncements.map(
                            (announcement) => (
                                <AnnouncementCard
                                    key={announcement.id}
                                    announcement={announcement}
                                    onOpen={() => {
                                        setSelectedAnnouncement(
                                            announcement,
                                        );

                                        window.scrollTo({
                                            top: 0,
                                            behavior: 'smooth',
                                        });
                                    }}
                                />
                            ),
                        )}

                    </div>
                ) : (
                    <EmptyAnnouncements
                        search={search}
                        onReset={() => {
                            setSearch('');
                            setFilter('All');
                        }}
                    />
                )}

            </div>
        </ResidentLayout>
    );
}

/*
|--------------------------------------------------------------------------
| Important Announcement
|--------------------------------------------------------------------------
*/

function ImportantAnnouncement({
    announcement,
    onOpen,
}: {
    announcement: Announcement;
    onOpen: () => void;
}) {
    return (
        <div className="overflow-hidden rounded-2xl border border-amber-200 bg-amber-50 shadow-sm">
            <div className="p-4 sm:p-5">

                <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">

                    <div className="flex min-w-0 items-start gap-3">

                        <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-amber-100 text-amber-700">
                            <AlertIcon />
                        </div>

                        <div className="min-w-0">

                            <div className="flex flex-wrap items-center gap-2">

                                <span className="text-xs font-bold uppercase tracking-wide text-amber-700">
                                    Important Announcement
                                </span>

                                <span className="text-xs text-amber-600">
                                    •
                                </span>

                                <span className="text-xs text-amber-700">
                                    {announcement.date}
                                </span>

                            </div>

                            <h2 className="mt-1 font-bold text-gray-900 sm:text-lg">
                                {announcement.title}
                            </h2>

                            <p className="mt-1 line-clamp-2 max-w-3xl text-sm leading-6 text-gray-600">
                                {announcement.preview}
                            </p>

                        </div>

                    </div>

                    <button
                        type="button"
                        onClick={onOpen}
                        className="
                            min-h-10
                            shrink-0
                            rounded-xl
                            border
                            border-amber-300
                            bg-white
                            px-4
                            py-2
                            text-sm
                            font-semibold
                            text-amber-800
                            transition
                            hover:bg-amber-100
                        "
                    >
                        Read More
                    </button>

                </div>

            </div>
        </div>
    );
}

/*
|--------------------------------------------------------------------------
| Announcement Card
|--------------------------------------------------------------------------
*/

function AnnouncementCard({
    announcement,
    onOpen,
}: {
    announcement: Announcement;
    onOpen: () => void;
}) {
    return (
        <article className="group flex h-full flex-col rounded-2xl border border-gray-200 bg-white p-4 shadow-sm transition hover:border-gray-300 hover:shadow-md sm:p-5">

            <div className="flex items-start justify-between gap-3">

                <CategoryBadge
                    category={announcement.category}
                />

                {announcement.important && (
                    <div
                        className="text-amber-500"
                        title="Important announcement"
                    >
                        <PinIcon />
                    </div>
                )}

            </div>

            <div className="mt-4 flex-1">

                <h3 className="text-base font-bold leading-6 text-gray-900">
                    {announcement.title}
                </h3>

                <div className="mt-2 flex items-center gap-1.5 text-xs text-gray-400">
                    <CalendarIcon />

                    <span>
                        {announcement.date}
                    </span>
                </div>

                <p className="mt-3 line-clamp-3 text-sm leading-6 text-gray-600">
                    {announcement.preview}
                </p>

            </div>

            <div className="mt-5 border-t border-gray-100 pt-4">

                <button
                    type="button"
                    onClick={onOpen}
                    className="
                        inline-flex
                        min-h-10
                        items-center
                        gap-2
                        text-sm
                        font-semibold
                        text-[#3f6f3d]
                        transition
                        hover:text-[#315b30]
                    "
                >
                    Read More
                    <ArrowIcon />
                </button>

            </div>

        </article>
    );
}

/*
|--------------------------------------------------------------------------
| Category Badge
|--------------------------------------------------------------------------
*/

function CategoryBadge({
    category,
}: {
    category: AnnouncementCategory;
}) {
    const styles: Record<
        AnnouncementCategory,
        string
    > = {
        Important:
            'border-amber-200 bg-amber-50 text-amber-700',

        Events:
            'border-purple-200 bg-purple-50 text-purple-700',

        Services:
            'border-blue-200 bg-blue-50 text-blue-700',
    };

    return (
        <span
            className={`inline-flex w-fit items-center rounded-full border px-2.5 py-1 text-[11px] font-bold ${styles[category]}`}
        >
            {category}
        </span>
    );
}

/*
|--------------------------------------------------------------------------
| Empty Search
|--------------------------------------------------------------------------
*/

function EmptyAnnouncements({
    search,
    onReset,
}: {
    search: string;
    onReset: () => void;
}) {
    return (
        <div className="rounded-2xl border border-gray-200 bg-white px-5 py-12 text-center shadow-sm">

            <div className="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-gray-100 text-gray-400">
                <SearchLargeIcon />
            </div>

            <h3 className="mt-4 font-bold text-gray-900">
                No announcements found
            </h3>

            <p className="mx-auto mt-2 max-w-md text-sm leading-6 text-gray-500">
                {search
                    ? `We couldn't find an announcement matching "${search}".`
                    : 'There are no announcements under this category.'}
            </p>

            <button
                type="button"
                onClick={onReset}
                className="
                    mt-5
                    min-h-10
                    rounded-xl
                    border
                    border-gray-300
                    bg-white
                    px-4
                    py-2
                    text-sm
                    font-semibold
                    text-gray-700
                    transition
                    hover:bg-gray-50
                "
            >
                View All Announcements
            </button>

        </div>
    );
}

/*
|--------------------------------------------------------------------------
| Icons
|--------------------------------------------------------------------------
*/

function SearchIcon() {
    return (
        <svg
            viewBox="0 0 24 24"
            fill="none"
            className="block h-5 w-5"
            stroke="currentColor"
            strokeWidth="2"
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
        >
            <circle
                cx="11"
                cy="11"
                r="7"
            />

            <path d="m20 20-3.5-3.5" />
        </svg>
    );
}

function SearchLargeIcon() {
    return (
        <svg
            viewBox="0 0 24 24"
            fill="none"
            className="h-6 w-6"
            stroke="currentColor"
            strokeWidth="2"
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
        >
            <circle
                cx="11"
                cy="11"
                r="7"
            />

            <path d="m20 20-3.5-3.5" />
        </svg>
    );
}

function CalendarIcon() {
    return (
        <svg
            viewBox="0 0 24 24"
            fill="none"
            className="h-4 w-4 shrink-0"
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
                height="16"
                rx="2"
            />

            <path d="M16 3v4" />
            <path d="M8 3v4" />
            <path d="M3 11h18" />
        </svg>
    );
}

function AlertIcon() {
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
            <path d="M10.3 2.9 1.8 17a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 2.9a2 2 0 0 0-3.4 0Z" />
            <path d="M12 9v4" />
            <path d="M12 17h.01" />
        </svg>
    );
}

function PinIcon() {
    return (
        <svg
            viewBox="0 0 24 24"
            fill="none"
            className="h-4 w-4"
            stroke="currentColor"
            strokeWidth="2"
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
        >
            <path d="m12 17-5 5" />
            <path d="M9 3 7 5l4 4-3 3 4 4 3-3 4 4 2-2" />
        </svg>
    );
}

function ArrowIcon() {
    return (
        <svg
            viewBox="0 0 24 24"
            fill="none"
            className="h-4 w-4"
            stroke="currentColor"
            strokeWidth="2"
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
        >
            <path d="M5 12h14" />
            <path d="m13 6 6 6-6 6" />
        </svg>
    );
}

function BackIcon() {
    return (
        <svg
            viewBox="0 0 24 24"
            fill="none"
            className="h-4 w-4"
            stroke="currentColor"
            strokeWidth="2"
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
        >
            <path d="m15 18-6-6 6-6" />
        </svg>
    );
}

function BarangayIcon() {
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
            <path d="M3 21h18" />
            <path d="M5 21V9l7-5 7 5v12" />
            <path d="M9 21v-6h6v6" />
        </svg>
    );
}