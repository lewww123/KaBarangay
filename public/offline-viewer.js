(async () => {
    const talk = async (message) => {
        const registration = await navigator.serviceWorker.ready;
        return new Promise((resolve, reject) => {
            const channel = new MessageChannel();
            const timer = setTimeout(() => reject(new Error('Offline storage unavailable.')), 5000);
            channel.port1.onmessage = event => { clearTimeout(timer); resolve(event.data); };
            (navigator.serviceWorker.controller || registration.active).postMessage(message, [channel.port2]);
        });
    };
    for (const button of document.querySelectorAll('[data-offline-action]')) {
        button.addEventListener('click', async () => {
            if (button.dataset.offlineAction === 'reload') { location.reload(); return; }
            if (button.dataset.offlineAction === 'logout') {
                button.disabled = true;
                await talk({ type: 'KB_LOGOUT', path: location.pathname.startsWith('/resident') ? '/resident/logout' : '/logout' });
                location.replace('/offline.html?loggedout=1');
            }
        });
    }
    const list = document.getElementById('kb-offline-pages');
    const message = document.getElementById('kb-offline-message');
    if (!list || !message || !('serviceWorker' in navigator)) return;
    const attendance = document.createElement('a');
    attendance.href = '/offline-attendance.html'; attendance.textContent = 'Offline staff attendance · manual, QR & sync';
    list.before(attendance);
    try {
        const data = await talk({ type: 'KB_LIST' });
        message.textContent = data.pendingLogout ? 'Na-clear na ang offline copies. Tatapusin ang server logout kapag bumalik ang internet.' : data.pages?.length ? 'Piliin ang page na gusto mong basahin.' : 'Wala pang saved page. Mag-sign in at magbukas muna ng mga page habang online.';
        if (new URLSearchParams(location.search).has('write-unconfirmed')) message.textContent = 'Hindi makumpirma ang pag-save dahil sa koneksiyon. Walang aksiyong naka-queue. Kumonekta at suriin ang record bago ulitin.';
        for (const item of data.pages || []) {
            const row = document.createElement('li');
            const link = document.createElement('a'); link.href = item.path; link.textContent = item.title;
            const time = document.createElement('small'); time.textContent = 'Huling na-save: ' + new Date(item.savedAt).toLocaleString('en-PH', { timeZone: 'Asia/Manila' });
            row.append(link, time); list.append(row);
        }
    } catch { message.textContent = 'Hindi mabasa ang offline storage. Kailangan munang buksan ang system habang online.'; }
})();
