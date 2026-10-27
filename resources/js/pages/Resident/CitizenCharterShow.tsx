import { Head, Link } from '@inertiajs/react';
import {
    formatCharterDate,
    formatFileSize,
    type CitizenCharterServiceData,
} from '@/components/citizen-charter/charter';
import ResidentLayout from '@/layouts/ResidentLayout';

type Props = {
    service: CitizenCharterServiceData;
    resident: {
        full_name: string;
    };
};

export default function ResidentCitizenCharterShow({ service }: Props) {
    const version = service.current_version;

    return (
        <ResidentLayout active="charter">
            <Head title={`${service.title} | Citizen's Charter`} />

            <div className="mx-auto w-full max-w-5xl space-y-6">

                {/* BREADCRUMB */}
                <div className="flex items-center gap-2 text-xs text-gray-500">
                    <Link href="/resident/citizen-charter" className="hover:text-[#3f6f3d]">
                        Citizen&apos;s Charter
                    </Link>
                    <span>/</span>
                    <span className="font-semibold text-gray-800 line-clamp-1">{service.title}</span>
                </div>

                {/* SERVICE HEADER CARD */}
                <div className="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                    <div className="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
                        <div className="space-y-2">
                            <div className="flex items-center gap-2">
                                <span className="inline-flex rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-bold text-emerald-800 border border-emerald-200">
                                    {service.category || 'Barangay Service'}
                                </span>
                                {version && (
                                    <span className="text-xs text-gray-400">
                                        Official Document ({formatFileSize(version.file_size)})
                                    </span>
                                )}
                            </div>

                            <h1 className="text-xl font-bold text-gray-900 sm:text-2xl">
                                {service.title}
                            </h1>

                            {service.description && (
                                <p className="text-sm leading-relaxed text-gray-600 max-w-3xl">
                                    {service.description}
                                </p>
                            )}

                            <div className="pt-1 text-xs text-gray-400">
                                Published standard as of {formatCharterDate(service.published_at)}
                            </div>
                        </div>

                        {/* ACTIONS */}
                        <div className="flex flex-wrap items-center gap-2 shrink-0">
                            {service.file_url && (
                                <a
                                    href={service.file_url}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    className="rounded-xl border border-gray-300 px-4 py-2 text-xs font-semibold text-gray-700 transition hover:bg-gray-100"
                                >
                                    Open in New Tab ↗
                                </a>
                            )}
                            {service.download_url && (
                                <a
                                    href={service.download_url}
                                    className="inline-flex items-center gap-2 rounded-xl bg-[#3f6f3d] px-4 py-2 text-xs font-semibold text-white shadow-sm transition hover:bg-[#315b30]"
                                >
                                    <span>Download PDF</span>
                                    <span>↓</span>
                                </a>
                            )}
                        </div>
                    </div>
                </div>

                {/* PDF VIEWER SECTION */}
                <div className="space-y-3">
                    <div className="flex items-center justify-between">
                        <h2 className="text-sm font-bold text-gray-900">
                            Official Charter Document
                        </h2>
                        <span className="text-xs text-gray-400">
                            Authorized Barangay Service Standard
                        </span>
                    </div>

                    <div className="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm">
                        {service.file_url ? (
                            <iframe
                                src={service.file_url}
                                className="h-[650px] w-full border-0"
                                title={`Citizen's Charter PDF - ${service.title}`}
                            />
                        ) : (
                            <div className="p-12 text-center text-xs text-gray-400">
                                Document file is currently unavailable.
                            </div>
                        )}
                    </div>

                    {/* MOBILE FALLBACK NOTE */}
                    <div className="rounded-xl border border-gray-200 bg-gray-50/70 p-3.5 text-xs text-gray-500">
                        <span className="font-semibold text-gray-700">Mobile notice:</span> If your device or browser cannot display embedded PDF previews, use the{' '}
                        <a href={service.download_url || '#'} className="font-semibold text-[#3f6f3d] underline">
                            Download PDF
                        </a>{' '}
                        or{' '}
                        <a href={service.file_url || '#'} target="_blank" rel="noopener noreferrer" className="font-semibold text-[#3f6f3d] underline">
                            Open in New Tab
                        </a>{' '}
                        button above to view the complete official document.
                    </div>
                </div>

            </div>
        </ResidentLayout>
    );
}
