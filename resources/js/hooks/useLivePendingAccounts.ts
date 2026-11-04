import { useCallback, useEffect, useRef, useState } from 'react';
import axios from 'axios';

export default function useLivePendingAccounts<T>(initial: T | undefined, query: string) {
    const [snapshot, setSnapshot] = useState<{ query: string; data: T; total: number; updatedAt: string } | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [refreshing, setRefreshing] = useState(false);
    const abort = useRef<AbortController | null>(null);
    const generation = useRef(0);

    const refresh = useCallback(async () => {
        abort.current?.abort();
        const controller = new AbortController();
        abort.current = controller;
        const current = ++generation.current;
        setRefreshing(true);
        try {
            const response = await axios.get(`/admin/resident-registrations/pending?${query}`, {
                signal: controller.signal,
                timeout: 20000,
                headers: { Accept: 'application/json', 'Cache-Control': 'no-cache' },
            });
            if (current !== generation.current || controller.signal.aborted) return;
            if (!response.data?.pendingRegistrations || typeof response.data.totalPending !== 'number') {
                throw new Error('Invalid queue response');
            }
            setSnapshot({ query, data: response.data.pendingRegistrations, total: response.data.totalPending, updatedAt: response.data.updatedAt });
            setError(null);
        } catch (failure) {
            if (current !== generation.current || controller.signal.aborted) return;
            setError(axios.isAxiosError(failure) && [401, 403, 419].includes(failure.response?.status ?? 0)
                ? 'Your session has expired. Reload this page and sign in again.'
                : 'Unable to refresh pending accounts. Showing the last loaded list. Select Refresh to try again.');
        } finally {
            if (current === generation.current) {
                abort.current = null;
                setRefreshing(false);
            }
        }
    }, [query]);

    useEffect(() => {
        void refresh();
        const timer = window.setInterval(() => {
            if (document.visibilityState === 'visible' && !abort.current) {
                void refresh();
            }
        }, 5000);
        const resume = () => { if (document.visibilityState === 'visible') void refresh(); };
        window.addEventListener('focus', resume);
        window.addEventListener('online', resume);
        document.addEventListener('visibilitychange', resume);
        return () => {
            window.clearInterval(timer);
            window.removeEventListener('focus', resume);
            window.removeEventListener('online', resume);
            document.removeEventListener('visibilitychange', resume);
            generation.current++;
            abort.current?.abort();
        };
    }, [refresh, initial]);

    const current = snapshot?.query === query ? snapshot : null;
    return { data: current?.data ?? initial, total: current?.total, updatedAt: current?.updatedAt, error, refreshing, refresh };
}
