type PortalPageSkeletonProps = {
    metrics?: number;
    rows?: number;
    columns?: number;
    showTabs?: boolean;
    list?: boolean;
};

export default function PortalPageSkeleton({
    metrics = 0,
    rows = 5,
    columns = 4,
    showTabs = false,
    list = false,
}: PortalPageSkeletonProps) {
    return (
        <div className="animate-pulse space-y-5" role="status" aria-label="Loading data">
            <span className="sr-only">Loading data</span>

            {metrics > 0 && (
                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    {Array.from({ length: metrics }).map((_, index) => (
                        <div key={index} className="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
                            <div className="h-2.5 w-24 rounded bg-gray-200" />
                            <div className="mt-4 h-8 w-16 rounded bg-gray-200" />
                        </div>
                    ))}
                </div>
            )}

            {showTabs && (
                <div className="flex flex-wrap gap-2 rounded-2xl border border-gray-200 bg-white p-4">
                    {Array.from({ length: 4 }).map((_, index) => (
                        <div key={index} className="h-8 w-24 rounded-lg bg-gray-200" />
                    ))}
                </div>
            )}

            {list ? (
                <div className="space-y-3">
                    {Array.from({ length: rows }).map((_, index) => (
                        <div key={index} className="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm">
                            <div className="flex gap-3">
                                <div className="h-3 w-28 rounded bg-gray-200" />
                                <div className="h-3 w-20 rounded bg-gray-100" />
                            </div>
                            <div className="mt-4 h-3 w-2/3 rounded bg-gray-200" />
                            <div className="mt-3 h-3 w-1/2 rounded bg-gray-100" />
                        </div>
                    ))}
                </div>
            ) : (
                <div className="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm">
                    <div className="flex gap-4 border-b border-gray-200 bg-[#fafaf7] px-5 py-3">
                        {Array.from({ length: columns }).map((_, index) => (
                            <div key={index} className="h-2.5 flex-1 rounded bg-gray-200" />
                        ))}
                    </div>

                    {Array.from({ length: rows }).map((_, rowIndex) => (
                        <div key={rowIndex} className="flex gap-4 border-b border-gray-100 px-5 py-4 last:border-0">
                            {Array.from({ length: columns }).map((_, columnIndex) => (
                                <div
                                    key={columnIndex}
                                    className="h-3 flex-1 rounded bg-gray-100"
                                    style={{ opacity: Math.max(0.35, 1 - rowIndex * 0.1) }}
                                />
                            ))}
                        </div>
                    ))}
                </div>
            )}
        </div>
    );
}
