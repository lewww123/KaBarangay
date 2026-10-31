import { cn } from '@/lib/utils';

interface AnnouncementAttendanceOptionProps {
    id: string;
    checked: boolean;
    onChange: (checked: boolean) => void;
    disabled?: boolean;
}

export default function AnnouncementAttendanceOption({
    id,
    checked,
    onChange,
    disabled = false,
}: AnnouncementAttendanceOptionProps) {
    const helpId = `${id}-help`;

    return (
        <div className="flex items-start gap-2 pt-1">
            <input
                type="checkbox"
                id={id}
                checked={checked}
                onChange={(event) => onChange(event.target.checked)}
                disabled={disabled}
                aria-describedby={helpId}
                className={cn(
                    'mt-0.5 h-4 w-4 rounded border-gray-300 text-[#3f6f3d] focus:ring-[#3f6f3d]',
                    disabled && 'opacity-60',
                )}
            />
            <div>
                <label
                    htmlFor={id}
                    className="text-xs font-semibold text-gray-700"
                >
                    Enable Attendance Sheet
                </label>
                <p id={helpId} className="mt-0.5 text-[10px] text-gray-500">
                    Optional. Turn this on only if this event needs resident
                    attendance tracking or aid-claim recording. The attendance
                    sheet becomes available when the program is published.
                </p>
            </div>
        </div>
    );
}
