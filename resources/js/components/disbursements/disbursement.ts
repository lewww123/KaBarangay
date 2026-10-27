export type DisbursementDocumentStatus =
    | 'draft'
    | 'published'
    | 'archived';

export type DisbursementDocumentData = {
    id: number;
    title: string;
    description: string | null;
    original_name: string;
    mime_type: string;
    file_size: number;
    is_pdf: boolean;
    is_csv: boolean;
    status: DisbursementDocumentStatus;
    is_published: boolean;
    published_at: string | null;
    archived_at: string | null;
    created_at: string | null;
    preview_url: string;
    file_url: string;
    download_url: string;
    uploader_name?: string | null;
    publish_url?: string;
    archive_url?: string;
};

export type CsvPreview = {
    columns: string[];
    rows: string[][];
    row_limit: number;
    column_limit: number;
    rows_truncated: boolean;
    columns_truncated: boolean;
    cells_truncated: boolean;
    error: string | null;
};

export type PaginatorLink = {
    url: string | null;
    label: string;
    active: boolean;
};

export type Paginator<T> = {
    data: T[];
    links: PaginatorLink[];
    current_page: number;
    last_page: number;
    total: number;
    from: number | null;
    to: number | null;
};

export function formatFileSize(bytes: number): string {
    if (!Number.isFinite(bytes) || bytes <= 0) {
        return '0 KB';
    }

    if (bytes < 1024 * 1024) {
        return `${Math.max(1, Math.round(bytes / 1024))} KB`;
    }

    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

export function formatDisbursementDate(
    value: string | null,
): string {
    if (!value) {
        return 'Not available';
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return 'Not available';
    }

    return new Intl.DateTimeFormat('en-PH', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
    }).format(date);
}

export function formatDisbursementDateTime(
    value: string | null,
): string {
    if (!value) {
        return 'Not available';
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return 'Not available';
    }

    return new Intl.DateTimeFormat('en-PH', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
    }).format(date);
}

export function disbursementStatusLabel(
    status: DisbursementDocumentStatus,
): string {
    if (status === 'published') {
        return 'Published';
    }

    if (status === 'archived') {
        return 'Archived';
    }

    return 'Draft';
}

export function disbursementStatusClasses(
    status: DisbursementDocumentStatus,
): string {
    if (status === 'published') {
        return 'border-emerald-200 bg-emerald-50 text-emerald-700';
    }

    if (status === 'archived') {
        return 'border-gray-200 bg-gray-100 text-gray-600';
    }

    return 'border-amber-200 bg-amber-50 text-amber-700';
}

export function disbursementFileType(
    document: DisbursementDocumentData,
): string {
    if (document.is_pdf) {
        return 'PDF';
    }

    if (document.is_csv) {
        return 'CSV';
    }

    return 'File';
}
