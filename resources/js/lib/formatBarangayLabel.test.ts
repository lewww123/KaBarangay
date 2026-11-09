import { describe, expect, it } from 'vitest';
import { formatBarangayLabel } from './formatBarangayLabel';

describe('formatBarangayLabel', () => {
    it('formats required examples', () => {
        expect(formatBarangayLabel('Sicsican')).toBe('Barangay Sicsican');
        expect(formatBarangayLabel('Barangay Sicsican')).toBe('Barangay Sicsican');
        expect(formatBarangayLabel('barangay Sicsican')).toBe('Barangay Sicsican');
        expect(formatBarangayLabel('  Barangay   Sta. Monica  ')).toBe('Barangay Sta. Monica');
        expect(formatBarangayLabel('San Jose')).toBe('Barangay San Jose');
        expect(formatBarangayLabel(null)).toBe('Barangay');
        expect(formatBarangayLabel('')).toBe('Barangay');
        expect(formatBarangayLabel('Barangay')).toBe('Barangay');
    });

    it('does not strip Barangay when it appears elsewhere in the name', () => {
        expect(formatBarangayLabel('Sicsican Barangay Annex')).toBe('Barangay Sicsican Barangay Annex');
        expect(formatBarangayLabel('Barangay Sicsican Barangay Annex')).toBe('Barangay Sicsican Barangay Annex');
    });

    it('handles undefined and whitespace-only values', () => {
        expect(formatBarangayLabel(undefined)).toBe('Barangay');
        expect(formatBarangayLabel('   ')).toBe('Barangay');
        expect(formatBarangayLabel(' barangay ')).toBe('Barangay');
    });
});
