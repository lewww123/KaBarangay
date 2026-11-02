import { useEffect, useId, useRef, useState } from 'react';

export interface ResidentMatch {
    id: number;
    full_name: string;
    purok: string;
    birthdate: string | null;
    age: number | null;
    sex: string | null;
    civil_status: string | null;
    address_details: string;
}

type Props = {
    endpoint?: string;
    label?: string;
    value: string;
    onChange: (value: string) => void;
    onSelect: (resident: ResidentMatch) => void;
};

/** Small, shared staff combobox: no masterlist download or search dependency. */
export default function ResidentAutocomplete({ value, onChange, onSelect, endpoint = '/admin/residents/search', label = 'Resident Name' }: Props) {
    const id = useId();
    const [query, setQuery] = useState('');
    const [matches, setMatches] = useState<ResidentMatch[]>([]);
    const [open, setOpen] = useState(false);
    const [active, setActive] = useState(-1);
    const [status, setStatus] = useState<'idle' | 'loading' | 'done' | 'error'>('idle');
    const request = useRef<AbortController | null>(null);
    const version = useRef(0);

    useEffect(() => {
        const term = query.trim();
        if (Array.from(term).length < 2) return;
        const controller = new AbortController();
        const currentVersion = version.current;
        request.current = controller;
        const timer = window.setTimeout(async () => {
            setStatus('loading');
            try {
                const response = await fetch(`${endpoint}?q=${encodeURIComponent(term)}`, {
                    signal: controller.signal,
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                });
                if (!response.ok) throw new Error('Search unavailable');
                const result: { residents: ResidentMatch[] } = await response.json();
                if (!controller.signal.aborted && currentVersion === version.current) {
                    setMatches(result.residents.slice(0, 5));
                    setActive(-1);
                    setStatus('done');
                }
            } catch {
                if (!controller.signal.aborted && currentVersion === version.current) {
                    setStatus('error');
                    setMatches([]);
                }
            }
        }, 300);
        return () => { window.clearTimeout(timer); controller.abort(); };
    }, [query, endpoint]);

    const select = (resident: ResidentMatch) => {
        version.current++;
        request.current?.abort();
        setQuery('');
        setMatches([]);
        setOpen(false);
        setStatus('idle');
        onSelect(resident);
    };

    return (
        <div className="relative" onBlur={(event) => {
            if (!event.currentTarget.contains(event.relatedTarget)) setOpen(false);
        }}>
            <label htmlFor={id} className="mb-1 block text-xs font-semibold text-gray-600 uppercase">{label}</label>
            <input id={id} type="text" role="combobox" autoComplete="off" required value={value}
                aria-autocomplete="list" aria-expanded={open && matches.length > 0}
                aria-controls={`${id}-list`} aria-describedby={`${id}-hint`}
                aria-activedescendant={open && active >= 0 ? `${id}-option-${active}` : undefined}
                placeholder="Search resident or type a walk-in name"
                className="w-full rounded-md border px-3 py-2 text-sm"
                onFocus={() => setOpen(true)}
                onChange={(event) => {
                    version.current++;
                    request.current?.abort();
                    setMatches([]);
                    setActive(-1);
                    setStatus('idle');
                    setOpen(true);
                    setQuery(event.target.value);
                    onChange(event.target.value);
                }}
                onKeyDown={(event) => {
                    if (event.key === 'Escape') { setOpen(false); setActive(-1); }
                    if ((event.key === 'ArrowDown' || event.key === 'ArrowUp') && matches.length) {
                        event.preventDefault();
                        setOpen(true);
                        setActive((index) => event.key === 'ArrowDown'
                            ? (index + 1) % matches.length
                            : (index <= 0 ? matches.length - 1 : index - 1));
                    }
                    if (event.key === 'Enter' && open && active >= 0 && matches[active]) {
                        event.preventDefault();
                        select(matches[active]);
                    }
                }} />
            {open && matches.length > 0 && (
                <ul id={`${id}-list`} role="listbox" aria-label="Matching residents"
                    className="absolute z-30 mt-1 max-h-56 w-full overflow-auto rounded-md border bg-white py-1 shadow-lg">
                    {matches.map((resident, index) => (
                        <li key={resident.id} id={`${id}-option-${index}`} role="option" aria-selected={index === active}
                            className={`cursor-pointer px-3 py-2 text-sm ${index === active ? 'bg-green-100' : 'hover:bg-gray-100'}`}
                            onMouseDown={(event) => event.preventDefault()}
                            onMouseEnter={() => setActive(index)} onClick={() => select(resident)}>
                            {resident.full_name} <span className="text-gray-500">• {resident.purok || 'No purok listed'}</span>
                        </li>
                    ))}
                </ul>
            )}
            <p id={`${id}-hint`} role="status" className="sr-only">
                {open && status === 'loading' ? 'Searching…'
                    : open && status === 'error' ? 'Search unavailable. You can still enter a walk-in name.'
                    : open && status === 'done' && !matches.length ? 'No matching resident. Continue with manual entry.'
                    : ''}
            </p>
        </div>
    );
}