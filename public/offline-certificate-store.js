/* Shared by the app, offline editor and service worker. No credentials in queued payloads. */
(() => {
    const DB = 'kabarangay-offline-certificates-v1';
    let opening;
    const database = () =>
        (opening ||= new Promise((resolve, reject) => {
            const request = indexedDB.open(DB, 1);
            request.onupgradeneeded = () => {
                const db = request.result;
                db.createObjectStore('meta', { keyPath: 'id' });
                db.createObjectStore('contexts', { keyPath: 'id' });
                const items = db.createObjectStore('requests', {
                    keyPath: 'id',
                });
                items.createIndex('owner', 'owner');
            };
            request.onsuccess = () => {
                request.result.onversionchange = () => request.result.close();
                resolve(request.result);
            };
            request.onerror = () =>
                reject(new Error('Hindi mabuksan ang local storage.'));
        }));
    const one = async (store, method, value) => {
        const db = await database();
        return new Promise((resolve, reject) => {
            const tx = db.transaction(
                store,
                ['get', 'getAll'].includes(method) ? 'readonly' : 'readwrite',
            );
            const request = tx.objectStore(store)[method](value);
            let result;
            request.onsuccess = () => {
                result = request.result;
            };
            tx.oncomplete = () => resolve(result);
            tx.onerror = tx.onabort = () =>
                reject(
                    new Error(
                        'Hindi ma-save ang local data. Maaaring puno o disabled ang browser storage.',
                    ),
                );
        });
    };
    const notify = async () => {
        if (typeof clients !== 'undefined')
            for (const client of await clients.matchAll({ type: 'window' }))
                client.postMessage({ type: 'KB_CERT_QUEUE_CHANGED' });
        else globalThis.dispatchEvent(new Event('kb-certificate-queue'));
    };
    const active = async () => {
        const value = await one('meta', 'get', 'active');
        if (!value)
            throw new Error(
                'Mag-sign in online bilang resident upang ma-unlock ang sariling drafts.',
            );
        return value;
    };
    const keyFor = (key) =>
        crypto.subtle.importKey(
            'raw',
            Uint8Array.from(atob(key), (char) => char.charCodeAt(0)),
            'AES-GCM',
            false,
            ['encrypt', 'decrypt'],
        );
    const encrypt = async (value, key) => {
        const iv = crypto.getRandomValues(new Uint8Array(12));
        return {
            iv,
            bytes: await crypto.subtle.encrypt(
                { name: 'AES-GCM', iv },
                await keyFor(key),
                new TextEncoder().encode(JSON.stringify(value)),
            ),
        };
    };
    const decrypt = async (value, key) =>
        JSON.parse(
            new TextDecoder().decode(
                await crypto.subtle.decrypt(
                    { name: 'AES-GCM', iv: value.iv },
                    await keyFor(key),
                    value.bytes,
                ),
            ),
        );
    const ownedWrite = async (record, session, expected) => {
        const db = await database();
        await new Promise((resolve, reject) => {
            const tx = db.transaction(['meta', 'requests'], 'readwrite');
            const check = tx.objectStore('meta').get('active');
            check.onsuccess = () => {
                if (
                    check.result?.owner !== session.owner ||
                    check.result?.scope !== session.scope
                ) {
                    tx.abort();
                    return;
                }
                const requests = tx.objectStore('requests');
                if (expected !== undefined) {
                    const previous = requests.get(record.id);
                    previous.onsuccess = () => {
                        if (
                            previous.result?.status !== expected?.status ||
                            previous.result?.updatedAt !== expected?.updatedAt
                        ) {
                            tx.abort();
                            return;
                        }
                        requests.put(record);
                    };
                } else requests.put(record);
            };
            tx.oncomplete = resolve;
            tx.onerror = tx.onabort = () =>
                reject(
                    new Error(
                        'Nagbago ang account o draft sa ibang tab. Buksan muli bago mag-save.',
                    ),
                );
        });
        await notify();
    };
    const activate = async (context) => {
        if (
            !/^[a-f0-9]{64}$/.test(context.owner || '') ||
            !context.key ||
            !context.scope ||
            !Array.isArray(context.certificates)
        )
            throw new Error('Invalid offline context.');
        const encrypted = await encrypt(context, context.key);
        const db = await database();
        await new Promise((resolve, reject) => {
            const tx = db.transaction(['contexts', 'meta'], 'readwrite');
            tx.objectStore('contexts').put({ id: context.owner, encrypted });
            tx.objectStore('meta').put({
                id: 'active',
                owner: context.owner,
                key: context.key,
                scope: context.scope,
            });
            tx.oncomplete = resolve;
            tx.onerror = tx.onabort = reject;
        });
        await notify();
    };
    const lock = async () => {
        await one('meta', 'delete', 'active');
        await notify();
    };
    const context = async () => {
        const session = await active();
        const saved = await one('contexts', 'get', session.owner);
        if (!saved)
            throw new Error(
                'Buksan muna online ang Certificate Requests upang maihanda ang offline form.',
            );
        return decrypt(saved.encrypted, session.key);
    };
    const list = async () => {
        const session = await active();
        const db = await database();
        const records = await new Promise((resolve, reject) => {
            const tx = db.transaction('requests');
            const read = tx
                .objectStore('requests')
                .index('owner')
                .getAll(session.owner);
            read.onsuccess = () => resolve(read.result);
            read.onerror = reject;
        });
        return Promise.all(
            records
                .sort((a, b) => b.updatedAt - a.updatedAt)
                .map(async (record) => ({
                    ...record,
                    data: await decrypt(record.encrypted, session.key),
                    encrypted: undefined,
                })),
        );
    };
    const saveDraft = async (form, id) => {
        const session = await active();
        const previous = id ? await one('requests', 'get', id) : null;
        if (
            previous &&
            (previous.owner !== session.owner ||
                !['draft', 'needs-review'].includes(previous.status))
        )
            throw new Error(
                'Hindi maaaring baguhin ang pending o submitted request. I-sync muna upang makumpirma ang resulta.',
            );
        const catalog = await context();
        const certificate = catalog.certificates.find(
            (item) => String(item.id) === String(form.certificate_id),
        );
        if (!certificate)
            throw new Error(
                'Pumili ng certificate sa kasalukuyang saved catalog.',
            );
        if (
            !previous &&
            (await list()).filter((item) => item.status !== 'synced').length >=
                50
        )
            throw new Error(
                'May 50 unsent drafts na. I-sync o alisin muna ang ibang drafts.',
            );
        const now = Date.now();
        const data = {
            form: {
                ...form,
                certificate_id: String(certificate.id),
                catalog_revision: form.catalog_revision || certificate.revision,
            },
            certificateName: certificate.name,
            estimatedFee: certificate.fee,
        };
        const record = {
            id: id || crypto.randomUUID(),
            owner: session.owner,
            status: 'draft',
            createdAt: previous?.createdAt || now,
            updatedAt: now,
            encrypted: await encrypt(data, session.key),
        };
        await ownedWrite(record, session, previous);
        return record.id;
    };
    const queue = async (id) => {
        const session = await active();
        const record = await one('requests', 'get', id);
        if (
            !record ||
            record.owner !== session.owner ||
            record.status !== 'draft'
        )
            throw new Error('I-save at suriin muna ang draft bago i-queue.');
        await ownedWrite(
            { ...record, status: 'pending', updatedAt: Date.now() },
            session,
            record,
        );
        try {
            const registration = await navigator.serviceWorker.ready;
            if (registration.sync)
                await registration.sync.register('kb-certificate-sync');
        } catch {
            /* Sync now and foreground sync remain available. */
        }
    };
    const remove = async (id) => {
        const session = await active();
        const db = await database();
        await new Promise((resolve, reject) => {
            const tx = db.transaction(['meta', 'requests'], 'readwrite');
            const check = tx.objectStore('meta').get('active');
            check.onsuccess = () => {
                if (
                    check.result?.owner !== session.owner ||
                    check.result?.scope !== session.scope
                ) {
                    tx.abort();
                    return;
                }
                const records = tx.objectStore('requests');
                const read = records.get(id);
                read.onsuccess = () => {
                    if (
                        !read.result ||
                        read.result.owner !== session.owner ||
                        !['draft', 'needs-review', 'synced'].includes(
                            read.result.status,
                        )
                    ) {
                        tx.abort();
                        return;
                    }
                    records.delete(id);
                };
            };
            tx.oncomplete = resolve;
            tx.onerror = tx.onabort = () =>
                reject(
                    new Error(
                        'Hindi ma-delete ang pending request o draft na nagbago sa ibang tab. I-sync muna upang makumpirma ang resulta.',
                    ),
                );
        });
        await notify();
    };
    const lease = async (owner, token, release = false) => {
        const db = await database();
        return new Promise((resolve, reject) => {
            let granted = false;
            const tx = db.transaction('meta', 'readwrite');
            const store = tx.objectStore('meta');
            const read = store.get('sync-lease');
            read.onsuccess = () => {
                const old = read.result;
                if (release) {
                    if (old?.token === token) store.delete('sync-lease');
                    return;
                }
                if (
                    !old ||
                    old.owner !== owner ||
                    old.token === token ||
                    old.expires < Date.now()
                ) {
                    store.put({
                        id: 'sync-lease',
                        owner,
                        token,
                        expires: Date.now() + 60000,
                    });
                    granted = true;
                }
            };
            tx.oncomplete = () => resolve(granted);
            tx.onerror = tx.onabort = reject;
        });
    };
    const timedFetch = async (url, options = {}) => {
        const controller = new AbortController();
        const timer = setTimeout(() => controller.abort(), 30000);
        try {
            return await fetch(url, {
                ...options,
                credentials: 'same-origin',
                signal: controller.signal,
                cache: 'no-store',
            });
        } finally {
            clearTimeout(timer);
        }
    };
    const sync = async () => {
        const session = await active();
        const token = crypto.randomUUID();
        if (!(await lease(session.owner, token))) return { busy: true };
        try {
            let server;
            try {
                const response = await timedFetch('/offline/session', {
                    headers: { Accept: 'application/json' },
                });
                server = await response.json();
            } catch {
                return { offline: true };
            }
            const items = (await list())
                .filter((item) =>
                    ['pending', 'syncing', 'login-required'].includes(
                        item.status,
                    ),
                )
                .reverse();
            for (const item of items) {
                const current = await active();
                if (
                    current.owner !== session.owner ||
                    server.owner !== session.owner
                ) {
                    await ownedWrite(
                        {
                            ...item,
                            encrypted: await encrypt(
                                {
                                    ...item.data,
                                    error: 'Mag-sign in muna sa account na gumawa ng request na ito.',
                                },
                                session.key,
                            ),
                            data: undefined,
                            status: 'login-required',
                            updatedAt: Date.now(),
                        },
                        session,
                    );
                    return { loginRequired: true };
                }
                await lease(session.owner, token);
                const update = async (status, data) =>
                    ownedWrite(
                        {
                            id: item.id,
                            owner: item.owner,
                            createdAt: item.createdAt,
                            updatedAt: Date.now(),
                            status,
                            encrypted: await encrypt(data, session.key),
                        },
                        session,
                    );
                await update('syncing', item.data);
                try {
                    const response = await timedFetch(
                        '/resident/certificates/sync',
                        {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                Accept: 'application/json',
                                'X-CSRF-TOKEN': server.csrfToken,
                            },
                            body: JSON.stringify({
                                ...item.data.form,
                                client_request_id: item.id,
                                offline_owner: session.owner,
                            }),
                        },
                    );
                    if (
                        response.redirected ||
                        [401, 419].includes(response.status)
                    ) {
                        await update('login-required', {
                            ...item.data,
                            error: 'Mag-sign in online bago muling mag-sync.',
                        });
                        return { loginRequired: true };
                    }
                    const result = await response.json();
                    if (response.ok && typeof result.reference === 'string')
                        await update('synced', {
                            ...item.data,
                            result,
                            error: null,
                        });
                    else if ([409, 422].includes(response.status))
                        await update('needs-review', {
                            ...item.data,
                            error:
                                Object.values(result.errors || {})
                                    .flat()
                                    .join(' ') ||
                                result.message ||
                                'Review this request against the current requirements.',
                        });
                    else if (response.status === 403) {
                        await update('login-required', {
                            ...item.data,
                            error: 'Hindi maaaring i-sync gamit ang ibang account o barangay.',
                        });
                        return { loginRequired: true };
                    } else {
                        await update('pending', {
                            ...item.data,
                            error: 'Hindi pa makumpirma ang submission. Ligtas na i-retry ang parehong local request.',
                        });
                        return { retryLater: true };
                    }
                } catch {
                    await update('pending', {
                        ...item.data,
                        error: 'Walang kumpirmasyon mula sa server. I-retry ang parehong request kapag may internet.',
                    });
                    return { offline: true };
                }
            }
            return { ok: true };
        } finally {
            await lease(session.owner, token, true);
        }
    };
    globalThis.KBCertificates = {
        activate,
        lock,
        context,
        list,
        saveDraft,
        queue,
        remove,
        sync,
    };
})();
