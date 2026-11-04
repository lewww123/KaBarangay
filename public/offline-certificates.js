(() => {
    const store = globalThis.KBCertificates;
    const $ = id => document.getElementById(id);
    let context, editing = null, reviewing = null, busy = false, displayedCertificate = null, renderEpoch = 0;
    const labels = { draft: 'Draft', pending: 'Pending sync', syncing: 'Syncing…', 'needs-review': 'Needs review', 'login-required': 'Sign in required', synced: 'Synced' };
    const message = (text, error = false) => { $('message').textContent = text; $('message').className = text ? 'note ' + (error ? 'warning' : '') : ''; };
    const money = fee => '₱' + Number(fee).toLocaleString('en-PH', { minimumFractionDigits: 2 });
    const selected = () => context?.certificates.find(item => String(item.id) === $('certificate').value);
    const text = (tag, value, className) => { const element = document.createElement(tag); element.textContent = value; if (className) element.className = className; return element; };
    const tell = async (data) => {
        const registration = await navigator.serviceWorker.ready;
        return new Promise((resolve, reject) => { const channel = new MessageChannel(); const timeout = setTimeout(() => reject(new Error('Offline support is not ready.')), 15000); channel.port1.onmessage = event => { clearTimeout(timeout); resolve(event.data); }; (navigator.serviceWorker.controller || registration.active).postMessage(data, [channel.port2]); });
    };
    const formData = () => ({ certificate_id: $('certificate').value, catalog_revision: displayedCertificate?.revision, purpose: $('purpose').value.trim(), business_name: $('business-name').value.trim(), business_address: $('business-address').value.trim(), additional_details: $('notes').value.trim(), details: Object.fromEntries([...$('fields').querySelectorAll('input')].map(input => [input.dataset.key, input.value.trim()])) });
    function certificateFields(data = {}) {
        const certificate = selected(); $('fields').replaceChildren(); $('certificate-info').replaceChildren();
        displayedCertificate = certificate ? structuredClone(certificate) : null;
        $('certificate-info').classList.toggle('hidden', !certificate);
        $('business').hidden = !certificate?.requiresBusinessDetails;
        $('business-name').required = $('business-address').required = Boolean(certificate?.requiresBusinessDetails);
        $('purpose').readOnly = Boolean(certificate?.fixedPurpose);
        if (!certificate) return;
        if (certificate.fixedPurpose) $('purpose').value = certificate.fixedPurpose;
        $('certificate-info').append(text('strong', certificate.name + ' · Saved fee: ' + money(certificate.fee)));
        if (certificate.description) $('certificate-info').append(text('p', certificate.description));
        const requirements = Array.isArray(certificate.requirements) ? certificate.requirements : [certificate.requirements];
        if (requirements.length) { const list = document.createElement('ul'); for (const item of requirements) if (typeof item === 'string') list.append(text('li', item)); $('certificate-info').append(list); }
        for (const field of certificate.detailsFields || []) {
            const label = text('label', field.label + (field.required ? ' *' : '')); const input = document.createElement('input');
            input.type = ['number', 'date'].includes(field.type) ? field.type : 'text'; input.dataset.key = field.key; input.id = 'detail-' + field.key;
            if (input.type === 'number') input.step = field.kind === 'numeric' ? 'any' : '1';
            input.required = Boolean(field.required); input.value = data[field.key] ?? field.default ?? ''; input.placeholder = field.placeholder || '';
            if (field.min !== undefined) input.min = String(field.min);
            if (field.max !== undefined) { if (input.type === 'number') input.max = String(field.max); else input.maxLength = field.max; }
            label.htmlFor = input.id; $('fields').append(label, input);
        }
    }
    function clear() { editing = null; reviewing = null; $('editor').reset(); $('editor-title').textContent = 'New draft'; $('review').hidden = true; $('review-details').replaceChildren(); certificateFields(); }
    async function render() {
        const epoch = ++renderEpoch;
        try {
            const nextContext = await store.context(); const items = await store.list(); const currentContext = await store.context();
            if (epoch !== renderEpoch || nextContext.owner !== currentContext.owner || nextContext.scope !== currentContext.scope) return;
            const ownerChanged = context && context.owner !== nextContext.owner;
            context = nextContext;
            if (ownerChanged) clear();
            $('locked').hidden = true; $('unlocked').hidden = false;
            $('resident').textContent = context.resident.name + ' · ' + context.resident.barangay;
            $('catalog-date').textContent = 'Catalog saved: ' + new Date(context.preparedAt).toLocaleString('en-PH', { timeZone: 'Asia/Manila' });
            const current = $('certificate').value;
            $('certificate').replaceChildren(new Option('Select certificate', ''));
            for (const item of context.certificates) $('certificate').append(new Option(item.name, item.id));
            $('certificate').value = current;
            $('requests').replaceChildren();
            if (!items.length) $('requests').append(text('p', 'No local drafts or queued requests yet.', 'muted'));
            for (const item of items) {
                const card = document.createElement('article'); card.className = 'card';
                card.append(text('span', labels[item.status] || item.status, 'pill'), text('h3', item.data.certificateName), text('small', 'Local ID: ' + item.id, 'muted'));
                if (item.data.form.purpose) card.append(text('p', item.data.form.purpose));
                if (item.data.result) card.append(text('p', 'Official reference: ' + item.data.result.reference), text('p', 'Server status: ' + item.data.result.status + ' · Fee: ' + money(item.data.result.fee)));
                if (item.data.error) card.append(text('p', item.data.error, 'error'));
                const actions = document.createElement('div'); actions.className = 'actions';
                if (['draft', 'needs-review'].includes(item.status)) {
                    const edit = text('button', item.status === 'needs-review' ? 'Review with current catalog' : 'Edit draft');
                    edit.onclick = async () => {
                        if (item.status === 'needs-review' && navigator.onLine) { try { await refresh(); } catch { return; } }
                        editing = item.id; $('editor-title').textContent = 'Edit local draft'; $('certificate').value = item.data.form.certificate_id;
                        $('purpose').value = item.data.form.purpose || ''; $('business-name').value = item.data.form.business_name || ''; $('business-address').value = item.data.form.business_address || ''; $('notes').value = item.data.form.additional_details || '';
                        certificateFields(item.data.form.details || {}); $('editor').scrollIntoView({ behavior: 'smooth' });
                    };
                    const remove = text('button', 'Delete local draft'); remove.onclick = async () => { try { await store.remove(item.id); await render(); } catch (error) { message(error.message, true); } };
                    actions.append(edit, remove);
                }
                if (item.status === 'synced') { const link = text('a', 'Open My Requests'); link.href = '/resident/certificates'; actions.append(link); }
                card.append(actions); $('requests').append(card);
            }
        } catch (error) {
            if (epoch !== renderEpoch) return;
            context = null; editing = reviewing = displayedCertificate = null;
            $('editor').reset(); $('fields').replaceChildren(); $('requests').replaceChildren(); $('resident').textContent = ''; $('certificate-info').replaceChildren();
            $('catalog-date').textContent = ''; $('review-details').replaceChildren(); $('review').hidden = true;
            $('certificate').replaceChildren(new Option('Select certificate', ''));
            $('locked').hidden = false; $('unlocked').hidden = true; message(error.message, true);
        }
        $('connection').textContent = navigator.onLine ? 'Internet available. Server validation is required before a request becomes submitted.' : 'Offline. Drafts and pending requests stay on this device.';
        $('sync').disabled = $('refresh').disabled = !navigator.onLine || busy;
    }
    async function refresh() {
        const response = await fetch('/resident/certificates/offline-context', { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } });
        if (!response.ok || response.redirected || !response.headers.get('Content-Type')?.includes('application/json')) throw new Error('Mag-sign in online bilang resident upang ma-refresh ang catalog.');
        const reply = await tell({ type: 'KB_CERT_ACTIVATE', context: await response.json() });
        if (!reply.ok) throw new Error('Nagbago ang session. Mag-sign in muli bago mag-sync.');
        await render();
    }
    async function sync() {
        if (busy || !navigator.onLine) return;
        busy = true; $('sync').disabled = true;
        try { const result = await store.sync(); message(result.loginRequired ? 'Mag-sign in sa resident account na gumawa ng drafts.' : result.offline || result.retryLater ? 'Pending pa ang ibang requests. Kumonekta at i-sync muli; hindi sila madodoble.' : result.busy ? 'May sync na tumatakbo sa ibang tab.' : 'Sync checked. Ang may official reference lang ang natanggap ng server.'); }
        catch (error) { message(error.message, true); }
        finally { busy = false; await render(); }
    }
    $('certificate').addEventListener('change', () => certificateFields());
    $('new-draft').onclick = clear;
    $('save-draft').onclick = async () => { try { editing = await store.saveDraft(formData(), editing); message('Draft saved on this device. Hindi pa ito submitted.'); await render(); } catch (error) { message(error.message, true); } };
    $('editor').onsubmit = event => {
        event.preventDefault(); reviewing = formData(); $('review-details').replaceChildren();
        const certificate = displayedCertificate; $('review-details').append(text('p', certificate.name + ' · Saved fee: ' + money(certificate.fee)), text('p', reviewing.purpose));
        for (const [key, value] of Object.entries(reviewing.details)) $('review-details').append(text('p', (certificate.detailsFields.find(field => field.key === key)?.label || key) + ': ' + value));
        if (reviewing.business_name) $('review-details').append(text('p', reviewing.business_name + ' · ' + reviewing.business_address));
        if (reviewing.additional_details) $('review-details').append(text('p', reviewing.additional_details));
        $('review').hidden = false; $('review').scrollIntoView({ behavior: 'smooth' });
    };
    $('back').onclick = () => { $('review').hidden = true; };
    $('confirm').onclick = async () => {
        if (!reviewing || $('confirm').disabled) return; $('confirm').disabled = true;
        try { const id = await store.saveDraft(reviewing, editing); await store.queue(id); clear(); message('Pending sync — hindi pa officially submitted.'); await render(); if (navigator.onLine) await sync(); }
        catch (error) { message(error.message, true); }
        finally { $('confirm').disabled = false; }
    };
    $('sync').onclick = sync;
    $('refresh').onclick = () => refresh().catch(error => message(error.message, true));
    globalThis.addEventListener('kb-certificate-queue', () => void render());
    navigator.serviceWorker?.addEventListener('message', event => { if (event.data?.type === 'KB_CERT_QUEUE_CHANGED') void render(); });
    globalThis.addEventListener('online', () => void refresh().then(sync).catch(error => message(error.message, true)));
    globalThis.addEventListener('offline', () => void render());
    setInterval(() => { if (navigator.onLine) void sync(); }, 30000);
    void render().then(() => { if (navigator.onLine) void refresh().then(sync).catch(error => message(error.message, true)); });
})();
