import { Head, Link } from '@inertiajs/react';
import ResidentLayout from '@/layouts/ResidentLayout';

/*
|--------------------------------------------------------------------------
| KaBarangay Assistant Guide
|--------------------------------------------------------------------------
|
| This page reuses the content of the floating KaBarangay Assistant that is
| available on every Resident Portal screen. The assistant is a scripted,
| rule-based guide (not an AI service), so this page labels it as such and
| points residents to the actual Resident Portal pages for each task.
|
*/

interface GuideTopic {
    title: string;
    description: string;
    steps: string[];
    href?: string;
    actionLabel?: string;
}

const guideTopics: GuideTopic[] = [
    {
        title: 'Request a Certificate',
        description:
            'The assistant can walk you through choosing a certificate and submitting a request.',
        steps: [
            'Open the Request Certificate page.',
            'Choose the certificate you need.',
            'Provide the purpose and any required details.',
            'Submit the request and keep the reference number.',
        ],
        href: '/resident/certificates',
        actionLabel: 'Go to Request Certificate',
    },
    {
        title: 'Track a Certificate Request',
        description:
            'The assistant explains the status of a request so you know what happens next.',
        steps: [
            'Open the Request Certificate page.',
            'Find your request in the list.',
            'Read the current status and its meaning.',
        ],
        href: '/resident/certificates',
        actionLabel: 'View My Requests',
    },
    {
        title: 'Certificate Requirements',
        description:
            'The assistant lists the requirement prompts for a certificate request. The exact requirements and fees are shown on the Request Certificate page.',
        steps: [
            'Open the Request Certificate page.',
            'Select a certificate to see its requirements and fee.',
            'Prepare the required details before submitting.',
        ],
        href: '/resident/certificates',
        actionLabel: 'See Requirements',
    },
    {
        title: 'File a Complaint',
        description:
            'The assistant explains how to file a complaint and how to follow its progress.',
        steps: [
            'Open the Lupon page.',
            'Provide the complaint details requested by the form.',
            'Submit the complaint and monitor its status.',
        ],
        href: '/resident/lupon',
        actionLabel: 'Go to Lupon',
    },
];

export default function Chatbot() {
    return (
        <ResidentLayout active="chatbot">
            <Head title="Assistant Guide" />

            <div className="mx-auto w-full max-w-4xl">
                <div className="mb-5 sm:mb-6">
                    <h1 className="text-xl font-bold text-gray-900 sm:text-2xl">
                        KaBarangay Assistant Guide
                    </h1>

                    <p className="mt-1 max-w-2xl text-sm leading-6 text-gray-500">
                        A scripted guide for common Resident Portal tasks.
                    </p>
                </div>

                <div className="rounded-2xl border border-[#dce8d8] bg-[#f5f9f3] p-4 sm:p-5">
                    <div className="flex items-start gap-3">
                        <div className="mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-white text-[#3f6f3d] shadow-sm">
                            <InfoIcon />
                        </div>

                        <p className="text-sm leading-6 text-gray-600">
                            KaBarangay Assistant is a <strong>scripted guide</strong>,
                            not a live AI service. It answers using a fixed set of
                            topics about barangay services. The floating assistant
                            button is available on every Resident Portal screen, and
                            this page lists the same topics so you can jump straight
                            to the right page.
                        </p>
                    </div>
                </div>

                <div className="mt-4 space-y-4">
                    {guideTopics.map((topic) => (
                        <div
                            key={topic.title}
                            className="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm sm:p-6"
                        >
                            <h2 className="text-base font-bold text-gray-900">
                                {topic.title}
                            </h2>

                            <p className="mt-1 text-sm leading-6 text-gray-500">
                                {topic.description}
                            </p>

                            <ol className="mt-3 space-y-2">
                                {topic.steps.map((step, index) => (
                                    <li
                                        key={step}
                                        className="flex items-start gap-3 text-sm text-gray-600"
                                    >
                                        <span className="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-[#e8f1e4] text-[11px] font-bold text-[#3f6f3d]">
                                            {index + 1}
                                        </span>
                                        <span>{step}</span>
                                    </li>
                                ))}
                            </ol>

                            {topic.href && (
                                <Link
                                    href={topic.href}
                                    className="mt-4 inline-flex items-center gap-2 rounded-lg bg-[#3f6f3d] px-4 py-2 text-xs font-semibold text-white transition hover:bg-[#315b30]"
                                >
                                    {topic.actionLabel}
                                </Link>
                            )}
                        </div>
                    ))}
                </div>

                <div className="mt-4 rounded-2xl border border-gray-200 bg-white p-5 shadow-sm sm:p-6">
                    <h2 className="text-base font-bold text-gray-900">
                        Need something else?
                    </h2>
                    <p className="mt-1 text-sm leading-6 text-gray-500">
                        For services not covered by the assistant, please visit
                        the barangay office. You can also review the Citizen&apos;s
                        Charter for the list of barangay services.
                    </p>
                    <Link
                        href="/resident/citizen-charter"
                        className="mt-4 inline-flex items-center gap-2 rounded-lg border border-gray-300 px-4 py-2 text-xs font-semibold text-gray-700 transition hover:bg-gray-50"
                    >
                        View Citizen&apos;s Charter
                    </Link>
                </div>
            </div>
        </ResidentLayout>
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
            <circle cx="12" cy="12" r="9" />
            <path d="M12 10v6" />
            <path d="M12 7h.01" />
        </svg>
    );
}
