import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import type { WarCombatMode, WarCommand, WarFormation } from '@/lib/schemas/reforger';
import { cn } from '@/lib/utils';
import { Eye, Footprints, Moon, Siren, SlidersHorizontal, X, type LucideIcon } from 'lucide-react';
import { orderSpec, type SquadOrderKind } from '../lib/orders';
import { warApi, type WarAlert, type WarPace } from '../lib/war-api';
import { gridRef } from '../map-geometry';
import { COMBAT } from '../war-format';
import { ORDER_ICON } from './icons';
import type { Point, Squad } from './types';

const COMBAT_HELP: Record<WarCombatMode, string> = {
    hold: 'Do not open fire, even when fired on.',
    return: 'Only shoot back.',
    free: 'Engage any enemy they see.',
};
const SHOWN_COMBAT: Record<WarCombatMode, string> = { hold: 'Hold', return: 'Return', free: 'Free' };

/** The game's group formations (SCR_EAIGroupFormation) and the group pace; auto leaves them to the AI. */
const FORMATION: Record<WarFormation, string> = { auto: 'Auto', wedge: 'Wedge', line: 'Line', column: 'Column', staggered: 'Staggered' };
const GROUP_PACE: Record<Squad['pace'], string> = { auto: 'Auto', walk: 'Walk', run: 'Run', sprint: 'Sprint' };

/** Hold fire, return fire, fire at will: the squad's standing combat mode. */
export function CombatToggle({ squad }: { squad: Squad }) {
    return (
        <ToggleGroup
            type="single"
            variant="outline"
            size="sm"
            className="h-7"
            value={squad.combat}
            onValueChange={(mode) => mode && mode !== squad.combat && warApi.squad.combat(squad.id, mode as WarCombatMode)}
            aria-label="Combat mode"
        >
            {(Object.keys(COMBAT) as WarCombatMode[]).map((mode) => (
                <Tooltip key={mode}>
                    <TooltipTrigger asChild>
                        <span className="flex">
                            <ToggleGroupItem value={mode} aria-label={COMBAT[mode]}>
                                {SHOWN_COMBAT[mode]}
                            </ToggleGroupItem>
                        </span>
                    </TooltipTrigger>
                    <TooltipContent>
                        {COMBAT[mode]}: {COMBAT_HELP[mode]}
                    </TooltipContent>
                </Tooltip>
            ))}
        </ToggleGroup>
    );
}

const ALERT: Record<Squad['alert'], { label: string; icon: LucideIcon; help: string }> = {
    normal: {
        label: 'Normal',
        icon: Moon,
        help: 'The AI decides. Once your side has seen the enemy within 500 m of the squad, it goes on alert by itself until the sighting is two minutes old.',
    },
    alert: {
        label: 'Alert',
        icon: Eye,
        help: "Expecting contact (the game's vigilant state): they spot the enemy far sooner and are never taken by surprise; they still move at full pace.",
    },
    ready: {
        label: 'Ready',
        icon: Siren,
        help: "Contact is coming (the game's alerted state): sharp eyes as on alert, and the fight's own moves are crouched and careful. For the last stretch before a fight.",
    },
};

/** How ready the squad is for contact: the game's threat state the mod holds the men at. */
export function AlertToggle({ squad }: { squad: Squad }) {
    return (
        <ToggleGroup
            type="single"
            variant="outline"
            size="sm"
            value={squad.alert}
            onValueChange={(level) => level && level !== squad.alert && warApi.squad.alert(squad.id, level as WarAlert)}
            aria-label="Readiness for contact"
        >
            {(Object.keys(ALERT) as Array<Squad['alert']>).map((level) => {
                const { label, icon: Icon, help } = ALERT[level];

                return (
                    <ToggleGroupItem key={level} value={level} aria-label={label} className="p-0">
                        <Tooltip>
                            <TooltipTrigger asChild>
                                <span className="flex size-full items-center gap-1 px-2 text-xs">
                                    <Icon /> <span className="max-sm:sr-only">{label}</span>
                                </span>
                            </TooltipTrigger>
                            <TooltipContent className="max-w-72">
                                {label}: {help}
                            </TooltipContent>
                        </Tooltip>
                    </ToggleGroupItem>
                );
            })}
        </ToggleGroup>
    );
}

