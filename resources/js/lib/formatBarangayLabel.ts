/**
 * Formats a barangay name for staff portal branding (display-only).
 * - Trims leading/trailing whitespace.
 * - Removes only a leading "Barangay" prefix, case-insensitively, including surrounding whitespace.
 * - Adds exactly one canonical "Barangay " prefix to non-empty normalized names.
 * - Returns "Barangay" for null/empty/whitespace-only/prefix-only input.
 * Does not modify stored/backend data. Does not strip "Barangay" when it appears elsewhere.
 */
export function formatBarangayLabel(name?: string | null): string {
    if (name == null) return 'Barangay';

    const trimmed = String(name).trim();
    if (trimmed === '') return 'Barangay';

    if (/^barangay$/i.test(trimmed)) return 'Barangay';

    const normalized = trimmed.replace(/^barangay\s+/i, '').trim();
    if (normalized === '') return 'Barangay';

    return `Barangay ${normalized}`;
}
