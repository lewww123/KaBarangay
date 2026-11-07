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
        <div className="flex flex-wrap gap-2 border-b border-gray-200 pb-3">
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
                                ? 'bg-[#2a5279] text-white'
                                : 'bg-gray-100 text-gray-600 hover:bg-gray-200',
                        )}
                    >
                        <span>{tab.label}</span>
                        <span
                            className={cn(
                                'rounded-full px-1.5 py-0.5 text-xs',
                                active
                                    ? 'bg-white/20 text-white'
                                    : 'bg-white text-gray-500',
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
