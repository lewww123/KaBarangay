import BarangayOrgChart from '@/components/org-chart/BarangayOrgChart';
import { Deferred, Head } from '@inertiajs/react';
import { useState } from 'react';
import {
    flattenOrgChart,
    type OrgChartNode,
} from '@/components/org-chart/orgChart';
import ResidentLayout from '@/layouts/ResidentLayout';
import PortalPageSkeleton from '@/components/PortalPageSkeleton';

type Props = {
    tree: OrgChartNode[];
    resident: {
        full_name: string;
    };
};

export default function ResidentOrgChart(props: Props) {
    return (
        <Deferred
            data="tree"
            fallback={
                <ResidentLayout active="org-chart">
                    <PortalPageSkeleton list />
                </ResidentLayout>
            }
        >
            <OrgChartContent {...props} />
        </Deferred>
    );
}

function OrgChartContent({ tree, resident }: Props) {
    const positions = flattenOrgChart(tree);
    const [selectedId, setSelectedId] = useState<number | null>(null);

    const selected =
        positions.find((position) => position.id === selectedId) ?? null;

    return (
        <ResidentLayout active="org-chart">
            <Head title="Organizational Chart | Barangay San Jose" />

            <div className="mx-auto w-full max-w-6xl space-y-6">
                <div>
                    <h1 className="text-xl font-bold text-gray-900 sm:text-2xl">
                        Barangay Organizational Chart
                    </h1>
                    <p className="mt-1 max-w-2xl text-sm leading-relaxed text-gray-500">
                        Official structure of barangay positions and offices.
                        Select a position to view its details and official
                        photo.
                    </p>
                </div>

                {positions.length === 0 ? (
                    <div className="rounded-2xl border border-gray-200 bg-white p-10 text-center shadow-sm">
                        <div className="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-[#e8f1e4] text-[#3f6f3d]">
                            <BuildingIcon className="h-8 w-8" />
                        </div>
                        <h2 className="mt-4 text-sm font-bold text-gray-800">
                            Organizational chart not yet published
                        </h2>
                        <p className="mx-auto mt-1 max-w-md text-xs leading-relaxed text-gray-500">
                            The barangay has not published its organizational
                            chart yet. Please check back later.
                        </p>
                    </div>
                ) : (
                    <div className="grid gap-5">
                        <div className="min-w-0">
                            <div className="mb-4 flex items-center justify-between border-b border-gray-100 pb-3">
                                <h2 className="text-sm font-bold text-gray-900">
                                    Published Positions
                                </h2>
                                <span className="text-xs font-medium text-gray-400">
                                    {positions.length} position
                                    {positions.length === 1 ? '' : 's'}
                                </span>
                            </div>

                            <BarangayOrgChart
                                tree={tree}
                                selectedId={selectedId}
                                onSelect={(position) =>
                                    setSelectedId(position.id)
                                }
                            />
                        </div>

                        <aside className="h-fit rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
                            {selected ? (
                                <div>
                                    <div className="flex justify-center">
                                        <ResidentPhoto
                                            node={selected}
                                            size="lg"
                                        />
                                    </div>

                                    <div className="mt-4 text-center">
                                        <p className="text-[10px] font-bold tracking-wider text-[#3f6f3d] uppercase">
                                            Published Position
                                        </p>
                                        <h2 className="mt-1 text-base font-bold text-gray-900">
                                            {selected.title}
                                        </h2>
                                        <p className="mt-1 text-sm font-medium text-gray-800">
                                            {selected.assigned_person ? (
                                                <span>
                                                    {selected.assigned_person}
                                                </span>
                                            ) : (
                                                <span className="text-gray-400 italic">
                                                    Vacant
                                                </span>
                                            )}
                                        </p>
                                        <p className="mt-2 text-xs text-gray-500">
                                            {selected.has_photo
                                                ? 'Official photo on file.'
                                                : 'No official photo is on file for this position.'}
                                        </p>
                                    </div>

                                    {selected.children.length > 0 && (
                                        <div className="mt-5 border-t border-gray-100 pt-4">
                                            <p className="text-[10px] font-bold tracking-wider text-gray-400 uppercase">
                                                Directly under this position
                                            </p>
                                            <ul className="mt-2 space-y-1.5">
                                                {selected.children.map(
                                                    (child) => (
                                                        <li
                                                            key={child.id}
                                                            className="flex items-start gap-2 text-xs text-gray-600"
                                                        >
                                                            <span className="mt-1.5 h-1 w-1 shrink-0 rounded-full bg-[#3f6f3d]" />
                                                            <span>
                                                                <span className="font-medium text-gray-900">
                                                                    {
                                                                        child.title
                                                                    }
                                                                </span>
                                                                {' — '}
                                                                {child.assigned_person ? (
                                                                    <span>
                                                                        {
                                                                            child.assigned_person
                                                                        }
                                                                    </span>
                                                                ) : (
                                                                    <span className="text-gray-400 italic">
                                                                        Vacant
                                                                    </span>
                                                                )}
                                                            </span>
                                                        </li>
                                                    ),
                                                )}
                                            </ul>
                                        </div>
                                    )}
                                </div>
                            ) : (
                                <div className="py-6 text-center">
                                    <div className="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-gray-50 text-gray-400">
                                        <PersonIcon className="h-6 w-6" />
                                    </div>
                                    <h2 className="mt-3 text-sm font-bold text-gray-800">
                                        Select a position
                                    </h2>
                                    <p className="mx-auto mt-1 max-w-[220px] text-xs leading-relaxed text-gray-500">
                                        Choose any position in the chart to see
                                        its official photo and reporting
                                        structure.
                                    </p>
                                </div>
                            )}

                            <p className="mt-5 border-t border-gray-100 pt-4 text-[11px] leading-relaxed text-gray-400">
                                Only positions published by barangay staff
                                appear here. No personal information is
                                displayed unless an official photo is on file.
                            </p>
                        </aside>
                    </div>
                )}

                <p className="text-center text-[11px] text-gray-400">
                    Viewed as {resident.full_name}
                </p>
            </div>
        </ResidentLayout>
    );
}