/** Formation and pace for the whole squad: set once, so out of the way. */
export function FormationPopover({ squad }: { squad: Squad }) {
    const custom = squad.formation !== 'auto' || squad.pace !== 'auto';

    return (
        <Popover>
            <PopoverTrigger asChild>
                <Button
                    type="button"
                    size="icon-sm"
                    variant={custom ? 'secondary' : 'ghost'}
                    aria-label="Formation and pace"
                    title={`Formation ${FORMATION[squad.formation]} · pace ${GROUP_PACE[squad.pace]}`}
                >
                    <SlidersHorizontal />
                </Button>
            </PopoverTrigger>
            <PopoverContent align="end" className="flex w-60 flex-col gap-3 p-3 text-xs">
                {(
                    [
                        ['Formation', FORMATION, squad.formation, 'formation'],
                        ['Pace', GROUP_PACE, squad.pace, 'pace'],
                    ] as const
                ).map(([label, choices, value, field]) => (
                    <div key={label} className="flex flex-col gap-1">
                        <span className="text-xs text-muted-foreground">{label}</span>
                        <ToggleGroup
                            type="single"
                            variant="outline"
                            size="sm"
                            className="flex w-full flex-wrap"
                            value={value}
                            onValueChange={(v) =>
                                v &&
                                v !== value &&
                                warApi.squad.form(squad.id, field === 'formation' ? { formation: v as WarFormation } : { pace: v as WarPace })
                            }
                        >
                            {Object.entries(choices).map(([id, name]) => (
                                <ToggleGroupItem key={id} value={id} className="flex-1">
                                    {name}
                                </ToggleGroupItem>
                            ))}
                        </ToggleGroup>
                    </div>
                ))}
                <p className="text-muted-foreground">The game&apos;s group settings: auto leaves them to the squad&apos;s AI.</p>
            </PopoverContent>
        </Popover>
    );
}

const CHAIN_STATUS: Record<string, string> = { pending: 'sent', acked: 'under way', queued: 'next' };

/** What the squad is doing and what waits behind it; a waiting order can be taken out. */
export function ChainStrip({ war, chain, onFocus }: { war: WarCommand; chain: WarCommand['orders']; onFocus: (point: Point) => void }) {
    return (
        <ol className="flex flex-wrap items-center gap-1 px-1 text-xs" aria-label="Order chain">
            {chain.map((order, i) => {
                const kind = order.kind as SquadOrderKind;
                const Icon = ORDER_ICON[kind] ?? Footprints;
                const vehicle = order.vehicle_id !== null ? war.vehicles.find((v) => v.id === order.vehicle_id) : undefined;
                const men = war.soldiers.filter((s) => s.squad_id === order.squad_id && s.location === 'field');
                const inside = men.filter((s) => s.in_vehicle).length;
                // The first in line waits only while the squad finishes getting in (or out).
                const waiting = i === 0 && order.status === 'queued';
                const status =
                    kind === 'mount' && order.status !== 'queued'
                        ? `${inside}/${men.length} in`
                        : waiting
                          ? inside > 0 && inside < men.length
                              ? `waits: ${inside}/${men.length} aboard`
                              : 'next'
                          : (CHAIN_STATUS[order.status] ?? order.status);

                return (
                    <li key={order.id} className="flex items-center gap-1">
                        {i > 0 ? <span className="text-muted-foreground">→</span> : null}
                        <Badge
                            variant={order.status === 'queued' ? 'outline' : 'secondary'}
                            className={cn('gap-0 p-0', order.status === 'queued' && 'border-dashed')}
                        >
                            <Button type="button" size="xs" variant="ghost" className="h-5" onClick={() => onFocus({ x: order.x, z: order.z })}>
                                <Icon />
                                {orderSpec(war, kind).label}
                                {vehicle
                                    ? ` ${vehicle.model}`
                                    : kind === 'rest' || kind === 'dismount' || kind === 'halt'
                                      ? ''
                                      : ` ${gridRef(order.x, order.z)}`}
                                <span className="text-muted-foreground">· {status}</span>
                            </Button>
                            {order.status === 'queued' ? (
                                <Button
                                    type="button"
                                    size="icon-xs"
                                    variant="ghost"
                                    className="size-5"
                                    aria-label="Take it out of the chain"
                                    onClick={() => warApi.cancelOrder(order.id)}
                                >
                                    <X />
                                </Button>
                            ) : null}
                        </Badge>
                    </li>
                );
            })}
        </ol>
    );
}
