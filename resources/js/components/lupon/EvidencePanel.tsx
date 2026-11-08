import { caseSaveOptions } from './CaseSaveFeedback';
import { router, useForm } from '@inertiajs/react';
import { EvidenceInput, EvidenceList } from './Evidence';
import { Errors } from './WorkflowPanel';
import type { LuponEvidenceFile } from './types';
export default function EvidencePanel({
    caseId,
    files,
}: {
    caseId: number;
    files: LuponEvidenceFile[];
}) {
    const form = useForm({ evidence: [] as File[] });
    return (
        <section className="space-y-4 rounded-xl border border-line-soft bg-white p-4 sm:p-5">
            <h2 className="font-semibold text-ink-strong">Evidence</h2>
            <EvidenceList files={files} />
            {files.length > 0 && (
                <div className="space-y-2 border-t pt-3">
                    {files.map((file) => (
                        <label
                            key={file.id}
                            className="flex items-start gap-2 text-xs text-ink-muted"
                        >
                            <input
                                type="checkbox"
                                checked={file.shared}
                                onChange={(event) =>
                                    router.patch(
                                        `/lupon/evidence/${file.id}/visibility`,
                                        {
                                            visible_to_parties:
                                                event.target.checked,
                                        },
                                        { ...caseSaveOptions },
                                    )
                                }
                            />
                            Share {file.name} with the authorized parties
                        </label>
                    ))}
                </div>
            )}
            <form
                className="space-y-3 border-t pt-3"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.post(`/lupon/cases/${caseId}/evidence`, {
                        forceFormData: true,
                        ...caseSaveOptions,
                        onSuccess: () => form.reset(),
                    });
                }}
            >
                <EvidenceInput
                    files={form.data.evidence}
                    onChange={(files) => form.setData('evidence', files)}
                />
                <Errors errors={form.errors} />
                <button
                    disabled={form.processing || !form.data.evidence.length}
                    className="rounded-lg border px-3 py-2 text-xs font-semibold disabled:opacity-50"
                >
                    {form.processing
                        ? 'Uploading evidence…'
                        : 'Upload evidence'}
                </button>
            </form>
        </section>
    );
}
