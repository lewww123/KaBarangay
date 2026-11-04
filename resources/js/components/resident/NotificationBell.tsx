import axios from 'axios';
import { router } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';

type Notice = {
    id: number;
    title: string;
    message: string;
    url: string;
    read_at: string | null;
    created_at: string;
};

export default function NotificationBell() {
    const [open, setOpen] = useState(false);
    const [items, setItems] = useState<Notice[]>([]);
    const [unread, setUnread] = useState(0);
    const [loaded, setLoaded] = useState(false);
    const [error, setError] = useState('');
    const [busy, setBusy] = useState(false);
    const root = useRef<HTMLDivElement>(null);
    const button = useRef<HTMLButtonElement>(null);
    const load = useCallback(async () => {
        try {
            const { data } = await axios.get('/resident/notifications');
            if (
                !Array.isArray(data.items) ||
                typeof data.unread_count !== 'number'
            ) {
                throw new Error('Notification response unavailable');
            }
            setItems(data.items);
            setUnread(data.unread_count);
            setLoaded(true);
            setError('');
        } catch {
            setError('Could not refresh notifications. Please try again.');
        }
    }, []);

    useEffect(() => {
        void load();
        const refresh = () => {
            if (!document.hidden) void load();
        };
        const timer = window.setInterval(refresh, 30000);
        window.addEventListener('focus', refresh);
        return () => {
            window.clearInterval(timer);
            window.removeEventListener('focus', refresh);
        };
    }, [load]);

    useEffect(() => {
        if (!open) return;
        void load();
        const outside = (event: PointerEvent) => {
            if (!root.current?.contains(event.target as Node)) setOpen(false);
        };
        const escape = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                setOpen(false);
                button.current?.focus();
            }
        };
        document.addEventListener('pointerdown', outside);
        document.addEventListener('keydown', escape);
        return () => {
            document.removeEventListener('pointerdown', outside);
            document.removeEventListener('keydown', escape);
        };
    }, [open, load]);

    const read = async (notice?: Notice) => {
        setBusy(true);
        try {
            await axios.post(
                notice
                    ? `/resident/notifications/${notice.id}/read`
                    : '/resident/notifications/read-all',
            );
            await load();
            if (notice) {
                setOpen(false);
                router.visit(notice.url);
            }
        } catch {
            setError('Could not mark as read. Please try again.');
        } finally {
            setBusy(false);
        }
    };

    return (
        <div ref={root} className="relative">
            <button
                ref={button}
                type="button"
                aria-label={`Notifications, ${unread} unread`}
                aria-expanded={open}
                aria-controls="resident-notifications"
                onClick={() => setOpen(!open)}
                className="relative flex h-11 w-11 items-center justify-center rounded-xl hover:bg-white/15 focus-visible:outline-2 focus-visible:outline-white"
            >
                <svg
                    width="22"
                    height="22"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    strokeWidth="1.8"
                    aria-hidden="true"
                >
                    <path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4" />
                </svg>
                {unread > 0 && (
                    <span className="absolute top-0 right-0 min-w-4 rounded-full bg-red-600 px-1 text-[10px] font-bold text-white">
                        {unread > 99 ? '99+' : unread}
                    </span>
                )}
            </button>
            {open && (
                <section
                    id="resident-notifications"
                    aria-label="Notifications"
                    className="fixed top-16 right-3 z-50 w-[calc(100vw-1.5rem)] max-w-sm overflow-hidden rounded-2xl border border-gray-200 bg-white text-gray-900 shadow-xl sm:absolute sm:top-full sm:right-0 sm:mt-2 sm:w-96"
                >
                    <div className="flex items-center justify-between border-b border-gray-100 p-4">
                        <h2 className="font-semibold">Notifications</h2>
                        <button
                            type="button"
                            disabled={busy || unread === 0}
                            onClick={() => void read()}
                            className="text-xs font-semibold text-[#315b30] disabled:opacity-40"
                        >
                            Mark all as read
                        </button>
                    </div>
                    {error && (
                        <div role="status" className="p-3 text-xs text-red-700">
                            {error}{' '}
                            <button
                                type="button"
                                onClick={() => void load()}
                                className="underline"
                            >
                                Retry
                            </button>
                        </div>
                    )}
                    <div className="max-h-[min(65vh,26rem)] overflow-y-auto">
                        {!loaded && !error && (
                            <p
                                role="status"
                                className="p-6 text-center text-sm text-gray-500"
                            >
                                Loading notifications…
                            </p>
                        )}
                        {loaded && items.length === 0 && (
                            <p className="p-6 text-center text-sm text-gray-500">
                                You’re all caught up. Certificate and attendance
                                updates will appear here.
                            </p>
                        )}
                        {items.map((notice) => (
                            <button
                                key={notice.id}
                                type="button"
                                disabled={busy}
                                onClick={() => void read(notice)}
                                className={`block w-full border-b border-gray-100 p-4 text-left hover:bg-gray-100 disabled:opacity-60 ${notice.read_at ? 'bg-white' : 'bg-green-50'}`}
                            >
                                <span className="flex items-center gap-2 text-sm font-semibold">
                                    {!notice.read_at && (
                                        <span
                                            className="h-2 w-2 shrink-0 rounded-full bg-green-700"
                                            aria-label="Unread"
                                        />
                                    )}
                                    {notice.title}
                                </span>
                                <span className="mt-1 block text-xs leading-5 text-gray-600">
                                    {notice.message}
                                </span>
                                <time
                                    dateTime={notice.created_at}
                                    className="mt-2 block text-[11px] text-gray-500"
                                >
                                    {new Date(
                                        notice.created_at,
                                    ).toLocaleString()}
                                </time>
                            </button>
                        ))}
                    </div>
                    <div className="flex justify-between p-3 text-xs text-gray-500">
                        <span>Latest 30 updates · refreshes automatically</span>
                        <button
                            type="button"
                            onClick={() => {
                                setOpen(false);
                                button.current?.focus();
                            }}
                            className="font-semibold text-[#315b30]"
                        >
                            Close
                        </button>
                    </div>
                </section>
            )}
        </div>
    );
}
