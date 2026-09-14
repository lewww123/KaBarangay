import KaBarangayAssistant from '@/components/resident/KaBarangayAssistant';
import { Link, router } from '@inertiajs/react';
import { ReactNode, useEffect, useState } from 'react';

type ResidentPage =
    | 'home'
    | 'certificates'
    | 'lupon'
    | 'announcements'
    | 'disbursement'
    | 'charter'
    | 'profile'
    | 'settings'
    | 'about';

type ResidentLayoutProps = {
    children: ReactNode;
    active?: ResidentPage;
};

export default function ResidentLayout({
    children,
    active = 'home',
}: ResidentLayoutProps) {
    const [mobileMenuOpen, setMobileMenuOpen] = useState(false);

    /*
    |--------------------------------------------------------------------------
    | Close Mobile Menu on Desktop
    |--------------------------------------------------------------------------
    */

    useEffect(() => {
        const handleResize = () => {
            if (window.innerWidth >= 1024) {
                setMobileMenuOpen(false);
            }
        };

        window.addEventListener('resize', handleResize);

        return () => {
            window.removeEventListener('resize', handleResize);
        };
    }, []);

    /*
    |--------------------------------------------------------------------------
    | Prevent Background Scroll While Mobile Menu is Open
    |--------------------------------------------------------------------------
    */

    useEffect(() => {
        if (mobileMenuOpen) {
            document.body.style.overflow = 'hidden';
        } else {
            document.body.style.overflow = '';
        }

        return () => {
            document.body.style.overflow = '';
        };
    }, [mobileMenuOpen]);

    /*
    |--------------------------------------------------------------------------
    | Logout
    |--------------------------------------------------------------------------
    */

    const handleLogout = () => {
        const confirmed = window.confirm(
            'Are you sure you want to log out?',
        );

        if (!confirmed) {
            return;
        }

        router.post('/logout');
    };

    return (
        <div className="min-h-screen bg-[#f7f6f0] text-gray-800">

            {/* ============================================================
                TOP HEADER
            ============================================================ */}

            <header className="sticky top-0 z-40 flex min-h-16 items-center justify-between bg-[#3f6f3d] px-3 py-3 text-white shadow-sm sm:px-5 lg:px-6">

                {/* LEFT SIDE */}

                <div className="flex min-w-0 items-center gap-2 sm:gap-3">

                    {/* MOBILE MENU BUTTON */}

                    <button
                        type="button"
                        onClick={() =>
                            setMobileMenuOpen((current) => !current)
                        }
                        className="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg bg-white/10 transition hover:bg-white/20 active:scale-95 lg:hidden"
                        aria-label="Open navigation menu"
                    >
                        <MenuIcon />
                    </button>

                    {/* BARANGAY LOGO */}

                    <div className="hidden h-10 w-10 shrink-0 items-center justify-center rounded-full bg-white text-sm font-bold text-[#3f6f3d] sm:flex">
                        SJ
                    </div>

                    {/* TITLE */}

                    <div className="min-w-0">

                        <p className="truncate text-base font-semibold sm:text-lg">
                            KaBarangay
                        </p>

                        <p className="truncate text-[11px] text-white/80 sm:text-xs">
                            Barangay San Jose
                        </p>

                    </div>

                </div>

                {/* RIGHT SIDE */}

                <Link
                    href="/resident/profile"
                    className="flex min-w-0 items-center gap-2 rounded-lg px-1.5 py-1.5 transition hover:bg-white/10 sm:gap-3 sm:px-2"
                >

                    <div className="hidden text-right sm:block">

                        <p className="text-sm font-medium">
                            My Account
                        </p>

                        <p className="text-xs text-white/75">
                            Resident
                        </p>

                    </div>

                    <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-[#315b30]">
                        <ProfileIcon />
                    </div>

                </Link>

            </header>

            {/* ============================================================
                BODY
            ============================================================ */}

            <div className="flex">

                {/* ========================================================
                    DESKTOP SIDEBAR
                ======================================================== */}

                <aside className="sticky top-16 hidden h-[calc(100vh-64px)] w-64 shrink-0 overflow-y-auto border-r border-gray-200 bg-white lg:block">

                    <ResidentNavigation
                        active={active}
                        onLogout={handleLogout}
                    />

                </aside>

                {/* ========================================================
                    MOBILE OVERLAY
                ======================================================== */}

                {mobileMenuOpen && (
                    <button
                        type="button"
                        aria-label="Close navigation menu"
                        className="fixed inset-0 top-16 z-40 bg-black/40 lg:hidden"
                        onClick={() => setMobileMenuOpen(false)}
                    />
                )}

                {/* ========================================================
                    MOBILE DRAWER
                ======================================================== */}

                <aside
                    className={`fixed bottom-0 left-0 top-16 z-50 w-[86%] max-w-[330px] overflow-y-auto bg-white shadow-2xl transition-transform duration-300 ease-out lg:hidden ${
                        mobileMenuOpen
                            ? 'translate-x-0'
                            : '-translate-x-full'
                    }`}
                >

                    {/* DRAWER HEADER */}

                    <div className="sticky top-0 z-10 flex items-center justify-between border-b border-gray-100 bg-white px-5 py-4">

                        <div>

                            <p className="text-base font-semibold text-gray-800">
                                Resident Services
                            </p>

                            <p className="mt-0.5 text-sm text-gray-500">
                                KaBarangay
                            </p>

                        </div>

                        <button
                            type="button"
                            onClick={() => setMobileMenuOpen(false)}
                            className="flex h-10 w-10 items-center justify-center rounded-full text-gray-500 transition hover:bg-gray-100 active:scale-95"
                            aria-label="Close navigation menu"
                        >
                            <CloseIcon />
                        </button>

                    </div>

                    <ResidentNavigation
                        active={active}
                        onNavigate={() => setMobileMenuOpen(false)}
                        onLogout={handleLogout}
                        mobile
                    />

                </aside>

                {/* ========================================================
                    MAIN PAGE CONTENT
                ======================================================== */}

                <main className="min-w-0 flex-1">

                    <div className="mx-auto w-full max-w-[1500px] p-4 pb-5 sm:p-6 lg:p-8">
                        {children}
                    </div>

                </main>

            </div>

            {/* ============================================================
                KABARANGAY ASSISTANT
            ============================================================ */}

            <KaBarangayAssistant />

            {/* ============================================================
                MOBILE BOTTOM NAVIGATION
            ============================================================ */}

            <nav className="fixed bottom-0 left-0 right-0 z-30 border-t border-gray-200 bg-white shadow-[0_-2px_10px_rgba(0,0,0,0.06)] lg:hidden">

                <div className="mx-auto grid max-w-lg grid-cols-4">

                    {/* HOME */}

                    <BottomNavItem
                        href="/resident"
                        icon={<HomeIcon />}
                        label="Home"
                        active={active === 'home'}
                    />

                    {/* CERTIFICATES */}

                    <BottomNavItem
                        href="/resident/certificates"
                        icon={<CertificateIcon />}
                        label="Certificate"
                        active={active === 'certificates'}
                    />

                    {/* ANNOUNCEMENTS */}

                    <BottomNavItem
                        href="/resident/announcements"
                        icon={<AnnouncementIcon />}
                        label="News"
                        active={active === 'announcements'}
                    />

                    {/* MORE */}

                    <button
                        type="button"
                        onClick={() => setMobileMenuOpen(true)}
                        className={`flex min-h-[68px] flex-col items-center justify-center gap-1 px-1 py-2 transition active:bg-gray-50 ${
                            mobileMenuOpen
                                ? 'text-[#3f6f3d]'
                                : 'text-gray-500'
                        }`}
                    >

                        <span
                            className={`flex h-8 w-10 items-center justify-center rounded-full ${
                                mobileMenuOpen
                                    ? 'bg-[#e8f1e4]'
                                    : ''
                            }`}
                        >
                            <MoreIcon />
                        </span>

                        <span className="text-[11px] font-medium sm:text-xs">
                            More
                        </span>

                    </button>

                </div>

            </nav>

            {/* ============================================================
                MOBILE BOTTOM NAV SPACING
            ============================================================ */}

            <div className="h-[76px] lg:hidden" />

        </div>
    );
}

/*
|--------------------------------------------------------------------------
| Resident Navigation
|--------------------------------------------------------------------------
*/

function ResidentNavigation({
    active,
    onNavigate,
    onLogout,
    mobile = false,
}: {
    active: ResidentPage;
    onNavigate?: () => void;
    onLogout: () => void;
    mobile?: boolean;
}) {
    return (
        <div className="px-3 py-5">

            {/* ============================================================
                MAIN SERVICES
            ============================================================ */}

            <NavigationTitle>
                Main Services
            </NavigationTitle>

            <nav className="space-y-1">

                <ResidentLink
                    href="/resident"
                    icon={<HomeIcon />}
                    label="Home"
                    active={active === 'home'}
                    onClick={onNavigate}
                />

                <ResidentLink
                    href="/resident/certificates"
                    icon={<CertificateIcon />}
                    label="Request Certificate"
                    active={active === 'certificates'}
                    onClick={onNavigate}
                />

                <ResidentLink
                    href="/resident/lupon"
                    icon={<ComplaintIcon />}
                    label="File a Complaint"
                    active={active === 'lupon'}
                    onClick={onNavigate}
                />

                <ResidentLink
                    href="/resident/announcements"
                    icon={<AnnouncementIcon />}
                    label="Announcements"
                    active={active === 'announcements'}
                    onClick={onNavigate}
                />

                <ResidentLink
                    href="/resident/disbursement"
                    icon={<PesoIcon />}
                    label="Disbursement"
                    active={active === 'disbursement'}
                    onClick={onNavigate}
                />

            </nav>

            {/* ============================================================
                INFORMATION
            ============================================================ */}

            <div className="mt-7">

                <NavigationTitle>
                    Information
                </NavigationTitle>

                <nav className="space-y-1">

                    <ResidentLink
                        href="/resident/citizen-charter"
                        icon={<CharterIcon />}
                        label="Citizen's Charter"
                        active={active === 'charter'}
                        onClick={onNavigate}
                    />

                </nav>

            </div>

            {/* ============================================================
                ACCOUNT
            ============================================================ */}

            <div className="mt-7">

                <NavigationTitle>
                    Account
                </NavigationTitle>

                <nav className="space-y-1">

                    <ResidentLink
                        href="/resident/profile"
                        icon={<ProfileIcon />}
                        label="My Profile"
                        active={active === 'profile'}
                        onClick={onNavigate}
                    />

                    <ResidentLink
                        href="/resident/settings"
                        icon={<SettingsIcon />}
                        label="Settings"
                        active={active === 'settings'}
                        onClick={onNavigate}
                    />

                    <ResidentLink
                        href="/resident/about"
                        icon={<InfoIcon />}
                        label="About"
                        active={active === 'about'}
                        onClick={onNavigate}
                    />

                </nav>

            </div>

            {/* ============================================================
                LOGOUT
            ============================================================ */}

            <div className="mt-7 border-t border-gray-100 pt-4">

                <button
                    type="button"
                    onClick={onLogout}
                    className="flex min-h-12 w-full items-center gap-3 rounded-lg px-4 py-3 text-left text-base font-medium text-red-600 transition hover:bg-red-50 active:bg-red-100"
                >

                    <span className="flex w-6 shrink-0 items-center justify-center">
                        <LogoutIcon />
                    </span>

                    <span>
                        Logout
                    </span>

                </button>

            </div>

            {/* ============================================================
                MOBILE ASSISTANT NOTE
            ============================================================ */}

            {mobile && (
                <div className="mt-5 rounded-xl border border-[#dce8d8] bg-[#f4f8f2] p-4">

                    <div className="flex items-start gap-3">

                        <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-[#dcebd8] text-[#3f6f3d]">
                            <ChatSmallIcon />
                        </div>

                        <div>

                            <p className="text-sm font-semibold text-[#3f6f3d]">
                                Need help?
                            </p>

                            <p className="mt-1 text-sm leading-6 text-gray-600">
                                Tap the chat button to talk to
                                KaBarangay Assistant.
                            </p>

                        </div>

                    </div>

                </div>
            )}

        </div>
    );
}

/*
|--------------------------------------------------------------------------
| Navigation Title
|--------------------------------------------------------------------------
*/

function NavigationTitle({
    children,
}: {
    children: ReactNode;
}) {
    return (
        <p className="mb-2 px-4 text-xs font-semibold uppercase tracking-wide text-gray-400">
            {children}
        </p>
    );
}

/*
|--------------------------------------------------------------------------
| Sidebar / Drawer Link
|--------------------------------------------------------------------------
*/

function ResidentLink({
    href,
    icon,
    label,
    active,
    onClick,
}: {
    href: string;
    icon: ReactNode;
    label: string;
    active: boolean;
    onClick?: () => void;
}) {
    return (
        <Link
            href={href}
            onClick={onClick}
            className={`flex min-h-12 items-center gap-3 rounded-lg px-4 py-3 text-base font-medium transition active:scale-[0.99] ${
                active
                    ? 'bg-[#e8f1e4] text-[#3f6f3d]'
                    : 'text-gray-600 hover:bg-gray-100 hover:text-gray-800'
            }`}
        >

            <span className="flex w-6 shrink-0 items-center justify-center">
                {icon}
            </span>

            <span className="min-w-0 flex-1">
                {label}
            </span>

        </Link>
    );
}

/*
|--------------------------------------------------------------------------
| Mobile Bottom Navigation Item
|--------------------------------------------------------------------------
*/

function BottomNavItem({
    href,
    icon,
    label,
    active,
}: {
    href: string;
    icon: ReactNode;
    label: string;
    active: boolean;
}) {
    return (
        <Link
            href={href}
            className={`flex min-h-[68px] min-w-0 flex-col items-center justify-center gap-1 px-1 py-2 transition active:bg-gray-50 ${
                active
                    ? 'text-[#3f6f3d]'
                    : 'text-gray-500'
            }`}
        >

            <span
                className={`flex h-8 w-10 items-center justify-center rounded-full ${
                    active
                        ? 'bg-[#e8f1e4]'
                        : ''
                }`}
            >
                {icon}
            </span>

            <span className="max-w-full truncate px-1 text-[11px] font-medium sm:text-xs">
                {label}
            </span>

        </Link>
    );
}

/*
|--------------------------------------------------------------------------
| Icons
|--------------------------------------------------------------------------
*/

function MenuIcon() {
    return (
        <svg
            viewBox="0 0 24 24"
            fill="none"
            className="h-6 w-6"
            stroke="currentColor"
            strokeWidth="2"
            strokeLinecap="round"
            aria-hidden="true"
        >
            <path d="M4 6h16" />
            <path d="M4 12h16" />
            <path d="M4 18h16" />
        </svg>
    );
}

function HomeIcon() {
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
            <path d="m3 10 9-7 9 7" />
            <path d="M5 9.5V21h14V9.5" />
            <path d="M9 21v-7h6v7" />
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

function CharterIcon() {
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
            <rect
                x="5"
                y="3"
                width="14"
                height="18"
                rx="2"
            />
            <path d="M9 8h6" />
            <path d="M9 12h6" />
            <path d="M9 16h4" />
        </svg>
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

function SettingsIcon() {
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
                r="3"
            />
            <path d="M12 2.5v3" />
            <path d="M12 18.5v3" />
            <path d="M2.5 12h3" />
            <path d="M18.5 12h3" />
            <path d="m5.3 5.3 2.1 2.1" />
            <path d="m16.6 16.6 2.1 2.1" />
            <path d="m18.7 5.3-2.1 2.1" />
            <path d="m7.4 16.6-2.1 2.1" />
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
            <path d="M12 11v6" />
            <path d="M12 7h.01" />
        </svg>
    );
}

function LogoutIcon() {
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
            <path d="M10 5H5v14h5" />
            <path d="M13 8l4 4-4 4" />
            <path d="M17 12H9" />
        </svg>
    );
}

function MoreIcon() {
    return (
        <svg
            viewBox="0 0 24 24"
            fill="currentColor"
            className="h-5 w-5"
            aria-hidden="true"
        >
            <circle
                cx="5"
                cy="12"
                r="1.7"
            />
            <circle
                cx="12"
                cy="12"
                r="1.7"
            />
            <circle
                cx="19"
                cy="12"
                r="1.7"
            />
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

function ChatSmallIcon() {
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
            <path d="M21 12a8 8 0 0 1-8 8H6l-4 2 1.4-4.2A8 8 0 1 1 21 12Z" />
            <path d="M8 12h.01" />
            <path d="M12 12h.01" />
            <path d="M16 12h.01" />
        </svg>
    );
}