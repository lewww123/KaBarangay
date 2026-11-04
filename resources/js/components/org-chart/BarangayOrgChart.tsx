import type { ReactNode } from 'react';
import {
    groupOrgChart,
    orgChartStatusBadge,
    type OrgChartNode,
    type OrgChartPosition,
} from './orgChart';

type Props = {
    tree: OrgChartNode[];
    actingCaptain?: OrgChartPosition | null;
    selectedId?: number | null;
    onSelect?: (position: OrgChartPosition) => void;
    showStatus?: boolean;
};

type Tone = 'leadership' | 'legislative' | 'support' | 'other';
const accents: Record<Tone, string> = {
    leadership: 'border-t-emerald-700 bg-emerald-50/40',
    legislative: 'border-t-sky-600 bg-sky-50/40',
    support: 'border-t-amber-500 bg-amber-50/40',
    other: 'border-t-slate-400 bg-slate-50/40',
};
const labels: Record<Tone, string> = {
    leadership: 'text-emerald-800',
    legislative: 'text-sky-800',
    support: 'text-amber-800',
    other: 'text-slate-600',
};

/** Shared read-only chart; selection opens the caller's existing detail/edit view. */
export default function BarangayOrgChart({
    tree,
    actingCaptain,
    selectedId,
    onSelect,
    showStatus = false,
}: Props) {
    const chart = groupOrgChart(tree, actingCaptain);
    // Keep the legislative row together; tablets can pan the contained canvas.
    const minimumWidth = Math.max(
        600,
        Math.max(chart.kagawads.length, chart.appointed.length) * 100,
    );
    const card = (position: OrgChartPosition, tone: Tone, acting = false) => (
        <OfficialCard
            key={position.id}
            position={position}
            tone={tone}
            acting={acting}
            selected={selectedId === position.id}
            onSelect={onSelect}
            showStatus={showStatus}
        />
    );

    return (
        <section
            aria-label="Barangay organizational hierarchy"
            className="min-w-0 rounded-2xl border border-slate-200 bg-white shadow-sm"
        >
            <div className="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-5 py-4">
                <h2 className="text-sm font-bold text-slate-900">
                    Barangay Leadership &amp; Officials
                </h2>
                <div
                    className="flex flex-wrap gap-3 text-[10px] font-semibold tracking-wide text-slate-500 uppercase"
                    aria-label="Role colors"
                >
                    <Legend color="bg-emerald-700">Leadership</Legend>
                    <Legend color="bg-sky-600">Legislative</Legend>
                    <Legend color="bg-amber-500">Support</Legend>
                </div>
            </div>
            <div
                role="region"
                aria-label="Scrollable organizational chart"
                tabIndex={0}
                className="overflow-x-auto overscroll-x-contain rounded-b-2xl focus-visible:outline-2 focus-visible:outline-emerald-600"
            >
                <div
                    className="px-5 py-7 sm:px-7 sm:py-9"
                    style={{ minWidth: minimumWidth }}
                >
                    <div className="mx-auto w-64">
                        {chart.captain ? (
                            card(chart.captain, 'leadership', chart.isActing)
                        ) : (
                            <div className="rounded-xl border border-dashed border-emerald-300 bg-emerald-50 p-6 text-center">
                                <h3 className="text-xs font-bold tracking-wide text-emerald-800 uppercase">
                                    Punong Barangay
                                </h3>
                                <p className="mt-2 text-sm text-slate-500">
                                    Not assigned
                                </p>
                            </div>
                        )}
                    </div>
                    <div className="relative">
                        {/* Continue the captain's branch around the legislative row to support staff. */}
                        <span
                            aria-hidden="true"
                            className="absolute top-3 right-1/2 left-0 h-px bg-slate-300"
                        />
                        <span
                            aria-hidden="true"
                            className="absolute top-3 bottom-0 left-0 w-px bg-slate-300"
                        />
                        <Stem />
                        <Branch
                            title="Sangguniang Barangay"
                            subtitle="Barangay Kagawads"
                            positions={chart.kagawads}
                            tone="legislative"
                            renderCard={card}
                            empty="No Kagawad positions to display."
                        />
                    </div>
                    <div aria-hidden="true" className="relative h-6 sm:h-7">
                        <span className="absolute top-0 left-0 h-1/2 w-1/2 border-b border-l border-slate-300" />
                        <span className="absolute top-1/2 left-1/2 h-1/2 w-px bg-slate-300" />
                    </div>
                    <Branch
                        title="Appointed Officials"
                        subtitle="Barangay Secretary & Treasurer"
                        positions={chart.appointed}
                        tone="support"
                        renderCard={card}
                        empty="No appointed positions to display."
                    />
                    {chart.others.length > 0 && (
                        <div className="mt-8 border-t border-slate-100 pt-6">
                            <h3 className="mb-4 text-center text-xs font-bold tracking-widest text-slate-500 uppercase">
                                Other Barangay Positions
                            </h3>
                            <ul className="grid grid-cols-3 gap-3 lg:grid-cols-4">
                                {chart.others.map((position) => (
                                    <li key={position.id}>
                                        {card(position, 'other')}
                                    </li>
                                ))}
                            </ul>
                        </div>
                    )}
                </div>
            </div>
            <p className="border-t border-slate-100 px-5 py-2 text-center text-xs text-slate-500 md:hidden">
                Scroll sideways to view the full chart.
            </p>
        </section>
    );
}

