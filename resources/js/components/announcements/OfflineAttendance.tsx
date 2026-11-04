import { useEffect, useState } from 'react';

export default function OfflineAttendance({ announcementId, department }: { announcementId: number; department: 'clerk' | 'pwd' | 'senior' }) {
    const [busy, setBusy] = useState(false);
    const [message, setMessage] = useState('Prepare this attendance sheet online before using it without internet.');
    const [online, setOnline] = useState(typeof navigator === 'undefined' || navigator.onLine);
    useEffect(() => {
        const update = () => setOnline(navigator.onLine);
        window.addEventListener('online', update); window.addEventListener('offline', update);
        return () => { window.removeEventListener('online', update); window.removeEventListener('offline', update); };
    }, []);
    const prepare = async () => {
        setBusy(true);
        try {
            const registration = await navigator.serviceWorker?.getRegistration('/');
            const worker = navigator.serviceWorker?.controller || registration?.active;
            if (!worker) throw new Error('Offline support is still preparing. Reload the production site and try again.');
            const response = await fetch(`/attendance/offline/${department}/${announcementId}/context`, { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } });
            if (!response.ok || response.redirected) throw new Error('Unable to prepare this roster. Check your connection and attendance permissions.');
            const context = await response.json();
            const channel = new MessageChannel();
            await new Promise<void>((resolve, reject) => {
                const timer = setTimeout(() => reject(new Error('Preparation timed out. Please try again.')), 20000);
                channel.port1.onmessage = event => { clearTimeout(timer); event.data.ok ? resolve() : reject(new Error('Session changed. Sign in again and prepare the sheet.')); };
                worker.postMessage({ type: 'KB_ATTENDANCE_ACTIVATE', context }, [channel.port2]);
            });
            setMessage('Ready for 24 hours on this device. Offline records remain pending until the server confirms sync.');
        } catch (error) { setMessage(error instanceof Error ? error.message : 'Offline preparation failed.'); }
        finally { setBusy(false); }
    };
    return <section className="my-4 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-950">
        <p className="font-semibold">Offline attendance · Manual & QR</p>
        <p role="status" className="mt-1">{message}</p>
        <button type="button" disabled={busy || !online} onClick={() => void prepare()} className="mr-4 mt-2 rounded border border-amber-800 px-3 py-2 disabled:opacity-50">{busy ? 'Preparing…' : 'Prepare / refresh roster'}</button>
        <a href={`/offline-attendance.html?event=${announcementId}`} className="font-semibold underline">Open offline attendance & sync</a>
    </section>;
}
