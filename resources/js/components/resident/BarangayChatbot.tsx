import { plainAssistantText } from '@/lib/assistantText';
import axios from 'axios';
import { Link } from '@inertiajs/react';
import { useEffect, useRef, useState, type FormEvent } from 'react';
import AssistantLauncher, { type LauncherPosition } from './AssistantLauncher';

type TrackingRecord = {
    category: string;
    reference: string;
    label: string;
    status: string;
    detail: string;
    url: string;
    updatedAt?: string;
};
type Message = {
    id: string;
    role: 'user' | 'assistant';
    content: string;
    records?: TrackingRecord[];
    checkedAt?: string;
};
type Turn = Pick<Message, 'role' | 'content'>;
const greeting = (): Message => ({
    id: 'welcome',
    role: 'assistant',
    content:
        'Magandang araw! Ako si Kabarangay AI. Paano kita matutulungan tungkol sa certificates, Lupon Tagapamayapa, o iba pang serbisyo ng barangay?',
});
const suggestions = [
    'I-track ang status ng requests ko',
    'Paano kumuha ng Indigent Certificate?',
    'Ano ang proseso ng Lupon Tagapamayapa?',
];

export default function BarangayChatbot({
    floating = false,
    storageKey = null,
}: {
    floating?: boolean;
    storageKey?: string | null;
}) {
    const [open, setOpen] = useState(!floating);
    const [launcherPosition, setLauncherPosition] =
        useState<LauncherPosition | null>(null);
    const [messages, setMessages] = useState<Message[]>([greeting()]);
    const [input, setInput] = useState('');
    const [hydratedKey, setHydratedKey] = useState<string | null>(null);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState('');
    const [retry, setRetry] = useState<{
        message: string;
        history: Turn[];
    } | null>(null);
    const request = useRef<AbortController | null>(null);
    const bottom = useRef<HTMLDivElement>(null);
    const inputRef = useRef<HTMLTextAreaElement>(null);
    const reset = () => {
        request.current?.abort();
        request.current = null;
        if (storageKey) {
            try {
                localStorage.removeItem(storageKey);
            } catch {}
        }
        setLoading(false);
        setError('');
        setRetry(null);
        setInput('');
        setMessages([greeting()]);
    };
    useEffect(() => {
        request.current?.abort();
        request.current = null;
        setLoading(false);
        setError('');
        setRetry(null);
        setInput('');
        let restored: Message[] | null = null;
        if (storageKey) {
            try {
                const saved = JSON.parse(
                    localStorage.getItem(storageKey) ?? 'null',
                );
                if (saved?.version === 1 && Array.isArray(saved.messages)) {
                    restored = saved.messages
                        .filter(
                            (item: Message) =>
                                item &&
                                typeof item.id === 'string' &&
                                ['user', 'assistant'].includes(item.role) &&
                                typeof item.content === 'string',
                        )
                        .slice(-60)
                        .map((item: Message) => ({
                            id: item.id,
                            role: item.role,
                            content: item.content.slice(0, 20000),
                            checkedAt:
                                typeof item.checkedAt === 'string'
                                    ? item.checkedAt
                                    : undefined,
                            records: Array.isArray(item.records)
                                ? item.records
                                      .filter(
                                          (record) =>
                                              record &&
                                              typeof record.label ===
                                                  'string' &&
                                              typeof record.status ===
                                                  'string' &&
                                              typeof record.detail ===
                                                  'string' &&
                                              typeof record.reference ===
                                                  'string' &&
                                              typeof record.url === 'string' &&
                                              record.url.startsWith(
                                                  '/resident/',
                                              ),
                                      )
                                      .slice(0, 20)
                                : undefined,
                        }));
                }
            } catch {
                /* An unavailable or invalid saved chat must not prevent chatting. */
            }
        }
        setMessages(restored?.length ? restored : [greeting()]);
        setHydratedKey(storageKey);
    }, [storageKey]);
    useEffect(() => {
        if (!storageKey || hydratedKey !== storageKey) return;
        try {
            localStorage.setItem(
                storageKey,
                JSON.stringify({ version: 1, messages: messages.slice(-60) }),
            );
        } catch {
            /* Chat remains usable when browser storage is full or blocked. */
        }
    }, [storageKey, hydratedKey, messages]);
    useEffect(() => {
        window.addEventListener('kabarangay-assistant-cleared', reset);
        return () => {
            window.removeEventListener('kabarangay-assistant-cleared', reset);
            request.current?.abort();
            request.current = null;
        };
    }, [storageKey]);
    useEffect(() => {
        bottom.current?.scrollIntoView({
            behavior: 'smooth',
            block: 'nearest',
        });
    }, [messages, loading, open, error]);

    const send = async (text = input, previous?: Turn[]) => {
        const message = text.trim();
        if (!message || request.current) return;
        if (message.length > 1000) {
            setError('Hanggang 1,000 characters lamang ang mensahe.');
            return;
        }
        const history =
            previous ??
            messages
                .filter((item) => item.id !== 'welcome')
                .slice(-10)
                .map(({ role, content }) => ({
                    role,
                    content: content.slice(0, 1000),
                }));
        if (!previous)
            setMessages((current) => [
                ...current,
                { id: crypto.randomUUID(), role: 'user', content: message },
            ]);
        setInput('');
        setError('');
        setRetry(null);
        setLoading(true);
        const controller = new AbortController();
        request.current = controller;
        try {
            const response = await axios.post<{
                reply: string;
                records?: TrackingRecord[];
                checkedAt?: string;
            }>(
                '/chatbot/send',
                { message, history },
                {
                    signal: controller.signal,
                    timeout: 40000,
                    headers: {
                        Accept: 'application/json',
                        'X-CSRF-TOKEN':
                            document
                                .querySelector('meta[name="csrf-token"]')
                                ?.getAttribute('content') ?? '',
                    },
                },
            );
            if (
                typeof response.data?.reply !== 'string' ||
                !response.data.reply.trim()
            )
                throw new Error(
                    'Hindi makasagot si Kabarangay AI. Subukan muli o mag-sign in ulit kung natapos na ang session.',
                );
            if (request.current !== controller) return;
            setMessages((current) => [
                ...current,
                {
                    id: crypto.randomUUID(),
                    role: 'assistant',
                    content: plainAssistantText(response.data.reply),
                    records: Array.isArray(response.data.records)
                        ? response.data.records.filter(
                              (record) =>
                                  typeof record?.url === 'string' &&
                                  record.url.startsWith('/resident/'),
                          )
                        : undefined,
                    checkedAt: response.data.checkedAt,
                },
            ]);
        } catch (failure) {
            if (axios.isCancel(failure) || request.current !== controller)
                return;
            let explanation =
                'Hindi makakonekta kay Kabarangay AI. Pakisubukan muli.';
            if (axios.isAxiosError(failure)) {
                const status = failure.response?.status;
                const payload = failure.response?.data;
                if (status === 419 || status === 401)
                    explanation =
                        'Natapos na ang session. I-refresh ang page o mag-sign in ulit.';
                else if (typeof payload?.error === 'string')
                    explanation = payload.error;
                else if (status === 429)
                    explanation =
                        'Maraming mensahe ang naipadala. Maghintay sandali bago subukan muli.';
                else if (typeof payload?.errors?.message?.[0] === 'string')
                    explanation = payload.errors.message[0];
            } else if (failure instanceof Error) explanation = failure.message;
            setError(explanation);
            setRetry({ message, history });
        } finally {
            if (request.current === controller) {
                request.current = null;
                setLoading(false);
                inputRef.current?.focus();
            }
        }
    };
    const submit = (event: FormEvent) => {
        event.preventDefault();
        void send();
    };
    const floatingStyle = (() => {
        if (!floating || !launcherPosition) return undefined;
        const { top, viewportHeight } = launcherPosition;
        const above = Math.max(0, top - 28);
        const below = Math.max(0, viewportHeight - top - 84);
        // Keep the icon outside the chat when there is room above or below it.
        // On short landscape screens the chat uses the available viewport.
        if (Math.max(above, below) < 300)
            return {
                top: 16,
                bottom: 'auto',
                height: Math.max(180, viewportHeight - 32),
                right: 84,
                width: 'min(360px, calc(100vw - 100px))',
            };
        const height = Math.min(520, Math.max(above, below));
        return {
            top: above >= below ? top - height - 12 : top + 68,
            bottom: 'auto',
            height,
        };
    })();
    return (
        <>
            {floating && (
                <AssistantLauncher
                    open={open}
                    onToggle={() => setOpen((value) => !value)}
                    onPositionChange={setLauncherPosition}
                >
                    <RobotAvatar className="h-10 w-10 shrink-0" />
                </AssistantLauncher>
            )}
            {open && (
                <section
                    id="kabarangay-ai-chat"
                    role={floating ? 'dialog' : undefined}
                    aria-label="Kabarangay AI assistant"
                    style={floatingStyle}
                    className={
                        floating
                            ? 'fixed right-3 bottom-20 z-50 flex h-[min(520px,calc(100dvh-7rem))] w-[min(360px,calc(100vw-1.5rem))] flex-col overflow-hidden rounded-2xl border bg-white shadow-2xl'
                            : 'flex h-[min(76dvh,740px)] min-h-96 flex-col overflow-hidden rounded-2xl border border-stone-200 bg-white shadow-sm'
                    }
                >
                    <header
                        className={`flex shrink-0 items-center justify-between gap-2 bg-[#3f6f3d] text-white ${floating ? 'p-3' : 'p-4'}`}
                    >
                        <div className="flex min-w-0 items-center gap-3">
                            <RobotAvatar
                                className={
                                    floating
                                        ? 'h-9 w-9 shrink-0'
                                        : 'h-12 w-12 shrink-0'
                                }
                                thinking={loading}
                            />
                            <div>
                                <h1
                                    className={
                                        floating
                                            ? 'text-sm font-bold'
                                            : 'font-bold'
                                    }
                                >
                                    Kabarangay AI
                                </h1>
                                {!floating && (
                                    <p className="text-xs text-white/80">
                                        Personal barangay assistant
                                    </p>
                                )}
                                <p className="mt-1 flex items-center gap-1 text-[10px] text-emerald-100">
                                    <span className="h-1.5 w-1.5 rounded-full bg-emerald-300" />
                                    Live request tracking
                                </p>
                            </div>
                        </div>
                        <div className="flex gap-2">
                            <button
                                type="button"
                                onClick={reset}
                                className="rounded-lg border border-white/40 px-2 py-1 text-xs"
                            >
                                Bagong usapan
                            </button>
                            {floating && (
                                <button
                                    type="button"
                                    onClick={() => setOpen(false)}
                                    aria-label="Close assistant"
                                    className="rounded px-2 py-1"
                                >
                                    ×
                                </button>
                            )}
                        </div>
                    </header>
                    <div
                        className={`flex min-h-0 flex-1 flex-col overflow-y-auto bg-stone-50 ${floating ? 'p-3' : 'p-4'}`}
                    >
                        <div
                            role="log"
                            aria-live="polite"
                            aria-relevant="additions"
                            aria-label="Mga mensahe"
                            className="space-y-3"
                        >
                            {messages.map((item) => (
                                <div
                                    key={item.id}
                                    className={`flex ${item.role === 'user' ? 'justify-end' : 'justify-start'}`}
                                >
                                    <div
                                        className={`max-w-[90%] rounded-2xl px-4 py-3 text-sm break-words whitespace-pre-wrap ${item.role === 'user' ? 'bg-[#3f6f3d] text-white' : 'border border-emerald-100 bg-white text-stone-800 shadow-sm'}`}
                                    >
                                        <span className="mb-1 block text-[10px] font-bold opacity-70">
                                            {item.role === 'user' ? (
                                                'Ikaw'
                                            ) : (
                                                <span className="flex items-center gap-1">
                                                    <RobotAvatar className="h-5 w-5" />
                                                    Kabarangay AI
                                                </span>
                                            )}
                                        </span>
                                        {item.role === 'assistant'
                                            ? plainAssistantText(
                                                  item.records?.length
                                                      ? item.content.split(
                                                            '\n\n',
                                                        )[0]
                                                      : item.content,
                                              )
                                            : item.content}
                                        {item.records?.map((record, index) => (
                                            <div
                                                key={record.reference + index}
                                                className="mt-3 space-y-2 rounded-xl border border-emerald-100 bg-emerald-50/60 p-3 text-stone-800"
                                            >
                                                <p className="text-xs font-bold">
                                                    {record.label}
                                                </p>
                                                <p className="font-mono text-[11px] text-stone-500">
                                                    {record.reference}
                                                </p>
                                                <p className="inline-block rounded-full border border-emerald-200 bg-white px-2 py-1 text-xs font-semibold text-emerald-900">
                                                    {record.status}
                                                </p>
                                                <p className="text-xs leading-5">
                                                    {record.detail}
                                                </p>
                                                <Link
                                                    href={record.url}
                                                    className="inline-flex text-xs font-bold text-emerald-800 underline"
                                                >
                                                    Buksan ang record →
                                                </Link>
                                            </div>
                                        ))}
                                        {item.checkedAt && (
                                            <p className="mt-2 text-[10px] text-stone-500">
                                                Mula sa system · Sinuri{' '}
                                                {new Date(
                                                    item.checkedAt,
                                                ).toLocaleString('en-PH', {
                                                    month: 'short',
                                                    day: 'numeric',
                                                    hour: 'numeric',
                                                    minute: '2-digit',
                                                    timeZone: 'Asia/Manila',
                                                })}
                                            </p>
                                        )}
                                    </div>
                                </div>
                            ))}
                        </div>
                        {loading && (
                            <p
                                role="status"
                                className="mt-3 text-sm text-stone-600"
                            >
                                <span
                                    aria-hidden="true"
                                    className="mr-2 inline-block h-3 w-3 animate-spin rounded-full border-2 border-emerald-800 border-t-transparent"
                                />
                                Naghahanda ng sagot si Kabarangay AI…
                            </p>
                        )}
                        {error && (
                            <div
                                role="alert"
                                className="mt-3 rounded-xl border border-red-200 bg-red-50 p-3 text-sm text-red-800"
                            >
                                {error}
                                {retry && (
                                    <button
                                        type="button"
                                        disabled={loading}
                                        onClick={() =>
                                            void send(
                                                retry.message,
                                                retry.history,
                                            )
                                        }
                                        className="mt-2 block font-semibold underline"
                                    >
                                        Subukan muli
                                    </button>
                                )}
                            </div>
                        )}
                        <div ref={bottom} />
                    </div>
                    <footer
                        className={`shrink-0 border-t ${floating ? 'space-y-2 p-2.5' : 'space-y-3 p-3'}`}
                    >
                        {messages.length === 1 && (
                            <div className="flex flex-wrap gap-2">
                                {suggestions
                                    .slice(0, floating ? 1 : suggestions.length)
                                    .map((text) => (
                                        <button
                                            key={text}
                                            type="button"
                                            disabled={loading}
                                            onClick={() => void send(text)}
                                            className="rounded-lg border px-2 py-1 text-left text-xs text-emerald-900 disabled:opacity-50"
                                        >
                                            {text}
                                        </button>
                                    ))}
                            </div>
                        )}
                        <nav
                            aria-label="Mga serbisyo"
                            className="flex gap-3 text-xs font-semibold text-emerald-800"
                        >
                            <Link href="/resident/certificates">
                                Certificates
                            </Link>
                            <Link href="/resident/lupon">Lupon</Link>
                            <Link href="/resident/citizen-charter">
                                Citizen’s Charter
                            </Link>
                        </nav>
                        <form
                            onSubmit={submit}
                            className="flex items-end gap-2"
                            aria-busy={loading}
                        >
                            <div className="min-w-0 flex-1">
                                <label
                                    htmlFor="kabarangay-ai-message"
                                    className="sr-only"
                                >
                                    Mensahe kay Kabarangay AI
                                </label>
                                <textarea
                                    id="kabarangay-ai-message"
                                    ref={inputRef}
                                    rows={floating ? 1 : 2}
                                    maxLength={1000}
                                    value={input}
                                    onChange={(event) =>
                                        setInput(event.target.value)
                                    }
                                    onKeyDown={(event) => {
                                        if (
                                            event.key === 'Enter' &&
                                            !event.shiftKey &&
                                            !event.nativeEvent.isComposing
                                        ) {
                                            event.preventDefault();
                                            void send();
                                        }
                                    }}
                                    placeholder="I-type ang iyong tanong…"
                                    className="w-full resize-none rounded-xl border border-stone-300 p-2 text-sm focus:border-emerald-700 focus:outline-none"
                                />
                                <p className="text-right text-[10px] text-stone-500">
                                    {input.length}/1000
                                </p>
                            </div>
                            <button
                                type="submit"
                                disabled={loading || !input.trim()}
                                className="mb-4 rounded-xl bg-[#3f6f3d] px-3 py-2 text-sm font-semibold text-white disabled:opacity-40"
                            >
                                {loading ? 'Sandali…' : 'Ipadala'}
                            </button>
                        </form>
                    </footer>
                </section>
            )}
        </>
    );
}