function Branch({
    title,
    subtitle,
    positions,
    tone,
    renderCard,
    empty,
}: {
    title: string;
    subtitle: string;
    positions: OrgChartNode[];
    tone: Tone;
    renderCard: (position: OrgChartPosition, tone: Tone) => ReactNode;
    empty: string;
}) {
    return (
        <section aria-label={title}>
            <div className="text-center">
                <h3
                    className={`text-xs font-extrabold tracking-[0.18em] uppercase ${labels[tone]}`}
                >
                    {title}
                </h3>
                <p className="mt-1 text-[11px] text-slate-500">{subtitle}</p>
            </div>
            {positions.length ? (
                <>
                    <Stem />
                    <ul
                        className={`mx-auto grid ${positions.length <= 2 ? 'max-w-xl' : ''}`}
                        style={{
                            gridTemplateColumns: `repeat(${positions.length}, minmax(0, 1fr))`,
                        }}
                    >
                        {positions.map((position, index) => (
                            <li
                                key={position.id}
                                className="relative flex px-1.5 pt-6"
                            >
                                <span
                                    aria-hidden="true"
                                    className="absolute top-0 h-px bg-slate-300"
                                    style={{
                                        left: index === 0 ? '50%' : 0,
                                        right:
                                            index === positions.length - 1
                                                ? '50%'
                                                : 0,
                                    }}
                                />
                                <span
                                    aria-hidden="true"
                                    className="absolute top-0 left-1/2 h-6 w-px bg-slate-300"
                                />
                                {renderCard(position, tone)}
                            </li>
                        ))}
                    </ul>
                </>
            ) : (
                <p className="mt-4 text-center text-xs text-slate-400 italic">
                    {empty}
                </p>
            )}
        </section>
    );
}

function OfficialCard({
    position,
    tone,
    acting,
    selected,
    onSelect,
    showStatus,
}: {
    position: OrgChartPosition;
    tone: Tone;
    acting?: boolean;
    selected: boolean;
    onSelect?: (position: OrgChartPosition) => void;
    showStatus: boolean;
}) {
    const name = position.assigned_person?.trim();
    const initials = name
        ? name
              .split(/\s+/)
              .filter(Boolean)
              .map((part) => Array.from(part)[0])
              .slice(0, 2)
              .join('')
              .toUpperCase()
        : '—';
    const badge = orgChartStatusBadge(position.status);
    const title = tone === 'leadership' ? 'Punong Barangay' : position.title;
    const className = `flex h-full w-full min-w-0 flex-col items-center rounded-xl border border-slate-200 border-t-4 px-2 py-4 lg:px-3 text-center shadow-sm transition ${accents[tone]} ${onSelect ? 'cursor-pointer hover:-translate-y-0.5 hover:shadow-md motion-reduce:transform-none focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-600' : ''} ${selected ? 'ring-2 ring-emerald-600 ring-offset-2' : ''}`;
    const content = (
        <>
            {position.photo_url ? (
                <img
                    src={position.photo_url}
                    alt={`Portrait of ${name || position.title}`}
                    loading="lazy"
                    decoding="async"
                    className={`${tone === 'leadership' ? 'h-16 w-16' : 'h-12 w-12'} rounded-full border-2 border-white object-cover shadow-sm`}
                />
            ) : (
                <span
                    aria-hidden="true"
                    className={`${tone === 'leadership' ? 'h-16 w-16 text-lg' : 'h-12 w-12 text-sm'} flex shrink-0 items-center justify-center rounded-full bg-white font-bold shadow-sm ${labels[tone]}`}
                >
                    {initials}
                </span>
            )}
            <h4 className="mt-3 w-full text-xs leading-snug font-bold break-words text-slate-900 lg:text-sm">
                {name || (
                    <span className="font-normal text-slate-400 italic">
                        Vacant
                    </span>
                )}
            </h4>
            <p
                className={`mt-1.5 w-full text-[10px] leading-relaxed font-bold tracking-wide break-words uppercase ${labels[tone]}`}
            >
                {title}
            </p>
            {acting && (
                <span className="mt-2 rounded-full border border-amber-200 bg-amber-100 px-2.5 py-0.5 text-[10px] font-bold text-amber-900">
                    (Acting)
                </span>
            )}
            {showStatus && (
                <span
                    className={`mt-2 rounded-full border px-2 py-0.5 text-[9px] font-bold ${badge.classes}`}
                >
                    {badge.label}
                </span>
            )}
        </>
    );
    return onSelect ? (
        <button
            type="button"
            className={className}
            aria-pressed={selected}
            onClick={() => onSelect(position)}
        >
            {content}
        </button>
    ) : (
        <article className={className}>{content}</article>
    );
}

function Stem() {
    return (
        <div
            aria-hidden="true"
            className="mx-auto h-6 w-px bg-slate-300 sm:h-7"
        />
    );
}

function Legend({ color, children }: { color: string; children: ReactNode }) {
    return (
        <span className="inline-flex items-center gap-1.5">
            <span
                aria-hidden="true"
                className={`h-2 w-2 rounded-full ${color}`}
            />
            {children}
        </span>
    );
}
