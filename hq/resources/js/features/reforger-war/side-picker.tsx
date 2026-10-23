import type { WarCommand, WarSideId } from '@/lib/schemas/reforger';
import { uiInteractive } from '@/lib/ui-tokens';
import { cn } from '@/lib/utils';
import { warApi } from './lib/war-api';
import { gridRef } from './map-geometry';
import { SIDE_BADGE, SIDE_DOT } from './side-style';

const SIDE_ROLE: Record<WarSideId, string> = {
    us: 'BLUFOR',
    ussr: 'OPFOR',
    fia: 'INDFOR',
};

/** Deploy-screen style faction choice. A side sticks until the week ends. */
export function SidePicker({ war }: { war: WarCommand }) {
    const weekOpen = war.session.open;
    const enlisted = war.enlisted_side;

    return (
        <section className="flex flex-col gap-3" aria-labelledby="war-side-title">
            <div className="flex flex-col gap-1">
                <h2 id="war-side-title" className="text-sm font-semibold tracking-widest uppercase">
                    Choose your side
                </h2>
                <p className="text-sm text-muted-foreground">
                    Your first squad of {war.squad_strength} riflemen musters at the side&apos;s home, where you also respawn in the client. The
                    choice holds for the whole week.
                </p>
            </div>
            <div className="grid gap-3 md:grid-cols-3">
                {war.sides.map((side) => {
                    const chosen = enlisted === side.id;
                    const locked = weekOpen && enlisted !== null && !chosen;
                    const home = war.places.find((place) => place.side === side.id && place.kind === 'home');

                    return (
                        <button
                            key={side.id}
                            type="button"
                            disabled={locked}
                            onClick={() => warApi.enlist(side.id, { inline: true })}
                            className={cn(
                                'group relative flex flex-col gap-3 overflow-hidden rounded-lg border bg-card p-4 text-left transition-colors',
                                'hover:border-foreground/30 disabled:cursor-not-allowed disabled:opacity-50',
                                chosen && uiInteractive.selectedRing,
                            )}
                        >
                            <span className={cn('absolute inset-x-0 top-0 h-1', SIDE_DOT[side.id])} aria-hidden />
                            <span className="flex items-center justify-between gap-2">
                                <span className={cn('rounded-md border px-2 py-0.5 text-sm font-bold tracking-wide uppercase', SIDE_BADGE[side.id])}>
                                    {side.name}
                                </span>
                                <span className="text-xs font-medium tracking-widest text-muted-foreground uppercase">{SIDE_ROLE[side.id]}</span>
                            </span>
                            <span className="text-sm text-muted-foreground">{side.detail}</span>
                            <dl className="grid grid-cols-3 gap-2 border-t pt-3 text-xs">
                                <Stat label="Home" value={home?.name ?? '—'} />
                                <Stat label="Grid" value={gridRef(side.home.x, side.home.z)} mono />
                                <Stat label="Squads" value={`${side.squads} · ${side.commanders} cmd`} />
                            </dl>
                            <span
                                className={cn(
                                    'mt-auto rounded-md py-1.5 text-center text-sm font-semibold tracking-wide uppercase',
                                    chosen ? 'bg-map-success/15 text-map-success' : 'bg-muted text-foreground group-hover:bg-foreground/10',
                                )}
                            >
                                {chosen ? 'Enlisted' : locked ? 'Locked this week' : 'Enlist'}
                            </span>
                        </button>
                    );
                })}
            </div>
        </section>
    );
}

function Stat({ label, value, mono = false }: { label: string; value: string; mono?: boolean }) {
    return (
        <div className="flex flex-col gap-0.5">
            <dt className="text-xs tracking-wider text-muted-foreground uppercase">{label}</dt>
            <dd className={cn('truncate', mono && 'font-mono')}>{value}</dd>
        </div>
    );
}
