import { useEffect, useId, type FormEvent, type ReactNode } from 'react';

import { X } from 'lucide-react';

import { cn } from '@/lib/utils';

type FinancialFieldProps = {
    label: string;
    error?: string;
    hint?: string;
    required?: boolean;
    children: ReactNode;
};

export function FinancialField({
    label,
    error,
    hint,
    required = false,
    children,
}: FinancialFieldProps) {
    return (
        <label className="block">
            <span className="text-ink-muted text-xs font-semibold tracking-wide uppercase">
                {label}
                {required ? (
                    <span className="text-destructive ml-1">*</span>
                ) : null}
            </span>
            <div className="mt-1">{children}</div>
            {hint ? (
                <span className="text-ink-muted mt-1 block text-xs">
                    {hint}
                </span>
            ) : null}
            {error ? (
                <span className="text-destructive mt-1 block text-xs">
                    {error}
                </span>
            ) : null}
        </label>
    );
}

type FinancialModalProps = {
    title: string;
    description?: string;
    onClose: () => void;
    children: ReactNode;
    wide?: boolean;
};

export function FinancialModal({
    title,
    description,
    onClose,
    children,
    wide = false,
}: FinancialModalProps) {
    const titleId = useId();

    useEffect(() => {
        const handleKeyDown = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                onClose();
            }
        };

        window.addEventListener('keydown', handleKeyDown);

        return () => window.removeEventListener('keydown', handleKeyDown);
    }, [onClose]);

    return (
        <div className="bg-ink-strong/40 fixed inset-0 z-50 flex items-start justify-center overflow-y-auto p-4 sm:p-8">
            <div
                role="dialog"
                aria-modal="true"
                aria-labelledby={titleId}
                className={cn(
                    'border-line-soft w-full rounded-2xl border bg-white shadow-xl',
                    wide ? 'max-w-5xl' : 'max-w-2xl',
                )}
            >
                <div className="border-line-soft flex items-start justify-between gap-4 border-b p-5">
                    <div>
                        <h2
                            id={titleId}
                            className="text-ink-strong text-lg font-semibold"
                        >
                            {title}
                        </h2>
                        {description ? (
                            <p className="text-ink-muted mt-1 text-sm">
                                {description}
                            </p>
                        ) : null}
                    </div>
                    <button
                        type="button"
                        onClick={onClose}
                        aria-label="Close dialog"
                        className="border-line-soft text-ink-muted hover:bg-canvas rounded-lg border p-2 transition"
                    >
                        <X className="size-4" />
                    </button>
                </div>
                <div className="p-5">{children}</div>
            </div>
        </div>
    );
}

type FinancialActionButtonProps = {
    type?: 'button' | 'submit' | 'reset';
    onClick?: () => void;
    disabled?: boolean;
    children: ReactNode;
    variant?: 'primary' | 'secondary' | 'danger';
};

export function FinancialActionButton({
    type = 'button',
    onClick,
    disabled = false,
    children,
    variant = 'primary',
}: FinancialActionButtonProps) {
    return (
        <button
            type={type}
            onClick={onClick}
            disabled={disabled}
            className={cn(
                'inline-flex items-center justify-center gap-2 rounded-lg px-4 py-2 text-sm font-semibold transition disabled:cursor-not-allowed disabled:opacity-60',
                variant === 'primary' &&
                    'bg-brand hover:bg-brand-deep text-white',
                variant === 'secondary' &&
                    'border-line-soft text-ink hover:bg-canvas border bg-white',
                variant === 'danger' &&
                    'border-destructive/30 bg-danger-soft text-destructive border',
            )}
        >
            {children}
        </button>
    );
}

type FinancialAlertProps = {
    success?: string;
    error?: string | null;
};

export function FinancialAlert({ success, error }: FinancialAlertProps) {
    if (!success && !error) {
        return null;
    }

    return (
        <div className="space-y-2">
            {success ? (
                <div
                    role="status"
                    className="border-brand-muted bg-brand-soft text-brand-deep rounded-lg border px-4 py-3 text-sm"
                >
                    {success}
                </div>
            ) : null}
            {error ? (
                <div
                    role="alert"
                    className="border-destructive/30 bg-danger-soft text-destructive rounded-lg border px-4 py-3 text-sm"
                >
                    {error}
                </div>
            ) : null}
        </div>
    );
}

type FinancialFilterBarProps = {
    onSubmit: (event: FormEvent<HTMLFormElement>) => void;
    onReset: () => void;
    busy?: boolean;
    children: ReactNode;
};

export function FinancialFilterBar({
    onSubmit,
    onReset,
    busy = false,
    children,
}: FinancialFilterBarProps) {
    return (
        <form
            onSubmit={onSubmit}
            className="border-line-soft shadow-card rounded-xl border bg-white p-4"
        >
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                {children}
            </div>
            <div className="mt-4 flex flex-wrap items-center gap-2">
                <FinancialActionButton type="submit" disabled={busy}>
                    Apply filters
                </FinancialActionButton>
                <FinancialActionButton
                    type="button"
                    variant="secondary"
                    onClick={onReset}
                    disabled={busy}
                >
                    Clear filters
                </FinancialActionButton>
            </div>
        </form>
    );
}
