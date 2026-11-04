/** Static, read-only HTML. Never persist live scripts, form values or bootstrap props. */
export function offlineSnapshot(savedAt: number): string {
    const copy = document.documentElement.cloneNode(true) as HTMLElement;
    copy.querySelectorAll(
        'script, iframe, object, embed, img, canvas, video, audio, dialog, [role="dialog"], [role="alert"], .docx-wrapper, #kabarangay-ai-chat, [data-kb-offline-control], link[rel="modulepreload"], link[rel="preload"]',
    ).forEach((node) => node.remove());
    copy.querySelectorAll(
        'meta[name="csrf-token"], meta[http-equiv], base',
    ).forEach((node) => node.remove());
    copy.querySelectorAll(
        'input, textarea, select, button, [contenteditable]',
    ).forEach((node) => node.remove());
    copy.querySelectorAll('form').forEach((form) => {
        const notice = document.createElement('p');
        notice.textContent =
            'Kailangan ng internet para gamitin ang form na ito.';
        form.replaceWith(notice);
    });
    for (const node of copy.querySelectorAll('*')) {
        const attributes = Array.from(node.attributes);
        for (const attribute of attributes) {
            if (
                /^on/i.test(attribute.name) ||
                [
                    'srcdoc',
                    'data-page',
                    'contenteditable',
                    'autofocus',
                    'nonce',
                ].includes(attribute.name)
            )
                node.removeAttribute(attribute.name);
        }
        if (/url\s*\(|data:/i.test(node.getAttribute('style') ?? ''))
            node.removeAttribute('style');
    }
    for (const link of copy.querySelectorAll('a[href]')) {
        const href = link.getAttribute('href') ?? '';
        if (href.startsWith('#')) continue;
        if (href === '/offline-certificates.html') continue;
        if (href.startsWith('/offline-attendance.html?event=')) continue;
        const url = new URL(href, location.origin);
        if (
            url.origin !== location.origin ||
            !/^\/(resident|admin|clerk|lupon|pwd|senior)(\/|$)/.test(
                url.pathname,
            ) ||
            /\/(documents?|evidence|file|download|print|verify|logout|reset-password|application)(\/|$)/.test(
                url.pathname,
            )
        ) {
            link.removeAttribute('href');
            link.setAttribute('aria-disabled', 'true');
        } else link.setAttribute('href', url.pathname + url.search);
    }
    const head = copy.querySelector('head')!;
    const style = document.createElement('style');
    style.textContent =
        '#kb-offline-notice{position:relative;z-index:99999;background:#fff4cf;color:#382b06;padding:16px 20px;font:14px/1.6 system-ui,sans-serif;border-bottom:1px solid #dfc66b}#kb-offline-notice a{color:inherit;text-decoration:underline;margin-right:16px}#kb-offline-notice button{display:inline-block;padding:5px 10px;border:1px solid currentColor;border-radius:6px;background:transparent;color:inherit;cursor:pointer}dialog,[role=dialog]{position:static!important;inset:auto!important;max-width:100%!important;width:auto!important;height:auto!important}a[aria-disabled=true]{cursor:default;opacity:.7}';
    head.append(style);
    const script = document.createElement('script');
    script.src = '/offline-viewer.js';
    script.defer = true;
    head.append(script);
    const notice = document.createElement('aside');
    notice.id = 'kb-offline-notice';
    notice.setAttribute('role', 'status');
    const description = document.createElement('p');
    description.textContent =
        'Offline copy · Read-only · Huling na-save: ' +
        new Date(savedAt).toLocaleString('en-PH', { timeZone: 'Asia/Manila' });
    const detail = document.createElement('p');
    detail.textContent =
        'Hindi live ang impormasyong ito. Kailangan ng internet para mag-submit, magbayad, mag-approve o mag-update ng records. Ang tabs at filters na hindi link ay available kapag online.';
    const pages = document.createElement('a');
    pages.href = '/offline.html';
    pages.textContent = 'Mga saved page';
    const reload = document.createElement('button');
    reload.type = 'button';
    reload.dataset.offlineAction = 'reload';
    reload.textContent = 'Subukan ulit online';
    const logout = document.createElement('button');
    logout.type = 'button';
    logout.dataset.offlineAction = 'logout';
    logout.textContent = 'Log out';
    notice.append(description, detail, pages, reload, logout);
    if (location.pathname.startsWith('/resident')) {
        const drafts = document.createElement('a');
        drafts.href = '/offline-certificates.html';
        drafts.textContent = 'Offline certificate drafts & requests';
        notice.append(drafts);
    }
    copy.querySelector('body')!.prepend(notice);
    return '<!doctype html>\n' + copy.outerHTML;
}
