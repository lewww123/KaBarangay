import { useEffect, useRef, useState, type PointerEvent, type ReactNode } from 'react';

const SIZE = 56;
const EDGE = 16;
const POSITION_KEY = 'kabarangay-ai-launcher-position:v1';
const limits = () => ({ min: EDGE, max: Math.max(EDGE, window.innerHeight - SIZE - EDGE) });
const clamp = (top: number) => Math.min(limits().max, Math.max(EDGE, top));

export type LauncherPosition = { top: number; viewportHeight: number };

export default function AssistantLauncher({ open, onToggle, onPositionChange, children }: {
    open: boolean;
    onToggle: () => void;
    onPositionChange: (position: LauncherPosition) => void;
    children: ReactNode;
}) {
    const [top, setTop] = useState<number | null>(null);
    const [viewportHeight, setViewportHeight] = useState(0);
    const [moving, setMoving] = useState(false);
    const drag = useRef<{ id: number; x: number; y: number; top: number; moved: boolean } | null>(null);
    const skipClick = useRef(false);
    const positionCallback = useRef(onPositionChange);
    positionCallback.current = onPositionChange;

    const remember = (value: number) => {
        const { min, max } = limits();
        try { localStorage.setItem(POSITION_KEY, String(max > min ? (value - min) / (max - min) : 1)); } catch { /* Dragging also works when storage is unavailable. */ }
    };

    useEffect(() => {
        let initial = limits().max;
        try {
            const saved = localStorage.getItem(POSITION_KEY);
            const ratio = saved === null ? NaN : Number(saved);
            if (Number.isFinite(ratio) && ratio >= 0 && ratio <= 1) {
                const { min, max } = limits();
                initial = min + ratio * (max - min);
            }
        } catch { /* Use the bottom-right position by default. */ }
        setTop(initial);
        setViewportHeight(window.innerHeight);
        const resize = () => {
            setViewportHeight(window.innerHeight);
            setTop(value => clamp(value ?? limits().max));
        };
        window.addEventListener('resize', resize);
        return () => window.removeEventListener('resize', resize);
    }, []);

    useEffect(() => {
        if (top !== null && viewportHeight > 0) positionCallback.current({ top, viewportHeight });
    }, [top, viewportHeight]);

    const finishDrag = (event: PointerEvent<HTMLButtonElement>) => {
        const current = drag.current;
        if (!current || current.id !== event.pointerId) return;
        if (current.moved) {
            skipClick.current = true;
            remember(event.type === 'pointercancel' ? top ?? current.top : clamp(current.top + event.clientY - current.y));
        } else if (event.type === 'pointerup' && event.pointerType !== 'mouse') {
            // Touch/pen browsers can omit the synthesized click after capture.
            // Handle the tap here and suppress a subsequent compatibility click.
            skipClick.current = true;
            onToggle();
        }
        drag.current = null;
        setMoving(false);
        if (event.currentTarget.hasPointerCapture(event.pointerId)) event.currentTarget.releasePointerCapture(event.pointerId);
    };

    return <>
        <span id="kabarangay-ai-move-help" className="sr-only">I-drag pataas o pababa sa kanang gilid. Sa keyboard, gamitin ang arrow up at arrow down para ilipat, at Enter para buksan o isara.</span>
        <button type="button"
            aria-expanded={open} aria-controls="kabarangay-ai-chat"
            aria-label={open ? 'Isara si Kabarangay AI' : 'Kausapin si Kabarangay AI'}
            aria-describedby="kabarangay-ai-move-help"
            title="Kabarangay AI — i-drag pataas o pababa"
            style={top === null ? { bottom: EDGE } : { top }}
            className={`fixed right-4 z-[60] flex h-14 w-14 touch-none select-none items-center justify-center rounded-full border border-emerald-300 bg-gradient-to-br from-emerald-900 to-[#3f6f3d] p-2 text-white shadow-xl focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-emerald-700 ${moving ? 'cursor-grabbing' : 'cursor-grab'}`}
            onClick={(event) => {
                if (skipClick.current && event.detail !== 0) { skipClick.current = false; return; }
                onToggle();
            }}
            onPointerDown={(event) => {
                if (!event.isPrimary || event.button !== 0) return;
                skipClick.current = false;
                drag.current = { id: event.pointerId, x: event.clientX, y: event.clientY, top: top ?? limits().max, moved: false };
                event.currentTarget.setPointerCapture(event.pointerId);
            }}
            onPointerMove={(event) => {
                const current = drag.current;
                if (!current || current.id !== event.pointerId) return;
                if (!current.moved && Math.hypot(event.clientX - current.x, event.clientY - current.y) < 6) return;
                current.moved = true;
                setMoving(true);
                setTop(clamp(current.top + event.clientY - current.y));
            }}
            onPointerUp={finishDrag}
            onPointerCancel={finishDrag}
            onLostPointerCapture={() => { drag.current = null; setMoving(false); }}
            onKeyDown={(event) => {
                if (!['ArrowUp', 'ArrowDown', 'Home', 'End'].includes(event.key)) return;
                event.preventDefault();
                const value = clamp(event.key === 'Home' ? EDGE : event.key === 'End' ? limits().max : (top ?? limits().max) + (event.key === 'ArrowUp' ? -24 : 24));
                setTop(value); remember(value);
            }}>
            {children}
        </button>
    </>;
}
