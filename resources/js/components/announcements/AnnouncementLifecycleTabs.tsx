import {
    ANNOUNCEMENT_TABS,
    type AnnouncementTab,
    type AnnouncementTabCounts,
} from '@/components/announcements/announcement';
import { cn } from '@/lib/utils';

interface AnnouncementLifecycleTabsProps {
    activeTab: AnnouncementTab;
    counts: AnnouncementTabCounts;
    onChange: (tab: AnnouncementTab) => void;
}

export default function AnnouncementLifecycleTabs({
    activeTab,
    counts,
    onChange,
}: AnnouncementLifecycleTabsProps) {
    return (
        <div className="flex flex-wrap gap-2 border-b border-line-soft pb-3">
            {ANNOUNCEMENT_TABS.map((tab) => {
                const active = activeTab === tab.value;

                return (
                    <button
                        key={tab.value}
                        type="button"
                        onClick={() => onChange(tab.value)}
                        className={cn(
                            'inline-flex items-center gap-2 rounded-full px-4 py-2 text-sm font-medium transition-colors',
                            active
                                ? 'bg-brand-deep text-white'
                                : 'bg-secondary text-ink-muted hover:bg-line-soft',
                        )}
                    >
                        <span>{tab.label}</span>
                        <span
                            className={cn(
                                'rounded-full px-1.5 py-0.5 text-xs',
                                active
                                    ? 'bg-white/20 text-white'
                                    : 'bg-white text-ink-muted',
                            )}
                        >
                            {counts[tab.value] ?? 0}
                        </span>
                    </button>
                );
            })}
        </div>
    );
}
