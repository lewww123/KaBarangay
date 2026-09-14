import { Head, Link } from '@inertiajs/react';
import ResidentLayout from '@/layouts/ResidentLayout';

export default function Dashboard() {
    return (
        <>
            <Head title="Home" />

            <ResidentLayout active="home">

                {/* WELCOME */}
                <section className="mb-7">
                    <h1 className="text-2xl font-semibold text-gray-800 sm:text-3xl">
                        Welcome to KaBarangay
                    </h1>

                    <p className="mt-2 text-base text-gray-500">
                        How can we help you today?
                    </p>
                </section>


                {/* MAIN SERVICES */}
                <section>
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">

                        <ServiceCard
                            href="/resident/certificates"
                            icon="📄"
                            title="Request Certificate"
                        />

                        <ServiceCard
                            href="/resident/lupon"
                            icon="⚖️"
                            title="File a Complaint"
                        />

                        <ServiceCard
                            href="/resident/chatbot"
                            icon="💬"
                            title="Ask KaBarangay"
                        />

                    </div>
                </section>


                {/* LATEST ANNOUNCEMENT */}
                <section className="mt-8">

                    <div className="mb-3 flex items-center justify-between">

                        <h2 className="text-lg font-semibold text-gray-800">
                            Latest Announcement
                        </h2>

                        <Link
                            href="/resident/announcements"
                            className="text-sm font-medium text-[#3f6f3d] hover:underline"
                        >
                            View All
                        </Link>

                    </div>


                    <Link
                        href="/resident/announcements"
                        className="flex items-center gap-4 rounded-xl border border-gray-200 bg-white p-4 transition hover:border-[#3f6f3d] hover:shadow-sm"
                    >

                        <div className="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-[#edf4ea] text-xl">
                            📢
                        </div>

                        <div className="min-w-0">

                            <p className="font-semibold text-gray-800">
                                Barangay Clean-Up Drive
                            </p>

                            <p className="mt-1 text-sm text-gray-500">
                                September 5, 2026
                            </p>

                        </div>

                    </Link>

                </section>

            </ResidentLayout>
        </>
    );
}


/*
|--------------------------------------------------------------------------
| Service Card
|--------------------------------------------------------------------------
*/

function ServiceCard({
    href,
    icon,
    title,
}: {
    href: string;
    icon: string;
    title: string;
}) {
    return (
        <Link
            href={href}
            className="flex min-h-[135px] flex-col items-center justify-center rounded-xl border border-gray-200 bg-white p-4 text-center transition hover:border-[#3f6f3d] hover:bg-[#fafcf9] hover:shadow-sm"
        >

            <div className="flex h-12 w-12 items-center justify-center rounded-full bg-[#edf4ea] text-2xl">
                {icon}
            </div>

            <p className="mt-3 text-base font-semibold text-gray-800">
                {title}
            </p>

        </Link>
    );
}