function RobotAvatar({
    className,
    thinking = false,
}: {
    className: string;
    thinking?: boolean;
}) {
    return (
        <svg
            viewBox="0 0 64 64"
            aria-hidden="true"
            className={
                className + (thinking ? ' motion-safe:animate-pulse' : '')
            }
            fill="none"
        >
            <circle cx="32" cy="32" r="31" fill="#ecfdf5" />
            <path
                d="M32 15V9"
                stroke="#047857"
                strokeWidth="3"
                strokeLinecap="round"
            />
            <circle cx="32" cy="8" r="3" fill="#34d399" />
            <rect x="11" y="22" width="5" height="19" rx="2.5" fill="#34d399" />
            <rect x="48" y="22" width="5" height="19" rx="2.5" fill="#34d399" />
            <rect
                x="16"
                y="17"
                width="32"
                height="31"
                rx="11"
                fill="#d1fae5"
                stroke="#047857"
                strokeWidth="2"
            />
            <rect
                x="21"
                y="24"
                width="22"
                height="13"
                rx="6.5"
                fill="#064e3b"
            />
            <circle cx="27" cy="30" r="2.5" fill="#6ee7b7" />
            <circle cx="37" cy="30" r="2.5" fill="#6ee7b7" />
            <path
                d="M27 41Q32 45 37 41"
                stroke="#047857"
                strokeWidth="2"
                strokeLinecap="round"
            />
            <path
                d="M23 53Q32 49 41 53"
                stroke="#047857"
                strokeWidth="3"
                strokeLinecap="round"
            />
        </svg>
    );
}
