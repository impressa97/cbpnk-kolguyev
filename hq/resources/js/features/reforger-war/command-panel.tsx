import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import type { WarCommand } from '@/lib/schemas/reforger';
import { cn } from '@/lib/utils';
import { ChevronDown, ChevronUp, Crosshair, Flame, HeartPulse, Package, Siren, SlidersHorizontal, Truck, Users } from 'lucide-react';
import { useState } from 'react';
import { CareRow } from './command/care';
import { FirePanel } from './command/fire-panel';
import { OrderRow } from './command/order-row';
import { Palette } from './command/palette';
import { SoldierCard } from './command/soldier-card';
import { AlertToggle, ChainStrip, CombatToggle, FormationPopover } from './command/squad-settings';
import type { CommandProps, Squad } from './command/types';
import { VehicleStrip } from './command/vehicles';
import { HScroll } from './h-scroll';
import { chainNow, logistics } from './lib/orders';
import { COMBAT, latestOrder, squadState, STATE_TONE } from './war-format';

export { SquadCare } from './command/care';
export type { Draft } from './command/types';
export { ServiceButton } from './command/vehicles';

const DOT: Record<keyof typeof STATE_TONE, string> = {
    idle: 'bg-muted-foreground/50',
    busy: 'bg-map-warning',
    good: 'bg-map-success',
    bad: 'bg-destructive',
    hold: 'bg-map-accent',
};

/**
 * The one place the war is run from, in two modes. Orders: your squads (a tap picks one and
 * flies the map there; unfold one for its men), then the picked squad's palette and send, with
 * its chain, logistics and settings a tap away. HQ fire: the fire missions on call, aimed on the
 * map, no squad needed. The map only points (a click marks); only the panel's buttons send
 * anything, so nothing goes out by accident. On a phone it sits over the map's bottom
 * edge, as small as it can be.
 */
export function CommandPanel(props: CommandProps) {
    const { war, squads, selected, soldier, draft } = props;
    const [open, setOpen] = useState(true);

    if (squads.length === 0) {
        return null;
    }
    const modes = (
        <ToggleGroup
            type="single"
            variant="outline"
            size="sm"
            className="shrink-0"
            value={draft.hq ? 'hq' : 'orders'}
            onValueChange={(v) => v && props.onHq(v === 'hq')}
            aria-label="What the map does"
        >
            <ToggleGroupItem value="orders" aria-label="Squad orders">
                <Users />
            </ToggleGroupItem>
            <ToggleGroupItem value="hq" aria-label="HQ fire">
                <Flame />
            </ToggleGroupItem>
        </ToggleGroup>
    );
    const body = draft.hq ? (
        <FirePanel {...props} />
    ) : selected ? (
        soldier ? (
            <SoldierCard war={war} squads={squads} squad={selected} soldier={soldier} onClose={props.onLeaveSoldier} />
        ) : (
            <SquadCommand {...props} squad={selected} />
        )
    ) : (
        <p className="px-1 text-xs text-muted-foreground">Pick a squad.</p>
    );

    // Squads with men in them, and the one picked even if it is empty.
    const shown = squads.filter((squad) => squad.strength > 0 || squad.id === selected?.id);

    return (
        <Card className="pointer-events-auto gap-2 bg-card/95 p-2 shadow-lg backdrop-blur-sm" data-testid="command-panel">
            <div className="flex items-center gap-1.5">
                {modes}
                {draft.hq ? (
                    <span className="min-w-0 flex-1 px-1 text-xs font-medium">HQ fire on call</span>
                ) : (
                    <HScroll className="min-w-0 flex-1">
                        <ToggleGroup
                            type="single"
                            size="sm"
                            spacing={1}
                            className="w-max pb-2"
                            value={selected ? String(selected.id) : ''}
                            aria-label="Your squads"
                        >
                            {shown.map((squad) => (
                                <SquadChip key={squad.id} war={war} squad={squad} onClick={() => props.onSelectSquad(squad.id)} />
                            ))}
                        </ToggleGroup>
                    </HScroll>
                )}
                <Button
                    type="button"
                    size="icon-sm"
                    variant="ghost"
                    className="shrink-0"
                    aria-label={open ? 'Fold the console' : 'Open the console'}
                    onClick={() => setOpen(!open)}
                >
                    {open ? <ChevronDown /> : <ChevronUp />}
                </Button>
            </div>
            {open ? body : null}
        </Card>
    );
}

