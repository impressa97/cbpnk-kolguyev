import { ScrollArea, ScrollBar } from '@/components/ui/scroll-area';
import { cn } from '@/lib/utils';
import { useEffect, useRef, type ReactNode } from 'react';

/** Mouse movement before a press on the row counts as a drag rather than a click. */
const DRAG_PX = 6;

/**
 * A row wider than its room, scrollable the way a desktop expects as well as by touch: the
 * scrollbar under it, the mouse wheel turned sideways, and a drag with the mouse. A click that
 * ended a drag does not reach the button under it, so dragging never picks anything.
 */
export function HScroll({ children, className }: { children: ReactNode; className?: string }) {
    const root = useRef<HTMLDivElement>(null);

    useEffect(() => {
        const viewport = root.current?.querySelector<HTMLElement>('[data-slot="scroll-area-viewport"]');
        if (!viewport) {
            return;
        }
        const onWheel = (event: WheelEvent) => {
            if (Math.abs(event.deltaY) <= Math.abs(event.deltaX)) {
                return;
            }
            const max = viewport.scrollWidth - viewport.clientWidth;
            const next = Math.max(0, Math.min(max, viewport.scrollLeft + event.deltaY));
            // At either end the wheel goes back to scrolling the page.
            if (max <= 0 || next === viewport.scrollLeft) {
                return;
            }
            event.preventDefault();
            viewport.scrollLeft = next;
        };
        let start: { x: number; left: number } | null = null;
        let dragged = false;
        const onDown = (event: PointerEvent) => {
            if (event.pointerType === 'mouse' && event.button === 0) {
                start = { x: event.clientX, left: viewport.scrollLeft };
                dragged = false;
            }
        };
        const onMove = (event: PointerEvent) => {
            if (!start) {
                return;
            }
            const dx = event.clientX - start.x;
            if (!dragged && Math.abs(dx) < DRAG_PX) {
                return;
            }
            dragged = true;
            viewport.scrollLeft = start.left - dx;
        };
        const onUp = () => {
            start = null;
        };
        const onClick = (event: MouseEvent) => {
            if (dragged) {
                event.preventDefault();
                event.stopPropagation();
                dragged = false;
            }
        };
        viewport.addEventListener('wheel', onWheel, { passive: false });
        viewport.addEventListener('pointerdown', onDown);
        viewport.addEventListener('click', onClick, true);
        window.addEventListener('pointermove', onMove);
        window.addEventListener('pointerup', onUp);

        return () => {
            viewport.removeEventListener('wheel', onWheel);
            viewport.removeEventListener('pointerdown', onDown);
            viewport.removeEventListener('click', onClick, true);
            window.removeEventListener('pointermove', onMove);
            window.removeEventListener('pointerup', onUp);
        };
    }, []);

    return (
        <ScrollArea ref={root} className={cn('w-full whitespace-nowrap', className)}>
            {children}
            <ScrollBar orientation="horizontal" />
        </ScrollArea>
    );
}
