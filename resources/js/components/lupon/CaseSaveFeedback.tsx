import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { createPortal } from 'react-dom';

export const caseSaveOptions = {
    only: ['caseData', 'errors', 'flash'],
    preserveState: true,
    preserveScroll: true,
    showProgress: false,
};
const actionName = (url: string) => {
    if (url.includes('/hearings')) return 'Hearing record';
    if (url.includes('/settlements')) return 'Amicable settlement';
    if (url.includes('/complainants')) return 'Complainant details';
    if (url.includes('/associate-respondent')) return 'Respondent association';
    if (url.includes('/extend-conciliation')) return 'Conciliation extension';
    if (url.includes('/status')) return 'Case phase and Pangkat details';
    if (url.includes('/documents')) return 'Case document';
    if (url.includes('/visibility')) return 'Evidence visibility';
    if (url.includes('/evidence')) return 'Evidence upload';
    if (url.includes('/history')) return 'Case history';
    return 'Case details';
};
type Notice = { kind: 'saving' | 'success' | 'error'; message: string } | null;
export function useCaseSaveStatus() {
    const [notice, setNotice] = useState<Notice>(null);
    const [busy, setBusy] = useState(false);
    useEffect(() => {
        let action = '';
        let pending = false;
        let completed = false;
        const cleanups = [
            router.on('start', (event) => {
                const visit = event.detail.visit;
                if (visit.method.toLowerCase() === 'get') return;
                pending = true;
                completed = false;
                action = actionName(visit.url.pathname);
                setBusy(true);
                setNotice({
                    kind: 'saving',
                    message: `Saving ${action.toLowerCase()}…`,
                });
            }),
            router.on('success', (event) => {
                if (!pending) return;
                completed = true;
                const flash = event.detail.page.props.flash as
                    | { success?: string; error?: string }
                    | undefined;
                setNotice({
                    kind: flash?.error ? 'error' : 'success',
                    message:
                        flash?.error ||
                        flash?.success ||
                        `${action} saved successfully.`,
                });
            }),
            router.on('error', () => {
                if (!pending) return;
                completed = true;
                setNotice({
                    kind: 'error',
                    message: `${action} was not saved. Check the highlighted fields and try again.`,
                });
            }),
            router.on('finish', (event) => {
                if (
                    event.detail.visit.method.toLowerCase() === 'get' ||
                    !pending
                )
                    return;
                if (!completed)
                    setNotice({
                        kind: 'error',
                        message: `${action} could not be saved. Please try again.`,
                    });
                pending = false;
                setBusy(false);
            }),
        ];
        return () => cleanups.forEach((cleanup) => cleanup());
    }, []);
    return { notice, busy, dismiss: () => setNotice(null) };
}
export function CaseSaveFeedback({
    notice,
    dismiss,
}: {
    notice: Notice;
    dismiss: () => void;
}) {
    if (!notice || typeof document === 'undefined') return null;
    const target = document.querySelector('dialog[open]') ?? document.body;
    return createPortal(
        <div
            role={notice.kind === 'error' ? 'alert' : 'status'}
            aria-live="polite"
            className={`fixed right-4 bottom-4 z-[100] flex max-w-[min(30rem,calc(100vw-2rem))] items-center gap-3 rounded-xl border px-4 py-3 text-sm shadow-lg ${notice.kind === 'error' ? 'border-red-200 bg-red-50 text-red-900' : notice.kind === 'saving' ? 'border-blue-200 bg-blue-50 text-blue-900' : 'border-emerald-200 bg-emerald-50 text-emerald-900'}`}
        >
            {notice.kind === 'saving' && (
                <span
                    aria-hidden="true"
                    className="h-4 w-4 shrink-0 animate-spin rounded-full border-2 border-blue-700 border-t-transparent"
                />
            )}
            <span>{notice.message}</span>
            {notice.kind !== 'saving' && (
                <button
                    type="button"
                    onClick={dismiss}
                    aria-label="Dismiss save notification"
                    className="ml-2 rounded px-2 py-1"
                >
                    
                </button>
            )}
        </div>,
        target,
    );
}
