import type { LuponEvidenceFile } from './types';
export function EvidenceInput({
    files,
    onChange,
    error,
}: {
    files: File[];
    onChange: (files: File[]) => void;
    error?: string;
}) {
    return (
        <div className="space-y-2">
            <label className="block text-sm font-semibold text-slate-800">
                Supporting evidence (optional)
                <input
                    type="file"
                    multiple
                    accept=".pdf,.jpg,.jpeg,.png,.webp,.docx"
                    className="mt-2 block w-full rounded-lg border border-line-soft bg-white p-2 text-xs"
                    onChange={(event) =>
                        onChange(Array.from(event.target.files || []))
                    }
                />
            </label>
            <p className="text-xs text-ink-muted">
                Up to 5 files, 5MB each. PDF, photos, or DOCX. Files are stored
                privately.
            </p>
            {files.length > 0 && (
                <ul className="space-y-1 text-xs text-ink-muted">
                    {files.map((file, index) => (
                        <li key={`${file.name}-${index}`}>
                            {file.name} · {(file.size / 1024).toFixed(0)} KB
                        </li>
                    ))}
                </ul>
            )}
            {error && (
                <p role="alert" className="text-xs text-red-700">
                    {error}
                </p>
            )}
        </div>
    );
}
export function EvidenceList({ files }: { files: LuponEvidenceFile[] }) {
    if (!files.length)
        return <p className="text-sm text-ink-muted">No evidence attached.</p>;
    return (
        <ul className="space-y-2">
            {files.map((file) => (
                <li
                    key={file.id}
                    className="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-line-soft bg-white p-3 text-sm"
                >
                    <a
                        href={file.url}
                        className="min-w-0 font-medium break-words text-emerald-800 underline"
                    >
                        {file.name}
                    </a>
                    <span className="text-xs text-ink-muted">
                        {(file.size / 1024).toFixed(0)} KB
                        {file.shared ? ' · Shared with parties' : ' · Private'}
                    </span>
                </li>
            ))}
        </ul>
    );
}
