import DocumentIssueControl from './DocumentIssueControl';
import { caseSaveOptions } from './CaseSaveFeedback';
import { useForm } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import DocxPreview from '@/components/DocxPreview';
import { Errors } from './WorkflowPanel';
import type {
    LuponDocument,
    LuponDocumentKind,
    LuponOfficials,
    LuponStatus,
} from './types';
const localTime = () =>
    new Date(Date.now() - new Date().getTimezoneOffset() * 60000)
        .toISOString()
        .slice(0, 19);
export default function DocumentPanel({
    caseId,
    status,
    paymentStatus,
    canVerifyPayments,
    actingCaptainId,
    documents,
    officials,
    hearings,
    hasSettlement,
    onSchedule,
}: {
    caseId: number;
    status: LuponStatus;
    paymentStatus: string;
    canVerifyPayments: boolean;
    actingCaptainId?: number | null;
    documents: LuponDocument[];
    officials: LuponOfficials;
    hearings: { id: number; hearingNumber: number; status: string }[];
    hasSettlement: boolean;
    onSchedule: () => void;
}) {
    const [preview, setPreview] = useState<LuponDocument | null>(null);
    const [previewReady, setPreviewReady] = useState(false);
    const [printing, setPrinting] = useState(false);
    const [printError, setPrintError] = useState<string | null>(null);
    const openPreview = (document: LuponDocument) => {
        setPreviewReady(false);
        setPrintError(null);
        setPreview(document);
    };
    const printPreview = async () => {
        if (!preview || !previewReady || printing) return;
        setPrinting(true);
        setPrintError(null);
        try {
            const response = await fetch(
                `/lupon/documents/${preview.id}/preview`,
                { credentials: 'same-origin' },
            );
            if (!response.ok || response.redirected)
                throw new Error(
                    'The prepared document is unavailable. Close and reopen the preview, then try again.',
                );
            const blob = await response.blob();
            const signature = new Uint8Array(
                await blob.slice(0, 2).arrayBuffer(),
            );
            if (signature[0] !== 0x50 || signature[1] !== 0x4b)
                throw new Error(
                    'The document could not be loaded for printing.',
                );
            const { printCertificateDocument } =
                await import('@/lib/certificateDocument');
            await printCertificateDocument(blob, preview.label);
        } catch (error) {
            setPrintError(
                error instanceof Error
                    ? error.message
                    : 'Unable to open the print view.',
            );
        } finally {
            setPrinting(false);
        }
    };
    const [serving, setServing] = useState<LuponDocument | null>(null);
    const generate = useForm({
        kind: (status === 'certified_to_file_action'
            ? 'cfa'
            : hasSettlement
              ? 'amicable_settlement'
              : 'summons') as LuponDocumentKind,
        hearing_id: '',
        acting_captain_id: actingCaptainId ? String(actingCaptainId) : '',
    });
    const fee = useForm({
        payment_status: 'paid',
        fee_amount: '0',
        or_number: '',
    });
    const service = useForm({
        served_at: localTime(),
        service_method: 'personal',
        service_note: '',
        receipt_confirmed: false,
    });
    const upcoming = hearings.filter((hearing) =>
        ['Scheduled', 'Rescheduled'].includes(hearing.status),
    );
    const openCase = [
        'filed',
        'mediation_active',
        'pangkat_formed',
        'conciliation_active',
    ].includes(status);
    const kinds: { value: LuponDocumentKind; label: string }[] = openCase
        ? [
              { value: 'notice_of_hearing', label: 'Notice of Hearing' },
              { value: 'summons', label: 'Summons' },
          ]
        : [];
    useEffect(() => {
        generate.setData(
            'kind',
            status === 'certified_to_file_action'
                ? 'cfa'
                : hasSettlement
                  ? 'amicable_settlement'
                  : 'summons',
        );
    }, [status, hasSettlement]);
    if (hasSettlement)
        kinds.push({
            value: 'amicable_settlement',
            label: 'Amicable Settlement',
        });
    if (status === 'certified_to_file_action')
        kinds.push({ value: 'cfa', label: 'Certificate to File Action' });
    return (
        <section className="space-y-4 rounded-xl border border-line-soft bg-surface p-4 sm:p-5">
            <div className="flex flex-wrap justify-between gap-2">
                <h2 className="font-bold text-ink-strong">
                    Mga dokumento at pag-abot
                </h2>
                <span className="text-xs font-semibold text-ink-muted">
                    Fee: {paymentStatus}
                </span>
            </div>
            <p className="text-xs text-ink-muted">
                Prepared files are unsigned copies. Review the details and
                required signatures before release; record actual notice service
                separately.
            </p>
            {!canVerifyPayments && paymentStatus === 'unpaid' && (
                <p className="rounded-lg border border-amber-200 bg-amber-50 p-3 text-xs font-semibold text-amber-800">
                    Only the Lupon Secretary can verify the filing fee or a
                    waiver. Ask the Secretary to record it before releasing a
                    notice.
                </p>
            )}
            {canVerifyPayments && paymentStatus === 'unpaid' && (
                <details
                    open
                    className="rounded-lg border border-amber-200 bg-amber-50 p-3"
                >
                    <summary className="cursor-pointer text-sm font-semibold">
                        1. Suriin ang filing fee / waiver
                    </summary>
                    <form
                        className="mt-3 grid gap-3 sm:grid-cols-2"
                        onSubmit={(event) => {
                            event.preventDefault();
                            fee.post(`/lupon/cases/${caseId}/verify-fee`, {
                                ...caseSaveOptions,
                            });
                        }}
                    >
                        <label className="text-xs font-semibold">
                            Payment
                            <select
                                value={fee.data.payment_status}
                                onChange={(event) =>
                                    fee.setData(
                                        'payment_status',
                                        event.target.value,
                                    )
                                }
                                className="mt-1 w-full rounded border bg-surface p-2 text-sm"
                            >
                                <option value="paid">Paid</option>
                                <option value="waived">Waived</option>
                            </select>
                        </label>
                        <label className="text-xs font-semibold">
                            Assessed fee (₱)
                            <input
                                type="number"
                                required
                                min="0"
                                step="0.01"
                                value={fee.data.fee_amount}
                                onChange={(event) =>
                                    fee.setData(
                                        'fee_amount',
                                        event.target.value,
                                    )
                                }
                                className="mt-1 w-full rounded border bg-surface p-2 text-sm"
                            />
                        </label>
                        <label className="text-xs font-semibold sm:col-span-2">
                            OR / waiver reference
                            <input
                                required
                                maxLength={100}
                                value={fee.data.or_number}
                                onChange={(event) =>
                                    fee.setData('or_number', event.target.value)
                                }
                                className="mt-1 w-full rounded border bg-surface p-2 text-sm"
                            />
                        </label>
                        <Errors errors={fee.errors} />
                        <button
                            disabled={fee.processing}
                            className="rounded-lg border bg-surface px-3 py-2 text-xs font-semibold"
                        >
                            Save verification
                        </button>
                    </form>
                </details>
            )}
            {kinds.length > 0 && (
                <h3 className="text-sm font-semibold text-ink-strong">
                    2. Ihanda ang kaukulang dokumento
                </h3>
            )}
            {kinds.length > 0 && (
                <form
                    className="space-y-3 rounded-lg bg-canvas p-3"
                    onSubmit={(event) => {
                        event.preventDefault();
                        generate.post(`/lupon/cases/${caseId}/documents`, {
                            ...caseSaveOptions,
                        });
                    }}
                >
                    <div className="grid gap-3 sm:grid-cols-2">
                        <label className="text-xs font-semibold">
                            Document
                            <select
                                value={generate.data.kind}
                                onChange={(event) =>
                                    generate.setData(
                                        'kind',
                                        event.target.value as LuponDocumentKind,
                                    )
                                }
                                className="mt-1 w-full rounded border bg-surface p-2 text-sm"
                            >
                                {kinds.map((kind) => (
                                    <option key={kind.value} value={kind.value}>
                                        {kind.label}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <label className="text-xs font-semibold">
                            Acting Punong Barangay (optional)
                            <select
                                value={generate.data.acting_captain_id}
                                onChange={(event) =>
                                    generate.setData(
                                        'acting_captain_id',
                                        event.target.value,
                                    )
                                }
                                className="mt-1 w-full rounded border bg-surface p-2 text-sm"
                            >
                                <option value="">
                                    {officials.default_punong_barangay?.name ||
                                        'Default Punong Barangay'}
                                </option>
                                {officials.active_kagawads.map((official) => (
                                    <option
                                        key={official.id}
                                        value={official.id}
                                    >
                                        {official.name}
                                    </option>
                                ))}
                            </select>
                        </label>
                    </div>
                    {['summons', 'notice_of_hearing'].includes(
                        generate.data.kind,
                    ) && (
                        <label className="block text-xs font-semibold">
                            Hearing
                            <select
                                value={generate.data.hearing_id}
                                onChange={(event) =>
                                    generate.setData(
                                        'hearing_id',
                                        event.target.value,
                                    )
                                }
                                className="mt-1 w-full rounded border bg-surface p-2 text-sm"
                            >
                                <option value="">Next scheduled hearing</option>
                                {upcoming.map((hearing) => (
                                    <option key={hearing.id} value={hearing.id}>
                                        Hearing #{hearing.hearingNumber}
                                    </option>
                                ))}
                            </select>
                        </label>
                    )}
                    {!upcoming.length &&
                        ['summons', 'notice_of_hearing'].includes(
                            generate.data.kind,
                        ) && (
                            <button
                                type="button"
                                onClick={onSchedule}
                                className="mr-3 mb-3 inline-block text-left text-xs font-semibold text-emerald-800 underline"
                            >
                                Schedule a hearing to complete pending notices
                            </button>
                        )}
                    <Errors errors={generate.errors} />
                    <button
                        disabled={
                            generate.processing ||
                            !kinds.some(
                                (kind) => kind.value === generate.data.kind,
                            )
                        }
                        className="rounded-lg bg-brand px-4 py-2 text-xs font-semibold text-white disabled:opacity-50"
                    >
                        {generate.processing
                            ? 'Preparing…'
                            : 'Prepare document'}
                    </button>
                </form>
            )}
            {[...new Set(documents.map((document) => document.hearingId))].map(
                (hearingId) => (
                    <section key={hearingId ?? 'case'} className="space-y-2">
                        <h3 className="text-sm font-semibold text-ink">
                            {hearingId === null
                                ? 'Case-level documents'
                                : hearings.find(
                                        (hearing) => hearing.id === hearingId,
                                    )
                                  ? `Hearing #${hearings.find((hearing) => hearing.id === hearingId)!.hearingNumber}`
                                  : `Linked hearing ID ${hearingId} (details not supplied)`}
                        </h3>
                        <ul className="space-y-2">
                            {documents
                                .filter(
                                    (document) =>
                                        document.hearingId === hearingId,
                                )
                                .map((document) => (
                                    <li
                                        key={document.id}
                                        className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-line-soft p-3"
                                    >
                                        <div>
                                            <p className="text-sm font-semibold">
                                                {document.label}
                                            </p>
                                            <p className="text-xs text-ink-muted">
                                                {document.status === 'ready'
                                                    ? 'Prepared file · review/signatures still required'
                                                    : document.status ===
                                                        'pending'
                                                      ? 'Preparation pending'
                                                      : 'Superseded file'}
                                                {document.generatedAt &&
                                                    ` · Prepared ${new Date(document.generatedAt).toLocaleString('en-PH')}`}
                                                {document.servedAt
                                                    ? ` · Service recorded ${new Date(document.servedAt).toLocaleString('en-PH')}`
                                                    : [
                                                            'summons',
                                                            'notice_of_hearing',
                                                        ].includes(
                                                            document.kind,
                                                        )
                                                      ? ' · No service record supplied'
                                                      : ''}
                                            </p>
                                        </div>
                                        {document.status === 'ready' && !document.issuedAt && <DocumentIssueControl documentId={document.id} />}
                                        {document.issuedAt && <p className="text-xs text-ink-muted">Issued: {new Date(document.issuedAt).toLocaleString('en-PH')}</p>}
                                        {document.status === 'ready' && (
                                            <div className="flex flex-wrap gap-2 text-xs font-semibold">
                                                <button
                                                    type="button"
                                                    onClick={() =>
                                                        openPreview(document)
                                                    }
                                                    className="rounded border px-2 py-1.5"
                                                >
                                                    Preview
                                                </button>
                                                {document.url && (
                                                    <a
                                                        href={document.url}
                                                        className="rounded border px-2 py-1.5 text-emerald-800"
                                                    >
                                                        Download DOCX
                                                    </a>
                                                )}
                                                {[
                                                    'summons',
                                                    'notice_of_hearing',
                                                ].includes(document.kind) &&
                                                    !document.servedAt && document.issuedAt && (
                                                        <button
                                                            type="button"
                                                            disabled={
                                                                paymentStatus ===
                                                                'unpaid'
                                                            }
                                                            onClick={() => {
                                                                service.reset();
                                                                service.setData(
                                                                    'served_at',
                                                                    localTime(),
                                                                );
                                                                setServing(
                                                                    document,
                                                                );
                                                            }}
                                                            className="rounded border px-2 py-1.5 disabled:opacity-40"
                                                        >
                                                            Record service
                                                        </button>
                                                    )}
                                            </div>
                                        )}
                                    </li>
                                ))}
                        </ul>
                    </section>
                ),
            )}
            {!documents.length && (
                <p className="text-xs text-ink-muted">
                    No documents have been prepared yet.
                </p>
            )}
            {preview && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-3">
                    <div
                        role="dialog"
                        aria-modal="true"
                        aria-label={preview.label}
                        className="flex max-h-[92vh] w-full max-w-5xl flex-col rounded-xl bg-surface shadow-xl"
                    >
                        <div className="flex flex-wrap items-center justify-between gap-3 border-b p-4">
                            <h3 className="font-bold">{preview.label}</h3>
                            <button
                                type="button"
                                disabled={!previewReady || printing}
                                onClick={printPreview}
                                className="rounded-lg bg-brand px-3 py-2 text-sm font-semibold text-white disabled:opacity-40"
                            >
                                {printing
                                    ? 'Opening print view…'
                                    : 'Print document'}
                            </button>
                            <button
                                type="button"
                                onClick={() => setPreview(null)}
                                disabled={printing}
                                className="rounded border px-3 py-1 text-sm"
                            >
                                Close
                            </button>
                        </div>
                        {printError && (
                            <p
                                role="alert"
                                className="m-3 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800"
                            >
                                {printError}
                            </p>
                        )}
                        <div className="overflow-auto bg-secondary p-3">
                            <DocxPreview
                                url={`/lupon/documents/${preview.id}/preview`}
                                onReady={setPreviewReady}
                            />
                        </div>
                    </div>
                </div>
            )}
            {serving && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-3">
                    <form
                        role="dialog"
                        aria-modal="true"
                        aria-label="Record notice service"
                        className="max-h-[92vh] w-full max-w-lg space-y-4 overflow-auto rounded-xl bg-surface p-5"
                        onSubmit={(event) => {
                            event.preventDefault();
                            service.transform((data) => ({
                                ...data,
                                served_at: new Date(
                                    data.served_at,
                                ).toISOString(),
                            }));
                            service.post(
                                `/lupon/documents/${serving.id}/serve`,
                                {
                                    ...caseSaveOptions,
                                    onSuccess: () => setServing(null),
                                },
                            );
                        }}
                    >
                        <h3 className="font-bold">
                            Record service — {serving.label}
                        </h3>
                        <label className="block text-xs font-semibold">
                            Actual receipt date/time
                            <input
                                required
                                type="datetime-local"
                                step="1"
                                value={service.data.served_at}
                                onChange={(event) =>
                                    service.setData(
                                        'served_at',
                                        event.target.value,
                                    )
                                }
                                className="mt-1 w-full rounded border p-2 text-sm"
                            />
                        </label>
                        <label className="block text-xs font-semibold">
                            Method
                            <select
                                value={service.data.service_method}
                                onChange={(event) =>
                                    service.setData(
                                        'service_method',
                                        event.target.value,
                                    )
                                }
                                className="mt-1 w-full rounded border p-2 text-sm"
                            >
                                <option value="personal">
                                    Personal service
                                </option>
                                <option value="other">
                                    Other documented lawful service
                                </option>
                            </select>
                        </label>
                        <label className="block text-xs font-semibold">
                            Recipient / serving officer / receipt details
                            <textarea
                                required
                                rows={3}
                                maxLength={2000}
                                value={service.data.service_note}
                                onChange={(event) =>
                                    service.setData(
                                        'service_note',
                                        event.target.value,
                                    )
                                }
                                className="mt-1 w-full rounded border p-2 text-sm"
                            />
                        </label>
                        <label className="flex items-start gap-2 text-xs">
                            <input
                                required
                                type="checkbox"
                                checked={service.data.receipt_confirmed}
                                onChange={(event) =>
                                    service.setData(
                                        'receipt_confirmed',
                                        event.target.checked,
                                    )
                                }
                            />
                            I confirm that the required service and receipt are
                            supported by the case record.
                        </label>
                        <Errors errors={service.errors} />
                        <div className="flex justify-end gap-2">
                            <button
                                type="button"
                                onClick={() => setServing(null)}
                                className="rounded border px-3 py-2 text-xs"
                            >
                                Cancel
                            </button>
                            <button
                                disabled={service.processing}
                                className="rounded bg-brand px-3 py-2 text-xs font-semibold text-white"
                            >
                                Save service record
                            </button>
                        </div>
                    </form>
                </div>
            )}
        </section>
    );
}
