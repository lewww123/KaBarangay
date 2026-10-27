import {
    formatImportanceLabel,
    importanceBadgeClass,
} from '@/components/announcements/announcement';
import { cn } from '@/lib/utils';

interface AnnouncementImportanceBadgeProps {
    importance?: string | null;
    className?: string;
}

export default function AnnouncementImportanceBadge({
    importance,
    className,
}: AnnouncementImportanceBadgeProps) {
    return (
        <span
            className={cn(
                'inline-flex items-center rounded-full border px-2 py-0.5 text-xs font-medium',
                importanceBadgeClass(importance),
                className,
            )}
        >
            {formatImportanceLabel(importance)}
        </span>
    );
}