function SquadChip({ war, squad, onClick }: { war: WarCommand; squad: Squad; onClick: () => void }) {
    const state = squadState(squad, latestOrder(war.orders, squad.id));
    const need = squad.condition.field > 0 ? logistics(war, squad) : null;

    return (
        <ToggleGroupItem value={String(squad.id)} onClick={onClick} aria-label={`${squad.label}: ${state.label}`} className="shrink-0 text-xs">
            <span className={cn('size-2 rounded-full', DOT[state.tone])} />
            {squad.label}
            <span className="text-muted-foreground tabular-nums">{squad.condition.field}</span>
            {need && need.aboard > 0 ? (
                <span className="flex items-center gap-0.5 text-map-accent" title={`${need.aboard} aboard`}>
                    <Truck className="size-3" />
                    {need.aboard}
                </span>
            ) : null}
            {need && need.underFire ? <Siren className="size-3 animate-pulse text-destructive" aria-label="under fire" /> : null}
            {need && need.wounded > 0 ? <HeartPulse className="size-3 text-destructive" aria-label="wounded" /> : null}
            {need && need.lowAmmo > 0 ? <Package className="size-3 text-map-warning" aria-label="low on ammo" /> : null}
        </ToggleGroupItem>
    );
}

/**
 * The picked squad: what it is doing, the palette and the send. Its combat mode, formation,
 * logistics and chain are in view on a desktop; on a phone they fold behind one button.
 */
function SquadCommand({ war, squad, draft, onKind, onOptions, onClearMarks, onFocus, onSend }: CommandProps & { squad: Squad }) {
    const [more, setMore] = useState(false);
    const latest = latestOrder(war.orders, squad.id);
    const state = squadState(squad, latest);
    const need = logistics(war, squad);
    const chain = chainNow(war, squad.id);
    const waiting = chain.filter((o) => o.status === 'queued').length;
    const open = latest && (latest.status === 'pending' || latest.status === 'acked');
    // The game holds fire on an operation and frees it to suppress; anything else is the AI's own call.
    const override =
        squad.combat_actual && squad.combat_actual !== squad.combat
            ? open && war.operations.some((op) => op.id === latest.kind)
                ? 'Holding fire for the operation'
                : open && latest.kind === 'suppress'
                  ? 'Fire at will to suppress'
                  : `The AI: ${COMBAT[squad.combat_actual]}`
            : null;
    const needs = need.wounded > 0 || need.lowAmmo > 0;
    const details = (
        <>
            <div className="flex flex-wrap items-center gap-1.5 px-1">
                <CombatToggle squad={squad} />
                <FormationPopover squad={squad} />
                {override ? <span className="text-xs text-map-accent">{override}</span> : null}
            </div>
            {chain.length > 0 ? <ChainStrip war={war} chain={chain} onFocus={onFocus} /> : null}
        </>
    );

    return (
        <div className="flex flex-col gap-2">
            <div className="flex flex-wrap items-center gap-1.5 px-1 text-xs">
                <Button
                    type="button"
                    size="xs"
                    variant="ghost"
                    className="font-semibold tracking-wide uppercase"
                    aria-label={`Show ${squad.label} on the map`}
                    onClick={() => onFocus({ x: squad.x, z: squad.z })}
                >
                    <Crosshair /> {squad.label}
                </Button>
                <Badge variant="outline" className={cn('px-1.5 py-0 text-xs', STATE_TONE[state.tone])} title="What the game has the squad doing">
                    {state.label}
                </Badge>
                {waiting > 0 ? <span className="text-muted-foreground">+{waiting} next</span> : null}
                <AlertToggle squad={squad} />
                <Button
                    type="button"
                    size="sm"
                    variant={more ? 'secondary' : 'ghost'}
                    className={cn('ml-auto h-7 px-2 text-xs', needs && !more && 'text-map-warning')}
                    aria-expanded={more}
                    onClick={() => setMore(!more)}
                >
                    <SlidersHorizontal /> <span className="max-sm:sr-only">Setup</span>
                </Button>
            </div>
            {latest?.note && open ? <p className="truncate px-1 text-xs text-muted-foreground">{latest.note}</p> : null}
            <CareRow war={war} squad={squad} need={need} />
            <VehicleStrip war={war} squad={squad} />
            {more ? details : null}

            <Palette war={war} squad={squad} draft={draft} onKind={onKind} />

            <OrderRow
                war={war}
                squad={squad}
                draft={draft}
                chained={chain.length > 0}
                onOptions={onOptions}
                onClearMarks={onClearMarks}
                onSend={onSend}
            />
        </div>
    );
}
