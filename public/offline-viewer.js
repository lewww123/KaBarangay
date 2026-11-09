(async () => {
    const signedOut = document.getElementById('kb-offline-logged-out');
    if (signedOut && new URLSearchParams(location.search).has('loggedout')) {
        document.getElementById('kb-offline-saved').hidden = true;
        signedOut.hidden = false;
        document.title = 'Mag-sign in · KaBarangay';
        return;
    }
    let loggingOut = false;
    let locked = false;
    const signalKey = 'kabarangay.session-boundary.v1';
    const readSignal = () => { try { return localStorage.getItem(signalKey); } catch { return null; } };
    const initialSignal = readSignal();
    const lock = () => {
        if (loggingOut || locked || !document.getElementById('kb-offline-notice')) return;
        locked = true;
        document.documentElement.style.visibility = 'hidden';
        location.replace('/offline.html?loggedout=1');
    };
    navigator.serviceWorker?.addEventListener('message', event => {
        if (event.data?.type === 'KB_PRIVATE_LOCKED') lock();
    });
    window.addEventListener('storage', event => { if (event.key === signalKey && event.newValue) lock(); });
    let leaving = false;
    const restore = (event) => {
        if (event?.type === 'pageshow' && event.persisted && loggingOut) {
            loggingOut = false;
            lock();
        }
        if (readSignal() !== initialSignal) lock();
        if (!locked && leaving) { document.documentElement.style.visibility = ''; leaving = false; }
    };
    window.addEventListener('pagehide', () => {
        if (document.getElementById('kb-offline-notice')) {
            leaving = true;
            document.documentElement.style.visibility = 'hidden';
        }
    });
    window.addEventListener('pageshow', restore);
    window.addEventListener('focus', restore);
    document.addEventListener('visibilitychange', restore);
    const talk = async (message) => {
        const registration = await navigator.serviceWorker.ready;
        return new Promise((resolve, reject) => {
            const channel = new MessageChannel();
            const timer = setTimeout(
                () => reject(new Error('Offline storage unavailable.')),
                5000,
            );
            channel.port1.onmessage = (event) => {
                clearTimeout(timer);
                resolve(event.data);
            };
            (
                navigator.serviceWorker.controller || registration.active
            ).postMessage(message, [channel.port2]);
        });
    };
    for (const button of document.querySelectorAll('[data-offline-action]')) {
        button.addEventListener('click', async () => {
            if (button.dataset.offlineAction === 'reload') {
                location.reload();
                return;
            }
            if (button.dataset.offlineAction === 'logout') {
                loggingOut = true;
                try { localStorage.setItem(signalKey, JSON.stringify({ kind: 'logout', id: crypto.randomUUID() })); } catch {}
                button.disabled = true;
                await talk({
                    type: 'KB_LOGOUT',
                    path: location.pathname.startsWith('/resident')
                        ? '/resident/logout'
                        : '/logout',
                });
                location.replace('/offline.html?loggedout=1');
            }
        });
    }
    const list = document.getElementById('kb-offline-pages');
    const message = document.getElementById('kb-offline-message');
    if (!list || !message || !('serviceWorker' in navigator)) return;
    const attendance = document.createElement('a');
    attendance.href = '/offline-attendance.html';
    attendance.className = 'quick-link';
    attendance.textContent = 'Offline staff attendance · manual, QR & sync';
    list.before(attendance);
    try {
        const data = await talk({ type: 'KB_LIST' });
        message.textContent = data.pendingLogout
            ? 'Na-clear na ang offline copies. Tatapusin ang server logout kapag bumalik ang internet.'
            : data.pages?.length
              ? 'Piliin ang page na gusto mong basahin.'
              : 'Wala pang saved page. Mag-sign in at magbukas muna ng mga page habang online.';
        if (new URLSearchParams(location.search).has('write-unconfirmed'))
            message.textContent =
                'Hindi makumpirma ang pag-save dahil sa koneksiyon. Walang aksiyong naka-queue. Kumonekta at suriin ang record bago ulitin.';
        for (const item of data.pages || []) {
            const row = document.createElement('li');
            const link = document.createElement('a');
            link.href = item.path;
            link.textContent = item.title;
            const time = document.createElement('small');
            time.textContent =
                'Huling na-save: ' +
                new Date(item.savedAt).toLocaleString('en-PH', {
                    timeZone: 'Asia/Manila',
                });
            row.append(link, time);
            list.append(row);
        }
    } catch {
        message.textContent =
            'Hindi mabasa ang offline storage. Kailangan munang buksan ang system habang online.';
    }
})();
