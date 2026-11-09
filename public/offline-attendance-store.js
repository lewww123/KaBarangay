/* Account-bound, encrypted attendance outbox. A local entry is never a server receipt. */
(() => {
    let opening;
    const db = () =>
        (opening ||= new Promise((resolve, reject) => {
            const open = indexedDB.open('kabarangay-offline-attendance-v1', 1);
            open.onupgradeneeded = () => {
                for (const name of ['meta', 'sheets', 'entries'])
                    open.result.createObjectStore(name, { keyPath: 'id' });
            };
            open.onsuccess = () => resolve(open.result);
            open.onerror = () => reject(Error('Local storage is unavailable.'));
        }));
    const read = async (store, id) => {
        const database = await db();
        return new Promise((resolve, reject) => {
            const tx = database.transaction(store);
            const request = id
                ? tx.objectStore(store).get(id)
                : tx.objectStore(store).getAll();
            request.onsuccess = () => resolve(request.result);
            request.onerror = reject;
        });
    };
    const active = async () => {
        const value = await read('meta', 'active');
        if (!value)
            throw Error(
                'Sign in online and prepare your attendance sheet first.',
            );
        return value;
    };
    const key = (value) =>
        crypto.subtle.importKey(
            'raw',
            Uint8Array.from(atob(value), (char) => char.charCodeAt(0)),
            'AES-GCM',
            false,
            ['encrypt', 'decrypt'],
        );
    const encrypt = async (data, secret) => {
        const iv = crypto.getRandomValues(new Uint8Array(12));
        return {
            iv,
            bytes: await crypto.subtle.encrypt(
                { name: 'AES-GCM', iv },
                await key(secret),
                new TextEncoder().encode(JSON.stringify(data)),
            ),
        };
    };
    const decrypt = async (data, secret) =>
        JSON.parse(
            new TextDecoder().decode(
                await crypto.subtle.decrypt(
                    { name: 'AES-GCM', iv: data.iv },
                    await key(secret),
                    data.bytes,
                ),
            ),
        );
    const write = async (store, record, session) => {
        const database = await db();
        await new Promise((resolve, reject) => {
            const tx = database.transaction(['meta', store], 'readwrite');
            const check = tx.objectStore('meta').get('active');
            check.onsuccess = () => {
                if (
                    check.result?.owner !== session.owner ||
                    check.result?.scope !== session.scope
                ) {
                    tx.abort();
                    return;
                }
                tx.objectStore(store).put(record);
            };
            tx.oncomplete = resolve;
            tx.onerror = tx.onabort = () =>
                reject(Error('Account changed. Local action was not saved.'));
        });
    };
    const activate = async (context) => {
        if (
            !/^[a-f0-9]{64}$/.test(context.owner || '') ||
            !context.scope ||
            !context.key ||
            !context.event ||
            !Array.isArray(context.residents)
        )
            throw Error('Invalid attendance preparation.');
        const encrypted = await encrypt(context, context.key);
        const database = await db();
        await new Promise((resolve, reject) => {
            const tx = database.transaction(['meta', 'sheets'], 'readwrite');
            tx.objectStore('meta').put({
                id: 'active',
                owner: context.owner,
                scope: context.scope,
                key: context.key,
            });
            tx.objectStore('sheets').put({
                id: context.owner + ':' + context.event.id,
                owner: context.owner,
                encrypted,
            });
            tx.oncomplete = resolve;
            tx.onerror = tx.onabort = reject;
        });
    };
    const lock = async () => {
        const database = await db();
        await new Promise((resolve, reject) => {
            const tx = database.transaction('meta', 'readwrite');
            tx.objectStore('meta').delete('active');
            tx.oncomplete = resolve;
            tx.onerror = reject;
        });
    };
    const sheets = async () => {
        const session = await active();
        return Promise.all(
            (await read('sheets'))
                .filter((item) => item.owner === session.owner)
                .map((item) => decrypt(item.encrypted, session.key)),
        );
    };
    const list = async () => {
        const session = await active();
        return Promise.all(
            (await read('entries'))
                .filter((item) => item.owner === session.owner)
                .map(async (item) => ({
                    id: item.id,
                    status: item.status,
                    data: await decrypt(item.encrypted, session.key),
                })),
        );
    };
    const hash = async (value) =>
        [
            ...new Uint8Array(
                await crypto.subtle.digest(
                    'SHA-256',
                    new TextEncoder().encode(value),
                ),
            ),
        ]
            .map((byte) => byte.toString(16).padStart(2, '0'))
            .join('');
    const token = (value) => {
        value = value.trim();
        if (!value.includes('/')) return value;
        try {
            const url = new URL(value);
            const match = url.pathname.match(/^\/verify\/resident\/([^/]+)$/);
            return match ? decodeURIComponent(match[1]) : '';
        } catch {
            return '';
        }
    };
    const enqueue = async (eventId, selection, status, method) => {
        const session = await active();
        const sheet = (await sheets()).find(
            (item) => item.event.id === Number(eventId),
        );
        if (!sheet || Date.now() > sheet.expiresAt)
            throw Error(
                'This offline roster expired. Prepare this sheet online again.',
            );
        const scanned = method === 'qr_scanner' ? token(selection) : null;
        const qrHash = scanned ? await hash(scanned) : null;
        const resident = sheet.residents.find((item) =>
            method === 'manual'
                ? item.id === Number(selection)
                : qrHash && item.qrHash === qrHash,
        );
        if (
            !resident ||
            !['present', 'claimed'].includes(status) ||
            !['manual', 'qr_scanner'].includes(method)
        )
            throw Error(
                'Resident or QR is not in this prepared eligible roster.',
            );
        const entries = await list();
        if (
            entries.some(
                (item) =>
                    item.data.eventId === sheet.event.id &&
                    item.data.payload.resident_id === resident.id &&
                    !['rejected'].includes(item.status),
            )
        )
            throw Error(
                'This resident already has a local attendance entry. Check its sync result first.',
            );
        if (entries.filter((item) => item.status !== 'synced').length >= 500)
            throw Error(
                'Sync the existing 500 pending entries before recording more.',
            );
        const id = crypto.randomUUID();
        const data = {
            eventId: sheet.event.id,
            department: sheet.event.department,
            name: resident.name,
            payload: {
                client_request_id: id,
                offline_owner: session.owner,
                grant: sheet.grant,
                resident_id: resident.id,
                status,
                method,
                qr_token: scanned,
                captured_at: new Date().toISOString(),
            },
        };
        await write(
            'entries',
            {
                id,
                owner: session.owner,
                status: 'pending',
                encrypted: await encrypt(data, session.key),
            },
            session,
        );
        return id;
    };
    const timedFetch = async (url, options = {}) => {
        const controller = new AbortController();
        const timeout = setTimeout(() => controller.abort(), 30000);
        try {
            return await fetch(url, {
                ...options,
                credentials: 'same-origin',
                cache: 'no-store',
                signal: controller.signal,
            });
        } finally {
            clearTimeout(timeout);
        }
    };
    const syncWork = async () => {
        const session = await active();
        let server;
        try {
            const response = await timedFetch('/offline/session', {
                headers: { Accept: 'application/json' },
            });
            server = await response.json();
        } catch {
            return { offline: true };
        }
        // An unavailable database is not an account switch: retry later without touching the queue.
        if (server?.identity === 'unresolved') return { offline: true };
        if (server.scope !== session.scope || !server.csrfToken)
            return { loginRequired: true };
        for (const entry of (await list()).filter(
            (item) => item.status === 'pending',
        )) {
            const current = await active();
            if (
                current.scope !== session.scope ||
                current.owner !== session.owner
            )
                return { loginRequired: true };
            let response, result;
            try {
                response = await timedFetch(
                    `/attendance/offline/${entry.data.department}/${entry.data.eventId}/sync`,
                    {
                        method: 'POST',
                        headers: {
                            Accept: 'application/json',
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': server.csrfToken,
                        },
                        body: JSON.stringify(entry.data.payload),
                    },
                );
                if (response.redirected || [401, 419].includes(response.status))
                    return { loginRequired: true };
                if (response.status === 429 || response.status >= 500)
                    return { retryLater: true };
                result = await response.json();
            } catch {
                return { offline: true };
            }
            const accepted = response.ok && result.result?.outcome;
            if (response.ok && !accepted) return { retryLater: true };
            const data = {
                ...entry.data,
                result: accepted
                    ? result.result
                    : {
                          message:
                              Object.values(result.errors || {}).flat()[0] ||
                              result.message ||
                              'Server rejected this entry. Review online.',
                      },
            };
            await write(
                'entries',
                {
                    id: entry.id,
                    owner: session.owner,
                    status: accepted ? 'synced' : 'rejected',
                    encrypted: await encrypt(data, session.key),
                },
                session,
            );
        }
        return { ok: true };
    };
    const sync = () =>
        globalThis.navigator?.locks
            ? navigator.locks.request(
                  'kb-attendance-sync',
                  { ifAvailable: true },
                  (lease) => (lease ? syncWork() : { busy: true }),
              )
            : syncWork();
    const clearResults = async () => {
        const session = await active();
        const database = await db();
        await new Promise((resolve, reject) => {
            const tx = database.transaction(['meta', 'entries'], 'readwrite');
            const check = tx.objectStore('meta').get('active');
            check.onsuccess = () => {
                if (
                    check.result?.owner !== session.owner ||
                    check.result?.scope !== session.scope
                ) {
                    tx.abort();
                    return;
                }
                const cursor = tx.objectStore('entries').openCursor();
                cursor.onsuccess = () => {
                    const row = cursor.result;
                    if (!row) return;
                    if (
                        row.value.owner === session.owner &&
                        ['synced', 'rejected'].includes(row.value.status)
                    )
                        row.delete();
                    row.continue();
                };
            };
            tx.oncomplete = resolve;
            tx.onerror = tx.onabort = () =>
                reject(Error('Could not clear local results.'));
        });
    };
    globalThis.KBAttendance = {
        activate,
        lock,
        sheets,
        list,
        enqueue,
        sync,
        clearResults,
    };
})();
