type AdminPageSkeletonProps = {
    metrics?: number;
    rows?: number;
    columns?: number;
    showTabs?: boolean;
};

export default function AdminPageSkeleton({
    metrics = 0,
    rows = 5,
    columns = 4,
    showTabs = false,
}: AdminPageSkeletonProps) {
    return (
        <div className="animate-pulse space-y-6" role="status" aria-label="Loading data">
            <span className="sr-only">Loading data</span>

            {metrics > 0 && (
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                    {Array.from({ length: metrics }).map((_, index) => (
                        <div key={index} className="rounded-lg border border-gray-200 bg-white p-4">
                            <div className="h-2.5 w-16 rounded bg-gray-200" />
                            <div className="mt-4 h-7 w-12 rounded bg-gray-200" />
                            <div className="mt-2 h-2 w-20 rounded bg-gray-100" />
                        </div>
                    ))}
                </div>
            )}

            {showTabs && (
                <div className="flex gap-2 border-b border-gray-200 pb-3">
                    {Array.from({ length: 4 }).map((_, index) => (
                        <div key={index} className="h-8 w-28 rounded bg-gray-200" />
                    ))}
                </div>
            )}

            <div className="overflow-hidden rounded-lg border border-gray-200 bg-white">
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
        </div>
    );
}
