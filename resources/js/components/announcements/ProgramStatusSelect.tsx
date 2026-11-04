export default function ProgramStatusSelect({
    value,
    onChange,
}: {
    value: string;
    onChange: (value: string) => void;
}) {
    return (
        <label className="block text-xs font-semibold text-gray-700">
            Program status
            <select
                value={value}
                onChange={(event) => onChange(event.target.value)}
                className="mt-1 block w-full rounded-md border-gray-300 text-sm"
            >
                <option value="ongoing">Ongoing — publish immediately</option>
                <option value="draft">Draft — keep unpublished</option>
            </select>
        </label>
    );
}
