export type CitizenCharterVersionData = {
    id: number;
    version_number: number;
    original_name: string;
    mime_type: string;
    file_size: number;
    status: 'draft' | 'published' | 'superseded' | 'archived';
    is_current: boolean;
    published_at: string | null;
    created_at: string | null;
    uploader_name?: string | null;
    file_url?: string;
    download_url?: string;
};

export type CitizenCharterServiceData = {
    id: number;
    title: string;
    category: string | null;
    description: string | null;
    display_order: number;
    status: 'draft' | 'published' | 'archived';
    is_published: boolean;
    published_at: string | null;
    archived_at: string | null;
    created_at: string | null;
    creator_name?: string | null;
    current_version: CitizenCharterVersionData | null;
    versions?: CitizenCharterVersionData[];
    show_url?: string;
    update_url?: string;
    publish_url?: string;
    archive_url?: string;
    upload_version_url?: string;
    detail_url?: string;
    file_url?: string;
    download_url?: string;
};

export type PaginatorLink = {
    url: string | null;
    label: string;
    active: boolean;
};

export type Paginator<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
    prev_page_url: string | null;
    next_page_url: string | null;
    links: PaginatorLink[];
};

export function formatFileSize(bytes: number): string {
    if (bytes <= 0) {
        return '0 B';
    }
    const units = ['B', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(1024));
    const val = bytes / Math.pow(1024, i);
    return `${val.toFixed(i === 0 ? 0 : 1)} ${units[i]}`;
}

export function formatCharterDate(iso: string | null | undefined): string {
    if (!iso) {
        return '—';
    }
    const date = new Date(iso);
    if (isNaN(date.getTime())) {
        return '—';
    }
    return date.toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
    });
}

export function charterStatusBadge(status: string): {
    label: string;
    classes: string;
} {
    switch (status) {
        case 'published':
            return {
                label: 'Published',
                classes: 'bg-brand-soft text-brand-deep border-brand-muted',
            };
        case 'archived':
            return {
                label: 'Archived',
                classes: 'bg-gray-100 text-gray-700 border-gray-300',
            };
        case 'superseded':
            return {
                label: 'Superseded',
                classes: 'bg-amber-100 text-amber-800 border-amber-200',
            };
        case 'draft':
        default:
            return {
                label: 'Draft',
                classes: 'bg-blue-100 text-blue-800 border-blue-200',
            };
    }
}
