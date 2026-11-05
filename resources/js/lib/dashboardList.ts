/** Accept legacy keyed lists while keeping malformed props out of list rendering. */
export function dashboardList<T extends { id: number | string }>(value: unknown): T[] {
    const items = Array.isArray(value)
        ? value
        : value && typeof value === 'object' && Object.keys(value).every(key => /^\d+$/.test(key))
          ? Object.values(value)
          : [];
    return items.filter((item): item is T => item !== null && typeof item === 'object' && (typeof item.id === 'number' || typeof item.id === 'string'));
}
