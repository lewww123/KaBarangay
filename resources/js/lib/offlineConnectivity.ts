export type ConnectivityNotice = {
    id: number;
    tone: 'success' | 'error';
    message: string;
};

export type ConnectivityState = {
    /** Browser connectivity, confirmed application-server reachability, *and* readable account data. */
    online: boolean;
    /** The server answered, but its barangay database could not be read. */
    degraded: boolean;
    syncing: boolean;
    pending: number;
    /** The current page was rendered from locally saved data, not the server. */
    stale: boolean;
    notice?: ConnectivityNotice;
};

let noticeId = 0;
let noticeTimer: ReturnType<typeof setTimeout> | undefined;
let state: ConnectivityState = {
    online: true,
    degraded: false,
    syncing: false,
    pending: 0,
    stale: false,
};
const listeners = new Set<() => void>();

const emit = () => {
    for (const listener of listeners) listener();
};

export function subscribeConnectivity(listener: () => void): () => void {
    listeners.add(listener);
    return () => listeners.delete(listener);
}

export function readConnectivity(): ConnectivityState {
    return state;
}

export function updateConnectivity(
    changes: Partial<Omit<ConnectivityState, 'notice'>>,
): void {
    const next = { ...state, ...changes };
    if (
        next.online === state.online &&
        next.degraded === state.degraded &&
        next.syncing === state.syncing &&
        next.pending === state.pending &&
        next.stale === state.stale
    )
        return;
    state = next;
    emit();
}

export function notifyConnectivity(
    tone: ConnectivityNotice['tone'],
    message: string,
    ttl = 8000,
): void {
    clearTimeout(noticeTimer);
    state = { ...state, notice: { id: ++noticeId, tone, message } };
    emit();
    noticeTimer = setTimeout(() => {
        state = { ...state, notice: undefined };
        emit();
    }, ttl);
}
