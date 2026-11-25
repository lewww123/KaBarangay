import type { LucideIcon } from 'lucide-react';
import { Fragment, type ReactNode } from 'react';

import { cn } from '@/lib/utils';

type Tone = 'brand' | 'neutral' | 'success' | 'warning' | 'danger' | 'info';

const toneClasses: Record<Tone, string> = {
    brand: 'border-brand-muted bg-brand-soft text-brand-deep',
    neutral: 'border-line-soft bg-secondary text-ink-muted',
    success: 'border-success/30 bg-success-soft text-success',
    warning: 'border-warning/30 bg-warning-soft text-warning',
    danger: 'border-destructive/30 bg-danger-soft text-destructive',
    info: 'border-info/30 bg-info-soft text-info',
};

export function FinancialPageHeader({
    eyebrow,
    title,
    description,
}: {
    eyebrow: string;
    title: string;
    description: string;
}) {
    return (
        <header className="border-line-soft border-b pb-5">
            <p className="text-brand text-sm font-medium">{eyebrow}</p>
            <h1 className="text-ink-strong mt-1 text-2xl font-semibold">
                {title}
            </h1>
            <p className="text-ink-muted mt-2 max-w-3xl text-sm leading-6">
                {description}
            </p>
        </header>
    );
}

export function FinancialSection({
    id,
    title,
    description,
    children,
}: {
    id: string;
    title: string;
    description?: string;
    children: ReactNode;
}) {
    return (
        <section aria-labelledby={id} className="space-y-4">
            <div>
                <h2 id={id} className="text-ink-strong text-lg font-semibold">
                    {title}
                </h2>
                {description && (
                    <p className="text-ink-muted mt-1 text-sm leading-6">
                        {description}
                    </p>
                )}
            </div>
            {children}
        </section>
    );
}

export function FinancialMetric({
    label,
    value,
    hint,
    icon: Icon,
}: {
    label: string;
    value: string;
    hint: string;
    icon: LucideIcon;
}) {
    return (
        <div className="border-line-soft shadow-card rounded-xl border bg-white p-5">
            <div className="flex items-start justify-between gap-3">
                <div>
                    <p className="text-ink-muted text-xs font-semibold tracking-wide uppercase">
                        {label}
                    </p>
                    <p className="text-ink-strong tnum mt-3 text-lg font-semibold">
                        {value}
                    </p>
                </div>
                <span className="bg-brand-soft text-brand-deep flex size-9 shrink-0 items-center justify-center rounded-lg">
                    <Icon className="size-[18px]" />
                </span>
            </div>
            <p className="text-ink-muted mt-3 text-xs leading-5">{hint}</p>
        </div>
    );
}

export function FinancialStatusBadge({
    children,
    tone = 'neutral',
}: {
    children: ReactNode;
    tone?: Tone;
}) {
    return (
        <span
            className={cn(
                'inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-medium whitespace-nowrap',
                toneClasses[tone],
            )}
        >
            {children}
        </span>
    );
}

export function FinancialStatusFlow({ steps }: { steps: string[] }) {
    return (
        <div className="flex flex-wrap items-center gap-2">
            {steps.map((step, index) => (
                <Fragment key={step}>
                    <FinancialStatusBadge tone="neutral">
                        {step}
                    </FinancialStatusBadge>
                    {index < steps.length - 1 && (
                        <span aria-hidden="true" className="text-ink-faint">
                            →
                        </span>
                    )}
                </Fragment>
            ))}
        </div>
    );
}

export function FinancialNotice({
    title,
    children,
    tone = 'info',
}: {
    title: string;
    children: ReactNode;
    tone?: 'info' | 'warning' | 'neutral';
}) {
    const noticeClasses: Record<'info' | 'warning' | 'neutral', string> = {
        info: 'border-brand-muted bg-brand-soft text-brand-deep',
        warning: 'border-amber-200 bg-amber-50 text-amber-950',
        neutral: 'border-line-soft bg-surface text-ink-muted',
    };

    return (
        <div
            className={cn(
                'rounded-xl border p-4 text-sm leading-6',
                noticeClasses[tone],
            )}
        >
            <p className="font-semibold">{title}</p>
            <div className="mt-1">{children}</div>
        </div>
    );
}

export function FinancialTableShell({ children }: { children: ReactNode }) {
    return (
        <div className="border-line-soft shadow-card overflow-hidden rounded-xl border bg-white">
            <div className="overflow-x-auto">{children}</div>
        </div>
    );
}

export function FinancialEmptyTableRow({
    colSpan,
    title,
    description,
}: {
    colSpan: number;
    title: string;
    description: string;
}) {
    return (
        <tr>
            <td colSpan={colSpan} className="px-5 py-10 text-center">
                <p className="text-ink-strong text-sm font-semibold">{title}</p>
                <p className="text-ink-muted mx-auto mt-1 max-w-md text-xs leading-5">
                    {description}
                </p>
            </td>
        </tr>
    );
}

export function FinancialEmptyState({
    title,
    description,
}: {
    title: string;
    description: string;
}) {
    return (
        <div className="border-line bg-surface rounded-xl border border-dashed p-8 text-center">
            <p className="text-ink-strong text-sm font-semibold">{title}</p>
            <p className="text-ink-muted mx-auto mt-1 max-w-md text-xs leading-5">
                {description}
            </p>
        </div>
    );
}

export function FinancialDetailList({
    items,
}: {
    items: { label: string; value: string }[];
}) {
    return (
        <dl className="divide-line-soft divide-y">
            {items.map((item) => (
                <div
                    key={item.label}
                    className="flex flex-col gap-1 py-2.5 first:pt-0 last:pb-0 sm:flex-row sm:items-center sm:justify-between sm:gap-4"
                >
                    <dt className="text-ink-muted text-sm">{item.label}</dt>
                    <dd className="text-ink-strong text-sm font-medium">
                        {item.value}
                    </dd>
                </div>
            ))}
        </dl>
    );
}
