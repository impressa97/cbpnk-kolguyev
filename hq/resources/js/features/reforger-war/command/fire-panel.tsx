import { Button } from '@/components/ui/button';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { useIsMobile } from '@/hooks/use-mobile';
import { uiText } from '@/lib/ui-tokens';
import { cn } from '@/lib/utils';
import { Flame } from 'lucide-react';
import { fireBlocked } from '../lib/orders';
import { useWarSending, warApi } from '../lib/war-api';
import { gridRef } from '../map-geometry';
import { countdown } from '../war-format';
import { FIRE_ICON } from './icons';
import type { CommandProps } from './types';

/**
 * HQ's fire on call, no squad needed: pick a mission, tap the map where it lands, call it. Each
 * says what it costs, when the battery is ready and why HQ would refuse it there (danger close
 * to your own men). Missions on their way are listed with a cancel while they can be.
 */
export function FirePanel({ war, draft, onFire, onCallFire, onFocus }: CommandProps) {
    const sending = useWarSending();
    const phone = useIsMobile();
    const offer = war.support_catalog.find((o) => o.id === draft.fire) ?? null;
    const at = draft.marks.a;
    const why = offer ? fireBlocked(war, offer, at) : null;
    const live = war.support.filter((r) => r.kind !== 'resupply' && (r.status === 'requested' || r.status === 'waiting'));

    return (
        <div className="flex flex-col gap-2">
            <ToggleGroup
                type="single"
                variant="outline"
                spacing={1}
                className={cn('grid w-full', 'grid-cols-4')}
                value={draft.fire ?? ''}
                onValueChange={(id) => onFire(id || null)}
                aria-label="Fire missions"
            >
                {war.support_catalog.map((o) => {
                    const Icon = FIRE_ICON[o.id] ?? Flame;
                    const blocked = fireBlocked(war, o, null);
                    const cooling = o.ready_at !== null && new Date(o.ready_at).getTime() > Date.now();

                    return (
                        <ToggleGroupItem
                            key={o.id}
                            value={o.id}
                            aria-label={o.name}
                            title={blocked ?? `${o.detail}. Lands ${Math.round(o.delay / 60) || 1} min after the call.`}
                            className={cn(
                                'h-auto justify-start gap-2 px-2 py-1.5 text-left text-xs',
                                'flex-col gap-0.5 px-1 text-center',
                                blocked && 'opacity-50',
                            )}
                        >
                            <Icon />
                            <span className="flex min-w-0 flex-1 flex-col leading-tight">
                                <span className="truncate">{o.name.split(' ')[0]}</span>
                                <span className="font-mono text-map-warning">
                                    {o.cost}
                                    {cooling ? ` · ${countdown((new Date(o.ready_at!).getTime() - Date.now()) / 1000)}` : ''}
                                </span>
                            </span>
                        </ToggleGroupItem>
                    );
                })}
            </ToggleGroup>
            <div className="flex flex-wrap items-center gap-1.5">
                <p className="min-w-40 flex-1 px-1 text-xs leading-snug">
                    {!offer ? (
                        <span className="text-muted-foreground">Pick a fire mission, then tap the map where it should land.</span>
                    ) : why ? (
                        <span className={uiText.danger}>
                            {offer.name}: {why}
                        </span>
                    ) : at ? (
                        <span>
                            <span className="font-medium">{offer.name}</span>
                            <span className="text-muted-foreground">
                                {' '}
                                on {gridRef(at.x, at.z)} · lands in {Math.round(offer.delay / 60) || 1} min · {offer.cost} supplies
                            </span>
                        </span>
                    ) : (
                        <span className="text-muted-foreground">
                            {offer.name}: {phone ? 'tap' : 'click'} the map where it should land.
                        </span>
                    )}
                </p>
                <Button
                    type="button"
                    size="sm"
                    className="tracking-wide uppercase"
                    disabled={!offer || !at || why !== null || sending}
                    onClick={onCallFire}
                    aria-label={offer ? `Call ${offer.name}` : 'Call fire'}
                >
                    <Flame /> Call fire
                </Button>
            </div>
            {live.length > 0 ? (
                <ul className="flex flex-col gap-0.5 text-xs">
                    {live.map((r) => (
                        <li key={r.id} className="flex items-center gap-1.5">
                            <Button
                                type="button"
                                size="xs"
                                variant="link"
                                className="min-w-0 flex-1 justify-start truncate"
                                onClick={() => r.x !== null && r.z !== null && onFocus({ x: r.x, z: r.z })}
                            >
                                {r.name} · lands in {countdown(Math.max(0, (new Date(r.ready_at).getTime() - Date.now()) / 1000))}
                            </Button>
                            {new Date(r.ready_at).getTime() > Date.now() ? (
                                <Button type="button" size="xs" variant="ghost" onClick={() => warApi.cancelSupport(r.id)}>
                                    Cancel
                                </Button>
                            ) : null}
                        </li>
                    ))}
                </ul>
            ) : null}
        </div>
    );
}