function BuildingIcon({ className }: { className?: string }) {
    return (
        <svg
            viewBox="0 0 24 24"
            fill="none"
            className={className}
            stroke="currentColor"
            strokeWidth="1.6"
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
        >
            <path d="M4 21V6l8-3 8 3v15" />
            <path d="M2 21h20" />
            <path d="M9 10h1M14 10h1M9 14h1M14 14h1M9 18h1M14 18h1" />
        </svg>
    );
}

function PersonIcon({ className }: { className?: string }) {
    return (
        <svg
            viewBox="0 0 24 24"
            fill="none"
            className={className}
            stroke="currentColor"
            strokeWidth="1.6"
            strokeLinecap="round"
            strokeLinejoin="round"
            aria-hidden="true"
        >
            <circle cx="12" cy="8" r="4" />
            <path d="M4 21c.8-4.2 3.5-6 8-6s7.2 1.8 8 6" />
        </svg>
    );
}

function ResidentPhoto({
    node,
    size,
}: {
    node: OrgChartNode;
    size: 'sm' | 'lg';
}) {
    const dimension = size === 'lg' ? 'h-24 w-24' : 'h-12 w-12';

    if (node.photo_url) {
        return (
            <img
                src={node.photo_url}
                alt={`${node.title} official photo`}
                loading="lazy"
                decoding="async"
                className={`${dimension} shrink-0 rounded-full border border-gray-200 object-cover`}
            />
        );
    }

    return (
        <div
            className={`${dimension} flex shrink-0 items-center justify-center rounded-full border border-dashed border-gray-300 bg-gray-50 text-gray-400`}
            aria-label="No photo on file"
        >
            <svg
                viewBox="0 0 24 24"
                fill="none"
                className={size === 'lg' ? 'h-10 w-10' : 'h-6 w-6'}
                stroke="currentColor"
                strokeWidth="1.6"
                strokeLinecap="round"
                strokeLinejoin="round"
                aria-hidden="true"
            >
                <circle cx="12" cy="8" r="4" />
                <path d="M4 21c.8-4.2 3.5-6 8-6s7.2 1.8 8 6" />
            </svg>
        </div>
    );
}
