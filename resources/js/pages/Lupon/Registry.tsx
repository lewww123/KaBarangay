import { Head, Link, router, useForm } from '@inertiajs/react';
import LuponLayout from '@/layouts/LuponLayout';
import PortalPageSkeleton from '@/components/PortalPageSkeleton';
import withDeferredPage from '@/components/withDeferredPage';
import { luponStatusLabel } from '@/components/lupon/types';

type Module = 'pangkat' | 'arbitration' | 'documents' | 'records';
type Row = { id: number; caseId: number; caseNumber: string; title: string; status: string; date: string | null; details: string };
type Props = { module: Module; filters: { search?: string; status?: string; from?: string; to?: string }; statuses: Record<string, string>; records: { data: Row[]; total: number; current_page: number; last_page: number; prev_page_url: string | null; next_page_url: string | null } };
const titles = { pangkat: 'Pangkat & Conciliation', arbitration: 'Arbitration & Awards', documents: 'Summons & Documents', records: 'Case Records' };
export default withDeferredPage(Registry, ['records'], p => <LuponLayout active={p.module}><PortalPageSkeleton rows={6} /></LuponLayout>);
function Registry({ module, filters, statuses, records }: Props) {
    const form = useForm({ search: filters.search || '', status: filters.status || '', from: filters.from || '', to: filters.to || '' });
    return <LuponLayout active={module}><Head title={titles[module]} /><div className="mx-auto max-w-7xl space-y-5">
        <header><h1 className="text-2xl font-semibold">{titles[module]}</h1><p className="mt-1 text-sm text-[#8a7f6a]">Recorded case information from your barangay. Open a case to view details and record authorized actions.</p></header>
        <form className="flex flex-wrap items-end gap-3 rounded-xl border border-[#e6ddcd] bg-white p-4" onSubmit={e => { e.preventDefault(); router.get(`/lupon/${module}`, form.data, { preserveState: true }); }}>
            <label className="text-sm">Search<input className="mt-1 block rounded border p-2" value={form.data.search} onChange={e=>form.setData('search',e.target.value)} placeholder="Case number, party or reference" /></label>
            <label className="text-sm">Status<select className="mt-1 block rounded border p-2" value={form.data.status} onChange={e=>form.setData('status',e.target.value)}><option value="">All statuses</option>{Object.entries(module === 'documents' ? { pending:'Pending', ready:'Prepared', superseded:'Superseded' } : statuses).map(([k,v])=><option key={k} value={k}>{v}</option>)}</select></label>
            <label className="text-sm">From<input type="date" className="mt-1 block rounded border p-2" value={form.data.from} onChange={e=>form.setData('from',e.target.value)} /></label>
            <label className="text-sm">To<input type="date" className="mt-1 block rounded border p-2" value={form.data.to} onChange={e=>form.setData('to',e.target.value)} /></label>
            <button className="rounded bg-[#3f6f3d] px-4 py-2 text-white">Apply filters</button>
        </form>
        <div className="overflow-x-auto rounded-xl border border-[#e6ddcd] bg-white"><table className="w-full text-left text-sm"><thead className="bg-[#f0ebe1]"><tr>{['Case number','Record','Details','Status','Date','Actions'].map(t=><th key={t} className="p-3">{t}</th>)}</tr></thead><tbody>{records.data.length ? records.data.map(row=><tr key={row.id} className="border-t"><td className="p-3 font-semibold">{row.caseNumber}</td><td className="p-3">{row.title}</td><td className="max-w-sm p-3">{row.details}</td><td className="p-3">{luponStatusLabel(row.status)}</td><td className="whitespace-nowrap p-3">{row.date || 'Not recorded'}</td><td className="p-3"><Link className="font-semibold text-[#3f6f3d] underline" href={`/lupon/cases/${row.caseId}${module === 'documents' ? '?section=documents' : module === 'arbitration' ? '?section=hearings' : ''}`}>Open case</Link></td></tr>) : <tr><td colSpan={6} className="p-10 text-center text-[#8a7f6a]">No matching records. Records appear only after an actual case action is saved.</td></tr>}</tbody></table></div>
        <footer className="flex items-center justify-between text-sm"><span>{records.total} records · Page {records.current_page} of {records.last_page}</span><div className="flex gap-3">{records.prev_page_url && <Link href={records.prev_page_url}>Previous</Link>}{records.next_page_url && <Link href={records.next_page_url}>Next</Link>}</div></footer>
    </div></LuponLayout>;
}
