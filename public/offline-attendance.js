(() => {
    const store = globalThis.KBAttendance;
    const $ = (id) => document.getElementById(id);
    let sheets = [],
        busy = false,
        stream,
        scanning = false,
        viewEpoch = 0;
    const notice = (message) => {
        $('notice').textContent = message;
    };
    const selected = () =>
        sheets.find((item) => String(item.event.id) === $('event').value);
    const roster = () => {
        const term = $('search').value.trim().toLowerCase();
        $('resident').replaceChildren();
        for (const item of (selected()?.residents || []).filter((item) =>
            (item.name + ' ' + item.number).toLowerCase().includes(term),
        )) {
            const option = document.createElement('option');
            option.value = item.id;
            option.textContent = item.name + ' · ' + item.number;
            $('resident').append(option);
        }
        $('prepared').textContent = selected()
            ? 'Prepared roster expires: ' +
              new Date(selected().expiresAt).toLocaleString()
            : 'No prepared sheet. Open attendance online first.';
    };
    const refresh = async () => {
        const epoch = viewEpoch;
        const items = await store.list();
        if (epoch !== viewEpoch) return;
        $('entries').replaceChildren();
        for (const item of items.filter(
            (item) => String(item.data.eventId) === $('event').value,
        )) {
            const li = document.createElement('li');
            li.textContent = `${item.data.name} · ${item.data.payload.status} · ${item.data.payload.method} · captured ${new Date(item.data.payload.captured_at).toLocaleString()} · ${item.status}${item.data.result ? ': ' + item.data.result.message : ' — not yet confirmed by server'}`;
            $('entries').append(li);
        }
    };
    const save = async (method) => {
        if (busy) return;
        busy = true;
        try {
            await store.enqueue(
                $('event').value,
                method === 'manual' ? $('resident').value : $('qr').value,
                $('status').value,
                method,
            );
            $('qr').value = '';
            notice(
                'Saved on this device · Pending sync. Attendance is not yet confirmed by the server.',
            );
            await refresh();
        } catch (error) {
            notice(error.message);
        } finally {
            busy = false;
        }
    };
    const sync = async () => {
        if (busy || !navigator.onLine) return;
        busy = true;
        $('sync').disabled = true;
        try {
            const result = await store.sync();
            notice(
                result.loginRequired
                    ? 'Session changed. Sign in as the same staff account and prepare this sheet online again.'
                    : result.offline || result.retryLater
                      ? 'No server confirmation. Entries remain pending; retry when connected.'
                      : result.busy
                        ? 'Another tab is syncing.'
                        : 'Sync finished. Review every record for accepted, duplicate, or rejected results.',
            );
            await refresh();
        } catch (error) {
            notice(error.message);
        } finally {
            busy = false;
            $('sync').disabled = false;
        }
    };
    const stopCamera = () => {
        scanning = false;
        stream?.getTracks().forEach((track) => track.stop());
        $('video').hidden = true;
    };
    const lockScreen = () => {
        viewEpoch++;
        stopCamera();
        sheets = [];
        for (const id of ['entries', 'resident', 'event'])
            $(id).replaceChildren();
        $('search').value = '';
        $('qr').value = '';
        $('prepared').textContent = '';
        document
            .querySelectorAll('button:not(#logout)')
            .forEach((button) => (button.disabled = true));
        notice(
            'Account changed or logged out. Sign in online and prepare your own attendance sheet again.',
        );
    };
    const signalKey = 'kabarangay.session-boundary.v1';
    const readSignal = () => { try { return localStorage.getItem(signalKey); } catch { return null; } };
    const initialSignal = readSignal();
    const restorePrivatePage = () => {
        if (readSignal() !== initialSignal) lockScreen();
        document.documentElement.style.visibility = '';
    };
    window.addEventListener('storage', event => { if (event.key === signalKey && event.newValue) lockScreen(); });
    window.addEventListener('pagehide', () => { document.documentElement.style.visibility = 'hidden'; });
    window.addEventListener('pageshow', restorePrivatePage);
    window.addEventListener('focus', restorePrivatePage);
    document.addEventListener('visibilitychange', restorePrivatePage);
    navigator.serviceWorker?.addEventListener('message', (event) => {
        if (event.data?.type === 'KB_PRIVATE_LOCKED') lockScreen();
    });
    $('camera').onclick = async () => {
        if (scanning) {
            stopCamera();
            return;
        }
        if (
            !('BarcodeDetector' in globalThis) ||
            !(await BarcodeDetector.getSupportedFormats()).includes('qr_code')
        ) {
            notice(
                'Camera QR decoding is unavailable in this browser. Use a USB/Bluetooth scanner or manual attendance.',
            );
            return;
        }
        try {
            stream = await navigator.mediaDevices.getUserMedia({
                video: { facingMode: 'environment' },
            });
            $('video').srcObject = stream;
            $('video').hidden = false;
            await $('video').play();
            scanning = true;
            const detector = new BarcodeDetector({ formats: ['qr_code'] });
            const detect = async () => {
                if (!scanning) return;
                try {
                    const matches = await detector.detect($('video'));
                    if (matches.length) {
                        $('qr').value = matches[0].rawValue;
                        stopCamera();
                        await save('qr_scanner');
                        return;
                    }
                } catch {
                    /* A frame may not be ready. */
                }
                setTimeout(detect, 250);
            };
            void detect();
        } catch {
            stopCamera();
            notice(
                'Camera could not start. Allow camera access or use a keyboard scanner/manual attendance.',
            );
        }
    };
    $('manual').onclick = () => save('manual');
    $('scan').onclick = () => save('qr_scanner');
    $('sync').onclick = sync;
    $('clear-results').onclick = async () => {
        try {
            await store.clearResults();
            await refresh();
            notice(
                'Finished local results cleared. Pending entries and server records were preserved.',
            );
        } catch (error) {
            notice(error.message);
        }
    };
    $('qr').onkeydown = (event) => {
        if (event.key === 'Enter') {
            event.preventDefault();
            void save('qr_scanner');
        }
    };
    $('search').oninput = roster;
    $('event').onchange = () => {
        roster();
        void refresh();
    };
    $('logout').onclick = async () => {
        lockScreen();
        await store.lock();
        const registration = await navigator.serviceWorker.getRegistration('/');
        const worker =
            navigator.serviceWorker.controller || registration?.active;
        worker?.postMessage({ type: 'KB_LOGOUT', path: '/logout' });
        location.replace('/offline.html?loggedout=1');
    };
    window.addEventListener('pagehide', stopCamera);
    window.addEventListener('online', sync);
    const timer = setInterval(sync, 30000);
    window.addEventListener('pagehide', () => clearInterval(timer));
    void (async () => {
        const epoch = viewEpoch;
        try {
            const preparedSheets = await store.sheets();
            if (epoch !== viewEpoch) return;
            sheets = preparedSheets;
            for (const item of sheets) {
                const option = document.createElement('option');
                option.value = item.event.id;
                option.textContent = item.event.title;
                $('event').append(option);
            }
            const id = new URLSearchParams(location.search).get('event');
            if (id && sheets.some((item) => String(item.event.id) === id))
                $('event').value = id;
            roster();
            await refresh();
            if (epoch !== viewEpoch) return;
            notice(
                navigator.onLine
                    ? 'Local attendance ready. Sync checks current server permissions and QR validity.'
                    : 'Offline · Manual and keyboard QR entries save as pending sync on this device.',
            );
            void sync();
        } catch (error) {
            if (epoch !== viewEpoch) return;
            notice(error.message);
            document
                .querySelectorAll('button:not(#logout)')
                .forEach((button) => (button.disabled = true));
        }
    })();
})();
