import markUrl from '@/assets/kabarangay-mark.png';

import { cn } from '@/lib/utils';

type MarkProps = {
    className?: string;
    /** "onBrand" = mark sits on the blue tile, "onLight" = on white surfaces. */
    tone?: 'onBrand' | 'onLight';
    size?: 'sm' | 'md' | 'lg';
};

const sizes = {
    sm: 'size-8 rounded-lg',
    md: 'size-9 rounded-[10px]',
    lg: 'size-12 rounded-xl',
};

/**
 * The KaBarangay mark (three barangay houses over an arc) as a tile. The
 * artwork ships in the brand blue on transparency, so the on-brand tile flips
 * it to white with a filter instead of shipping a second file.
 */
export function LogoMark({
    className,
    tone = 'onLight',
    size = 'md',
}: MarkProps) {
    const onBrand = tone === 'onBrand';

    return (
        <span
            aria-hidden
            className={cn(
                'inline-flex shrink-0 items-center justify-center overflow-hidden',
                sizes[size],
                onBrand ? 'bg-white' : 'bg-brand',
                className,
            )}
        >
            <img
                src={markUrl}
                alt=""
                className={cn('w-[80%]', !onBrand && 'brightness-0 invert')}
            />
        </span>
    );
}

type LogoProps = {
    tone?: 'onBrand' | 'onLight';
    size?: 'sm' | 'md' | 'lg';
    subtitle?: string;
    collapsed?: boolean;
    className?: string;
};

export function Logo({
    tone = 'onLight',
    size = 'md',
    subtitle = 'Barangay Management System',
    collapsed = false,
    className,
}: LogoProps) {
    const textTone = tone === 'onBrand' ? 'text-white' : 'text-ink-strong';
    const subTone = tone === 'onBrand' ? 'text-white/70' : 'text-ink-muted';

    return (
        <div className={cn('flex min-w-0 items-center gap-3', className)}>
            <LogoMark tone={tone} size={size} />
            {!collapsed && (
                <div className="min-w-0 leading-tight">
                    <p
                        className={cn(
                            'truncate text-[15px] font-semibold tracking-tight',
                            textTone,
                        )}
                    >
                        KaBarangay
                    </p>
                    <p
                        className={cn(
                            'truncate text-[11px] font-medium',
                            subTone,
                        )}
                    >
                        {subtitle}
                    </p>
                </div>
            )}
        </div>
    );
}
