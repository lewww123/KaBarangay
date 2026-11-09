import { Head, Link } from '@inertiajs/react';
import { Download, ExternalLink } from 'lucide-react';
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

            <div className="mx-auto w-full max-w-4xl space-y-5">
                {/* BREADCRUMB */}
                <div className="flex items-center gap-2 text-xs text-ink-muted">
                    <Link
                        href="/resident/citizen-charter"
                        className="hover:text-brand"
                    >
                        Citizen&apos;s Charter
                    </Link>
                    <span>/</span>
                    <span className="line-clamp-1 font-semibold text-ink-strong">
                        {service.title}
                    </span>
                </div>

                {/* SERVICE HEADER CARD */}
                <div className="rounded-xl border border-line-soft bg-white p-5 shadow-card">
                    <div className="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
                        <div className="space-y-2">
                            <div className="flex items-center gap-2">
                                <span className="inline-flex rounded-full border border-emerald-200 bg-emerald-50 px-2.5 py-0.5 text-xs font-semibold text-emerald-800">
                                    {service.category || 'Barangay Service'}
                                </span>
                                {version && (
                                    <span className="text-xs text-ink-faint">
                                        Official Document (
                                        {formatFileSize(version.file_size)})
                                    </span>
                                )}
                            </div>

                            <h1 className="text-xl font-semibold tracking-tight text-ink-strong sm:text-2xl">
                                {service.title}
                            </h1>

                            {service.description && (
                                <p className="max-w-3xl text-sm leading-relaxed text-ink-muted">
                                    {service.description}
                                </p>
                            )}

                            <div className="pt-1 text-xs text-ink-faint">
                                Published standard as of{' '}
                                {formatCharterDate(service.published_at)}
                            </div>
                        </div>

                        {/* ACTIONS */}
                        <div className="flex shrink-0 flex-wrap items-center gap-2">
                            {service.file_url && (
                                <a
                                    href={service.file_url}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    className="inline-flex items-center gap-1.5 rounded-xl border border-line px-4 py-2 text-xs font-semibold text-ink transition hover:bg-secondary"
                                >
                                    Open in New Tab
                                    <ExternalLink className="size-3.5" />
                                </a>
                            )}
                            {service.download_url && (
                                <a
                                    href={service.download_url}
                                    className="inline-flex items-center gap-2 rounded-xl bg-brand px-4 py-2 text-xs font-semibold text-white shadow-card transition hover:bg-brand-deep"
                                >
                                    <span>Download PDF</span>
                                    <Download className="size-3.5" />
                                </a>
                            )}
                        </div>
                    </div>
                </div>

                {/* PDF VIEWER SECTION */}
                <div className="space-y-3">
                    <div className="flex items-center justify-between">
                        <h2 className="text-sm font-semibold text-ink-strong">
                            Official Charter Document
                        </h2>
                        <span className="text-xs text-ink-faint">
                            Authorized Barangay Service Standard
                        </span>
                    </div>

                    <div className="overflow-hidden rounded-xl border border-line-soft bg-white shadow-card">
                        {service.file_url ? (
                            <iframe
                                src={service.file_url}
                                className="h-[650px] w-full border-0"
                                title={`Citizen's Charter PDF - ${service.title}`}
                            />
                        ) : (
                            <div className="p-12 text-center text-xs text-ink-faint">
                                Document file is currently unavailable.
                            </div>
                        )}
                    </div>

                    {/* MOBILE FALLBACK NOTE */}
                    <div className="rounded-xl border border-line-soft bg-canvas/70 p-3.5 text-xs text-ink-muted">
                        <span className="font-semibold text-ink">
                            Mobile notice:
                        </span>{' '}
                        If your device or browser cannot display embedded PDF
                        previews, use the{' '}
                        <a
                            href={service.download_url || '#'}
                            className="font-semibold text-brand underline"
                        >
                            Download PDF
                        </a>{' '}
                        or{' '}
                        <a
                            href={service.file_url || '#'}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="font-semibold text-brand underline"
                        >
                            Open in New Tab
                        </a>{' '}
                        button above to view the complete official document.
                    </div>
                </div>
            </div>
        </ResidentLayout>
    );
}
