import axios from 'axios';
import { useEffect, useRef, useState } from 'react';
import ResidentAutocomplete from '@/components/certificates/ResidentAutocomplete';
import QrCamera from './QrCamera';
import { luponError, type LuponParty, type LuponResident } from './types';
export default function PartyIntake({
    label,
    value,
    onChange,
    otherResidentId,
    autoFocus = false,
    cameraOpen,
    onCamera,
}: {
    label: string;
    value: LuponParty;
    onChange: (value: LuponParty) => void;
    otherResidentId: string;
    autoFocus?: boolean;
    cameraOpen: boolean;
    onCamera: (open: boolean) => void;
}) {
    const [query, setQuery] = useState('');
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const input = useRef<HTMLInputElement>(null);
    const version = useRef(0);
    useEffect(() => {
        if (autoFocus) input.current?.focus({ preventScroll: true });
    }, [autoFocus]);
    useEffect(
        () => () => {
            version.current++;
        },
        [],
    );
    const resolve = async (
        token: string,
        residentId?: number,
    ): Promise<boolean> => {
        const current = ++version.current;
        if (!residentId && !token.trim()) {
            setError('Scan a resident QR code or enter a resident reference.');
            return false;
        }
        setLoading(true);
        setError(null);
        try {
            const response = await axios.get<{ resident: LuponResident }>(
                residentId
                    ? `/lupon/residents/${residentId}/lookup`
                    : '/lupon/cases/qr-lookup',
                residentId ? {} : { params: { token: token.trim() } },
            );
            if (current !== version.current) return false;
            const resident = response.data.resident;
            if (String(resident.id) === otherResidentId) {
                setError(
                    'Complainant and respondent cannot be the same registered resident.',
                );
                return false;
            }
            onChange({
                name: resident.name,
                residentId: String(resident.id),
                isResident: true,
                address: resident.address || '',
                contact: resident.contact || '',
            });
            setQuery('');
            return true;
        } catch (caught: unknown) {
            if (current === version.current) setError(luponError(caught));
            return false;
        } finally {
            if (current === version.current) setLoading(false);
        }
    };
    const unlink = (name: string) => {
        version.current++;
        setLoading(false);
        setError(null);
        onChange({
            ...value,
            name,
            residentId: '',
            address: value.residentId ? '' : value.address,
            contact: value.residentId ? '' : value.contact,
        });
    };
    return (
        <section className="space-y-4 rounded-xl border border-line-soft bg-white p-4 sm:p-5">
            <h2 className="font-semibold text-ink-strong">{label}</h2>
            <div className="flex flex-wrap gap-2">
                <input
                    ref={input}
                    value={query}
                    onChange={(event) => setQuery(event.target.value)}
                    onKeyDown={(event) => {
                        if (event.key === 'Enter') {
                            event.preventDefault();
                            void resolve(query);
                        }
                    }}
                    placeholder="Scan QR / enter resident number"
                    aria-label={`${label} QR or resident reference`}
                    className="min-w-0 flex-1 rounded-lg border border-line px-3 py-2 text-sm"
                />
                <button
                    type="button"
                    disabled={loading}
                    onClick={() => void resolve(query)}
                    className="rounded-lg bg-brand px-3 py-2 text-xs font-semibold text-white disabled:opacity-50"
                >
                    {loading ? 'Looking up…' : 'Look up'}
                </button>
                <button
                    type="button"
                    onClick={() => onCamera(!cameraOpen)}
                    className="rounded-lg border border-line px-3 py-2 text-xs font-semibold"
                >
                    Camera
                </button>
            </div>
            {cameraOpen && (
                <QrCamera
                    onScan={(code) => resolve(code)}
                    onClose={() => onCamera(false)}
                />
            )}
            {error && (
                <p role="alert" className="text-xs text-red-700">
                    {error}
                </p>
            )}
            <ResidentAutocomplete
                endpoint="/lupon/residents/search"
                label="Full name"
                value={value.name}
                onChange={unlink}
                onSelect={(resident) => void resolve('', resident.id)}
            />
            {value.residentId && (
                <p className="text-xs font-medium text-emerald-700">
                    Linked to the masterlist. Editing the name switches to
                    manual entry.
                </p>
            )}
            <div className="grid gap-3 sm:grid-cols-2">
                <label className="text-xs font-semibold text-ink">
                    Address
                    <input
                        value={value.address}
                        readOnly={Boolean(value.residentId)}
                        onChange={(event) =>
                            onChange({ ...value, address: event.target.value })
                        }
                        className="mt-1 w-full rounded-lg border border-line px-3 py-2 text-sm read-only:bg-canvas"
                    />
                </label>
                <label className="text-xs font-semibold text-ink">
                    Contact number
                    <input
                        value={value.contact}
                        readOnly={Boolean(value.residentId)}
                        onChange={(event) =>
                            onChange({ ...value, contact: event.target.value })
                        }
                        className="mt-1 w-full rounded-lg border border-line px-3 py-2 text-sm read-only:bg-canvas"
                    />
                </label>
            </div>
            {!value.residentId && (
                <label className="flex items-center gap-2 text-xs text-ink-muted">
                    <input
                        type="checkbox"
                        checked={value.isResident}
                        onChange={(event) =>
                            onChange({
                                ...value,
                                isResident: event.target.checked,
                            })
                        }
                    />
                    Complainant/respondent states that they reside in this
                    barangay
                </label>
            )}
        </section>
    );
}
