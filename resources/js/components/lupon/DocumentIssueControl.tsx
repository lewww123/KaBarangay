import { useForm } from '@inertiajs/react';
import { Errors } from './WorkflowPanel';

export default function DocumentIssueControl({ documentId }: { documentId: number }) {
    const form = useForm({ issued_at: '', content_reviewed: false });
    return <details className="rounded border p-2 text-xs"><summary className="cursor-pointer font-semibold">Record actual issuance</summary>
        <form className="mt-2 space-y-2" onSubmit={e => { e.preventDefault(); if (!window.confirm('Record this actual issuance? Service remains a separate action.')) return; form.post(`/lupon/documents/${documentId}/issue`, { preserveScroll: true }); }}>
            <p>Review the document before release. The configurable system template is not a prescribed official form and requires legal review before official use.</p>
            <label className="block">Actual issuance date and time<input required type="datetime-local" className="mt-1 block rounded border p-2" value={form.data.issued_at} onChange={e => form.setData('issued_at', e.target.value)} /></label>
            <label className="flex gap-2"><input required type="checkbox" checked={form.data.content_reviewed} onChange={e => form.setData('content_reviewed', e.target.checked)} />I reviewed the actual content and required signatures for release.</label>
            <Errors errors={form.errors} /><button disabled={form.processing} className="rounded bg-brand px-3 py-2 text-white">Save issuance</button>
        </form>
    </details>;
}
