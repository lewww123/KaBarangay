import { useEffect, useId, useMemo, useRef, useState } from 'react';

export interface AttendanceResidentOption {
    id: number;
    first_name: string;
    last_name: string;
    middle_name?: string;
    suffix?: string;
    resident_number: string;
}

type Props = {
    residents: AttendanceResidentOption[];
    value: string;
    onChange: (residentId: string) => void;
    disabled?: boolean;
    label?: string;
    placeholder?: string;
};

function displayName(resident: AttendanceResidentOption): string {
    return `${resident.last_name}, ${resident.first_name} (${resident.resident_number})`;
}

export default function AttendanceResidentSelect({
    residents,
    value,
    onChange,
    disabled = false,
    label = 'Piliin ang Residente',
    placeholder = 'Mag-type ng pangalan o ID number...',
}: Props) {
    const id = useId();
    const listId = `${id}-list`;
    const containerRef = useRef<HTMLDivElement | null>(null);
    const selected = useMemo(
        () => residents.find((resident) => String(resident.id) === value) ?? null,
        [residents, value],
    );
    const [query, setQuery] = useState(selected ? displayName(selected) : '');
    const [open, setOpen] = useState(false);
    const [active, setActive] = useState(-1);

    useEffect(() => {
        if (selected) {
            setQuery(displayName(selected));
            return;
        }

        if (value === '') {
            setQuery('');
        }
    }, [selected, value]);

    const matches = useMemo(() => {
        const term = query.trim().toLowerCase();
        if (term.length < 1) {
            return residents.slice(0, 8);
        }

        return residents
            .filter((resident) => {
                const haystack = `${resident.last_name} ${resident.first_name} ${resident.middle_name ?? ''} ${resident.suffix ?? ''} ${resident.resident_number}`.toLowerCase();
                return haystack.includes(term);
            })
            .slice(0, 8);
    }, [query, residents]);

    useEffect(() => {
        if (!open) {
            setActive(-1);
        }
    }, [open]);

    const selectResident = (resident: AttendanceResidentOption) => {
        onChange(String(resident.id));
        setQuery(displayName(resident));
        setOpen(false);
        setActive(-1);
    };

    const clearSelection = () => {
        if (value !== '') {
            onChange('');
        }
    };

    return (
        <div ref={containerRef} className="relative">
            <label
                htmlFor={id}
                className="block text-xs font-semibold text-ink"
            >
                {label}
            </label>
            <input
                id={id}
                type="text"
                role="combobox"
                autoComplete="off"
                required
                value={query}
                disabled={disabled}
                aria-autocomplete="list"
                aria-expanded={open}
                aria-controls={listId}
                aria-activedescendant={
                    open && active >= 0 ? `${id}-option-${active}` : undefined
                }
                placeholder={placeholder}
                className="mt-1.5 w-full rounded-lg border border-line bg-white p-2.5 text-xs outline-none focus:border-[#3f6f3d] disabled:cursor-wait disabled:bg-secondary"
                onFocus={() => setOpen(true)}
                onBlur={(event) => {
                    if (containerRef.current?.contains(event.relatedTarget)) {
                        return;
                    }

                    setOpen(false);
                    if (!selected) {
                        setQuery('');
                    } else {
                        setQuery(displayName(selected));
                    }
                }}
                onChange={(event) => {
                    const nextQuery = event.target.value;
                    setQuery(nextQuery);
                    clearSelection();
                    setOpen(true);
                    setActive(-1);
                }}
                onKeyDown={(event) => {
                    if (event.key === 'Escape') {
                        setOpen(false);
                        setActive(-1);
                        return;
                    }

                    if (
                        (event.key === 'ArrowDown' || event.key === 'ArrowUp') &&
                        matches.length > 0
                    ) {
                        event.preventDefault();
                        setOpen(true);
                        setActive((index) => {
                            if (event.key === 'ArrowDown') {
                                return (index + 1) % matches.length;
                            }

                            return index <= 0 ? matches.length - 1 : index - 1;
                        });
                        return;
                    }

                    if (
                        event.key === 'Enter' &&
                        open &&
                        active >= 0 &&
                        matches[active]
                    ) {
                        event.preventDefault();
                        selectResident(matches[active]);
                    }
                }}
            />
            {open && !disabled && (
                <ul
                    id={listId}
                    role="listbox"
                    aria-label="Matching residents"
                    className="absolute z-30 mt-1 max-h-56 w-full overflow-auto rounded-lg border bg-white py-1 shadow-lg"
                >
                    {matches.length === 0 ? (
                        <li className="px-3 py-2 text-xs text-ink-muted">
                            Walang tumugmang residente. Pumili mula sa listahan.
                        </li>
                    ) : (
                        matches.map((resident, index) => (
                            <li
                                key={resident.id}
                                id={`${id}-option-${index}`}
                                role="option"
                                aria-selected={String(resident.id) === value}
                                tabIndex={-1}
                                className={`cursor-pointer px-3 py-2 text-xs ${
                                    index === active
                                        ? 'bg-brand-soft'
                                        : 'hover:bg-secondary'
                                }`}
                                onMouseDown={(event) => event.preventDefault()}
                                onMouseEnter={() => setActive(index)}
                                onClick={() => selectResident(resident)}
                            >
                                <span className="font-semibold text-ink-strong">
                                    {resident.last_name}, {resident.first_name}
                                </span>{' '}
                                <span className="text-ink-muted">
                                    ({resident.resident_number})
                                </span>
                            </li>
                        ))
                    )}
                </ul>
            )}
            <p className="mt-1.5 text-[11px] text-ink-muted">
                Pumili ng wastong rekord ng residente mula sa mga tugma.
            </p>
            {value === '' && query.trim() !== '' && (
                <p className="mt-1 text-[11px] font-semibold text-amber-700">
                    Kailangan pumili ng wastong residente mula sa listahan.
                </p>
            )}
        </div>
    );
}
