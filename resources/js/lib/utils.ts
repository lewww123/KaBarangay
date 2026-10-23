import type { ClassValue } from 'clsx';
import { clsx } from 'clsx';
import { twMerge } from 'tailwind-merge';

export function cn(...inputs: ClassValue[]) {
    return twMerge(clsx(inputs));
}

/**
 * Convert a backend-serialized date/time value into the `YYYY-MM-DDTHH:mm`
 * format required by `<input type="datetime-local">`.
 *
 * Laravel serializes dates as ISO-8601 (e.g. "2026-11-15T08:30:00.000000Z").
 * The leading wall-clock components are reused verbatim, so the value is not
 * shifted into the viewer's browser timezone. Null or empty values are
 * returned as an empty string, which renders an empty input.
 */
export function toDateTimeLocalValue(value?: string | null): string {
    if (!value) {
        return '';
    }

    const match = value.match(/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2})/);

    return match ? match[1] : '';
}
