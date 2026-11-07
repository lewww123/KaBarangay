import { Link } from '@inertiajs/react';
import type { PaginatedData } from '@/types';

type Props = {
    paginator: PaginatedData<unknown>;
    label: string;
};

function linkLabel(label: string): string {
    return label
        .replace(/<[^>]*>/g, '')
        .replace(/&laquo;/g, '«')
        .replace(/&raquo;/g, '»');
}

export default function Pagination({ paginator, label }: Props) {
    if (paginator.last_page <= 1) {
        return null;
    }

    return (
        <nav
            aria-label={`${label} pages`}
            className="flex flex-wrap justify-center gap-1.5 pt-5"
        >
            {paginator.links.map((link, index) =>
                link.url === null ? (
                    <span
                        key={`${link.label}-${index}`}
                        className="inline-flex h-10 min-w-10 items-center justify-center rounded-lg border border-gray-200 px-3 text-xs font-semibold text-gray-400 sm:text-sm"
                    >
                        {linkLabel(link.label)}
                    </span>
                ) : (
                    <Link
                        key={`${link.label}-${index}`}
                        href={link.url}
                        preserveScroll
                        preserveState
                        aria-current={link.active ? 'page' : undefined}
                        className={`inline-flex h-10 min-w-10 items-center justify-center rounded-lg border px-3 text-xs font-semibold transition sm:text-sm ${
                            link.active
                                ? 'border-[#3b6fa8] bg-[#3b6fa8] text-white'
                                : 'border-gray-300 bg-white text-gray-700 hover:bg-gray-50'
                        }`}
                    >
                        {linkLabel(link.label)}
                    </Link>
                ),
            )}
        </nav>
    );
}
