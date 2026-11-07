import { router } from '@inertiajs/react';
import { useState } from 'react';
import {
    completionUrl,
    type AnnouncementPortal,
} from '@/components/announcements/announcement';
import { cn } from '@/lib/utils';

interface MarkAnnouncementDoneButtonProps {
    portal: AnnouncementPortal;
    announcementId: number;
    title: string;
    className?: string;
    kind?: 'event' | 'update';
}

export default function MarkAnnouncementDoneButton({
    portal,
    announcementId,
    title,
    className,
    kind = 'event',
}: MarkAnnouncementDoneButtonProps) {
    const [open, setOpen] = useState(false);
    const [processing, setProcessing] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const confirmCompletion = () => {
        if (processing) {
            return;
        }

        setError(null);
        setProcessing(true);

        router.post(
            completionUrl(portal, announcementId, kind),
            {},
            {
                preserveScroll: true,
                onSuccess: () => setOpen(false),
                onError: (errors) => {
                    setError(
                        (errors.completion ??
                            errors.announcement ??
                            errors.approval ??
                            kind === 'update')
                            ? 'Unable to mark this update as completed.'
                            : 'Unable to mark this announcement as done.',
                    );
                },
                onFinish: () => setProcessing(false),
            },
        );
    };

    return (
        <>
            <button
                type="button"
                onClick={(event) => {
                    event.preventDefault();
                    event.stopPropagation();
                    setError(null);
                    setOpen(true);
                }}
                className={cn(
                    'inline-flex items-center justify-center rounded-lg bg-[#2a5279] px-3 py-2 text-sm font-medium text-white transition-colors hover:bg-[#2a5279]',
                    className,
                )}
            >
                {kind === 'update' ? 'Mark Completed' : 'Mark as Done'}
            </button>

            {open && (
                <div
                    className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
                    role="dialog"
                    aria-modal="true"
                    aria-label={
                        kind === 'update'
                            ? 'Mark update as completed'
                            : 'Mark announcement as done'
                    }
                >
                    <div className="w-full max-w-md rounded-xl bg-white p-6 shadow-xl">
                        <h2 className="text-lg font-semibold text-gray-900">
                            {kind === 'update'
                                ? 'Mark Update Completed'
                                : 'Mark as Done'}
                        </h2>
                        <p className="mt-2 text-sm text-gray-600">
                            {kind === 'update' ? (
                                <>
                                    Mark &ldquo;{title}&rdquo; as completed? It
                                    will move to Past Updates and be locked from
                                    editing.
                                </>
                            ) : (
                                <>
                                    Mark &ldquo;{title}&rdquo; as done? This
                                    records completion and locks the
                                    announcement from further editing.
                                </>
                            )}
                        </p>

                        {error && (
                            <p className="mt-3 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">
                                {error}
                            </p>
                        )}

                        <div className="mt-6 flex justify-end gap-2">
                            <button
                                type="button"
                                onClick={() => setOpen(false)}
                                disabled={processing}
                                className="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-60"
                            >
                                Cancel
                            </button>
                            <button
                                type="button"
                                onClick={confirmCompletion}
                                disabled={processing}
                                className="rounded-lg bg-[#2a5279] px-4 py-2 text-sm font-medium text-white hover:bg-[#2a5279] disabled:opacity-60"
                            >
                                {processing
                                    ? 'Saving...'
                                    : kind === 'update'
                                      ? 'Mark Completed'
                                      : 'Mark as Done'}
                            </button>
                        </div>
                    </div>
                </div>
            )}
        </>
    );
}
