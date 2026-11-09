type Props = {
    value: string;
    onChange: (value: string) => void;
    required?: boolean;
    className?: string;
};

const months = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

// Display explicit date parts and preserve the YYYY-MM-DD storage format.
export default function BirthdateInput({ value, onChange, required, className }: Props) {
    const [year = '', month = '', day = ''] = value.split('-');
    const daysInMonth = month ? new Date(Number(year) || 2000, Number(month), 0).getDate() : 31;
    const update = (part: 'year' | 'month' | 'day', next: string) => {
        const parts = { year, month, day, [part]: next };
        const limit = parts.month ? new Date(Number(parts.year) || 2000, Number(parts.month), 0).getDate() : 31;
        if (Number(parts.day) > limit) parts.day = '';
        onChange([parts.year, parts.month, parts.day].join('-'));
    };
    const selectClass = 'min-w-0 w-full rounded-lg border border-line bg-white px-2 py-2 text-sm';
    return (
        <span className={['grid grid-cols-[1.4fr_1fr_1fr] gap-2', className].filter(Boolean).join(' ')}>
            <select aria-label="Birth month" value={month} onChange={(event) => update('month', event.target.value)} required={required} className={selectClass}>
                <option value="">Month</option>
                {months.map((name, index) => <option key={name} value={String(index + 1).padStart(2, '0')}>{name}</option>)}
            </select>
            <select aria-label="Birth day" value={day} onChange={(event) => update('day', event.target.value)} required={required} className={selectClass}>
                <option value="">Day</option>
                {Array.from({ length: daysInMonth }, (_, index) => index + 1).map((number) => <option key={number} value={String(number).padStart(2, '0')}>{number}</option>)}
            </select>
            <select aria-label="Birth year" value={year} onChange={(event) => update('year', event.target.value)} required={required} className={selectClass}>
                <option value="">Year</option>
                {Array.from({ length: 151 }, (_, index) => new Date().getFullYear() - index).map((number) => <option key={number} value={number}>{number}</option>)}
            </select>
        </span>
    );
}
