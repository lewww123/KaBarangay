import { caseSaveOptions } from './CaseSaveFeedback';
import { useState } from 'react';
import { useForm } from '@inertiajs/react';
import DocxPreview from '@/components/DocxPreview';
import type { LuponDocument } from './types';
import { Errors } from './WorkflowPanel';

export default function SettlementDocument({ caseId, document }: { caseId: number; document?: LuponDocument }) {
    const [ready, setReady] = useState(false);
    const [printing, setPrinting] = useState(false);
    const [error, setError] = useState('');
    const generate = useForm({ kind: 'amicable_settlement' });
    const print = async () => {
        if (!document || !ready || printing) return;
        setPrinting(true);
        setError('');
        try {
            const response = await fetch(`/lupon/documents/${document.id}/preview`, { credentials: 'same-origin' });
            if (!response.ok || response.redirected) throw new Error('Unable to load the settlement document.');
            const blob = await response.blob();
            const signature = new Uint8Array(await blob.slice(0, 2).arrayBuffer());
            if (signature[0] !== 0x50 || signature[1] !== 0x4b) throw new Error('Invalid document. Please reopen the preview.');
            const { printCertificateDocument } = await import('@/lib/certificateDocument');
            await printCertificateDocument(blob, 'Amicable Settlement');
        } catch (failure) { setError(failure instanceof Error ? failure.message : 'Unable to print.'); }
        finally { setPrinting(false); }
    };
    if (!document?.url || document.status !== 'ready') return <section className="mt-4 space-y-3 rounded-xl border p-4">
        <p className="text-sm">Naitala na ang kasunduan. Kailangan ang nakatalagang Punong Barangay o acting captain bago maihanda ang DOCX.</p>
        <button type="button" disabled={generate.processing} onClick={() => generate.post(`/lupon/cases/${caseId}/documents`, { ...caseSaveOptions })} className="rounded-lg border px-4 py-2 text-sm">{generate.processing ? 'Preparing settlement DOCX…' : 'Prepare settlement DOCX'}</button>
        <Errors errors={generate.errors} />
    </section>;
    return <section className="mt-4 space-y-3">
        <div className="flex flex-wrap gap-3">
            <a href={document.url} className="rounded-lg bg-emerald-800 px-4 py-2 text-sm text-white">Download Amicable Settlement DOCX</a>
            <button type="button" disabled={!ready || printing} onClick={print} className="rounded-lg border px-4 py-2 text-sm disabled:opacity-40">{printing ? 'Opening print view…' : 'Print settlement'}</button>
        </div>
        {error && <p role="alert" className="text-sm text-red-700">{error}</p>}
        <div className="max-h-[50vh] overflow-auto rounded-lg bg-stone-100 p-3"><DocxPreview key={document.id} url={`/lupon/documents/${document.id}/preview`} onReady={setReady} /></div>
    </section>;
}
