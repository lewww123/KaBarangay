export type OfflineCertificateForm = {
    certificate_id: string;
    purpose: string;
    business_name?: string;
    business_address?: string;
    additional_details?: string;
    details: Record<string, string>;
    catalog_revision?: string;
};
export type LocalCertificateStatus =
    | 'draft'
    | 'pending'
    | 'syncing'
    | 'synced'
    | 'login-required'
    | 'needs-review';

export type LocalCertificateRequest = {
    id: string;
    status: LocalCertificateStatus;
    createdAt: number;
    updatedAt: number;
    data: {
        certificateName: string;
        estimatedFee?: number;
        form?: OfflineCertificateForm;
        result?: { reference: string; status?: string; fee?: number };
        error?: string | null;
    };
};

/** Queued but not yet confirmed by the server; shown in My Requests as local only. */
export const UNSYNCED_STATUSES: string[] = [
    'pending',
    'syncing',
    'login-required',
    'needs-review',
];

type SyncResult = {
    ok?: boolean;
    busy?: boolean;
    offline?: boolean;
    retryLater?: boolean;
    loginRequired?: boolean;
};
type CertificateStore = {
    context(): Promise<{ owner: string; scope: string }>;
    list(): Promise<LocalCertificateRequest[]>;
    saveDraft(form: OfflineCertificateForm, id?: string): Promise<string>;
    queue(id: string): Promise<void>;
    sync(): Promise<SyncResult>;
};

export async function offlineCertificateStore(): Promise<CertificateStore> {
    const url = '/offline-certificate-store.js';
    await import(/* @vite-ignore */ url);
    return (globalThis as unknown as { KBCertificates: CertificateStore })
        .KBCertificates;
}

export async function queueOfflineCertificateRequest(
    form: OfflineCertificateForm,
): Promise<string> {
    let store = await offlineCertificateStore();
    try {
        await store.context();
    } catch {
        store = await prepareOfflineCertificates();
    }
    const id = await store.saveDraft(form);
    await store.queue(id);
    return id;
}

export async function prepareOfflineCertificates(): Promise<CertificateStore> {
    const registration = await navigator.serviceWorker?.getRegistration('/');
    const worker = navigator.serviceWorker?.controller || registration?.active;
    if (!worker)
        throw new Error(
            'Offline support is still preparing. Please try again on the production site.',
        );
    const response = await fetch('/resident/certificates/offline-context', {
        credentials: 'same-origin',
        cache: 'no-store',
        headers: { Accept: 'application/json' },
    });
    if (
        !response.ok ||
        response.redirected ||
        !response.headers.get('Content-Type')?.includes('application/json')
    )
        throw new Error(
            'Sign in online as a resident to unlock your offline drafts.',
        );
    const context = await response.json();
    const channel = new MessageChannel();
    await new Promise<void>((resolve, reject) => {
        const timeout = setTimeout(
            () =>
                reject(new Error('Offline certificate preparation timed out.')),
            15000,
        );
        channel.port1.onmessage = (event) => {
            clearTimeout(timeout);
            if (event.data.ok) resolve();
            else
                reject(
                    new Error(
                        'Your session changed. Sign in again to prepare offline requests.',
                    ),
                );
        };
        worker.postMessage({ type: 'KB_CERT_ACTIVATE', context }, [
            channel.port2,
        ]);
    });
    return offlineCertificateStore();
}
