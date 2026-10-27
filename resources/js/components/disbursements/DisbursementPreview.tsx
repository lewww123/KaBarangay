import {
    disbursementFileType,
    formatFileSize,
    type CsvPreview,
    type DisbursementDocumentData,
} from './disbursement';

type Props = {
    document: DisbursementDocumentData;
    csvPreview: CsvPreview | null;
};

export default function DisbursementPreview({
    document,
    csvPreview,
}: Props) {
    const previewLimited =
        csvPreview !== null &&
        (csvPreview.rows_truncated ||
            csvPreview.columns_truncated ||
            csvPreview.cells_truncated);

    return (
        <div className="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm">
            <div className="flex flex-col gap-3 border-b border-gray-100 px-4 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-5">
                <div className="min-w-0">
                    <div className="flex flex-wrap items-center gap-2">
                        <span className="rounded-md border border-gray-200 bg-gray-50 px-2 py-1 text-[11px] font-semibold text-gray-600">
                            {disbursementFileType(document)}
                        </span>
                        <span className="text-xs text-gray-500">
                            {formatFileSize(document.file_size)}
                        </span>
                    </div>

                    <p className="mt-2 truncate text-sm font-medium text-gray-800">
                        {document.original_name}
                    </p>
                </div>

                <div className="flex shrink-0 flex-wrap gap-2">
                    <a
                        href={document.file_url}
                        target="_blank"
                        rel="noreferrer"
                        className="inline-flex min-h-9 items-center justify-center rounded-lg border border-gray-200 bg-white px-3 text-xs font-semibold text-gray-700 transition hover:bg-gray-50"
                    >
                        Open file
                    </a>

                    <a
                        href={document.download_url}
                        className="inline-flex min-h-9 items-center justify-center rounded-lg bg-[#3f6f3d] px-3 text-xs font-semibold text-white transition hover:bg-[#315b30]"
                    >
                        Download original
                    </a>
                </div>
            </div>

            {document.is_pdf && (
                <div>
                    <iframe
                        src={document.file_url}
                        title={`Preview of ${document.title}`}
                        className="h-[70vh] min-h-[420px] w-full bg-gray-100"
                    />

                    <p className="border-t border-gray-100 px-4 py-3 text-xs text-gray-500">
                        If the PDF preview does not load, use Open file or
                        download the original.
                    </p>
                </div>
            )}

            {document.is_csv && csvPreview && (
                <CsvPreviewTable preview={csvPreview} />
            )}

            {previewLimited && (
                <p className="border-t border-amber-100 bg-amber-50 px-4 py-3 text-xs text-amber-800">
                    Preview limited to {csvPreview?.row_limit} rows,{' '}
                    {csvPreview?.column_limit} columns, and 500 characters per
                    cell. Download the original for the complete file.
                </p>
            )}

            {!document.is_pdf && !document.is_csv && (
                <div className="px-4 py-12 text-center text-sm text-gray-500">
                    Preview is not available for this file type.
                </div>
            )}
        </div>
    );
}

function CsvPreviewTable({ preview }: { preview: CsvPreview }) {
    if (preview.error) {
        return (
            <div className="px-4 py-10 text-center text-sm text-gray-500">
                {preview.error}
            </div>
        );
    }

    return (
        <div className="max-h-[70vh] overflow-auto">
            <table className="min-w-full border-collapse text-left text-xs">
                <thead className="sticky top-0 z-10 bg-gray-50">
                    <tr>
                        {preview.columns.map((column, index) => (
                            <th
                                key={`${index}-${column}`}
                                scope="col"
                                className="max-w-64 border-b border-gray-200 px-3 py-2 font-semibold whitespace-nowrap text-gray-700"
                            >
                                {column}
                            </th>
                        ))}
                    </tr>
                </thead>

                <tbody>
                    {preview.rows.map((row, rowIndex) => (
                        <tr
                            key={rowIndex}
                            className="odd:bg-white even:bg-gray-50/60"
                        >
                            {preview.columns.map((_, columnIndex) => (
                                <td
                                    key={columnIndex}
                                    className="max-w-64 border-b border-gray-100 px-3 py-2 align-top whitespace-pre-wrap break-words text-gray-600"
                                >
                                    {row[columnIndex] || '\u00a0'}
                                </td>
                            ))}
                        </tr>
                    ))}
                </tbody>
            </table>

            {preview.rows.length === 0 && (
                <p className="px-4 py-8 text-center text-sm text-gray-500">
                    This CSV file has no data rows.
                </p>
            )}
        </div>
    );
}
