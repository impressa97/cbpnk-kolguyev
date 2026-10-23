import { Button } from '@/components/ui/button';
import { Item } from '@/components/ui/item';
import type { WarCommand } from '@/lib/schemas/reforger';
import { cn } from '@/lib/utils';
import { HeartPulse, Hospital, House, Package, Truck } from 'lucide-react';
import { logistics, runFor } from '../lib/orders';
import { warApi } from '../lib/war-api';
import { formatDistance } from '../map-geometry';
import { ReinforcePopover } from '../soldier-dialogs';
import type { Squad } from './types';

/**
 * The picked squad's care, under the map with the rest of squad management: where it rearms
 * and sees to its wounded, HQ's truck and ambulance, reinforcements.
 */
export function SquadCare({ war, squad, onHome }: { war: WarCommand; squad: Squad; onHome: () => void }) {
    return <LogisticsStrip war={war} squad={squad} need={logistics(war, squad)} onHome={onHome} />;
}

/**
 * What the squad needs and where it gets it: at home the hospital takes the wounded and the
 * arsenal rearms everyone for free; beside one of your trucks it rearms for supplies. Away from
 * both it says how far home is. Reinforcements are recruited here and walk out from home.
 */
function LogisticsStrip({ war, squad, need, onHome }: { war: WarCommand; squad: Squad; need: ReturnType<typeof logistics>; onHome: () => void }) {
    const act = (action: 'rearm' | 'hospital') => warApi.squad.logistics(squad.id, action);
    const field = squad.condition.field > 0;

    return (
        <Item variant="muted" size="sm" className="gap-1.5 px-2 py-1.5 text-xs">
            {field && need.home ? (
                <>
                    <span className="flex items-center gap-1 font-medium">
                        <House className="size-3.5" /> At home
                    </span>
                    <Button
                        type="button"
                        size="xs"
                        variant="outline"
                        disabled={need.wounded === 0}
                        title="Hand the wounded to the hospital: they recover in the reserve, then go back"
                        onClick={() => act('hospital')}
                    >
                        <Hospital /> Hospital{need.wounded > 0 ? ` · ${need.wounded}` : ''}
                    </Button>
                    <Button
                        type="button"
                        size="xs"
                        variant="outline"
                        disabled={need.rearming}
                        title={`Everyone to ${war.logistics.magazines} spare magazines, free at home`}
                        onClick={() => act('rearm')}
                    >
                        <Package /> {need.rearming ? 'Rearming' : 'Rearm'}
                    </Button>
                </>
            ) : field && need.truckInReach && need.truck ? (
                <>
                    <span className="flex items-center gap-1 font-medium">
                        <Package className="size-3.5" /> {need.truck.model} {Math.round(need.truck.metres)} m
                    </span>
                    <Button
                        type="button"
                        size="xs"
                        variant="outline"
                        disabled={need.rearming || (war.economy.supplies ?? 0) < war.logistics.truck_cost}
                        title={`Everyone to ${war.logistics.magazines} spare magazines from the truck`}
                        onClick={() => act('rearm')}
                    >
                        <Package /> {need.rearming ? 'Rearming' : `Rearm · ${war.logistics.truck_cost}`}
                    </Button>
                </>
            ) : field ? (
                <span className={cn('flex items-center gap-1', need.wounded > 0 || need.lowAmmo > 0 ? 'text-map-warning' : 'text-muted-foreground')}>
                    {need.wounded > 0 ? (
                        <>
                            <HeartPulse className="size-3.5" /> {need.wounded} wounded ·
                        </>
                    ) : null}
                    {need.lowAmmo > 0 ? (
                        <>
                            <Package className="size-3.5" /> {need.lowAmmo} low on ammo ·
                        </>
                    ) : null}
                    home {formatDistance(need.homeMetres)}
                    {need.truck ? ` · ${need.truck.model} ${formatDistance(need.truck.metres)}` : ''}
                </span>
            ) : (
                <span className="text-muted-foreground">Nobody on the island. Recruits appear at home.</span>
            )}
            {field && !need.home && (need.wounded > 0 || need.lowAmmo > 0) ? (
                <Button type="button" size="xs" variant="ghost" onClick={onHome}>
                    <House /> Go home
                </Button>
            ) : null}
            <span className="ml-auto">
                <ReinforcePopover war={war} squad={squad} />
            </span>
        </Item>
    );
}

const RUN_WORD: Record<string, string> = { supply: 'HQ ammo truck', ambulance: 'HQ ambulance', transport: 'HQ lift' };

/**
 * Care on the map, one row: HQ's ammo truck and ambulance for the squad where it stands; the
 * run under way, if any, in their place. A run is a full round: the vehicle drives out, the squad
 * walks to the truck and carries on with its orders, the wounded walk to the ambulance and are
 * driven home, then the vehicle drives back.
 */
export function CareRow({ war, squad, need }: { war: WarCommand; squad: Squad; need: ReturnType<typeof logistics> }) {
    const run = runFor(war, squad.id);
    if (squad.condition.field === 0) {
        return null;
    }
    const supplies = war.economy.supplies ?? 0;
    const ask = (kind: 'supply' | 'ambulance') => warApi.run({ kind, squad_id: squad.id });
    if (run) {
        return (
            <div className="flex items-center gap-1.5 px-1 text-xs">
                <span className="flex min-w-0 items-center gap-1 text-map-accent">
                    <Truck className="size-3.5 shrink-0" />{' '}
                    <span className="truncate">
                        {RUN_WORD[run.kind]}: {run.note}
                    </span>
                </span>
            </div>
        );
    }

    return (
        <div className="flex flex-wrap items-center gap-1 px-1 text-xs">
            {need.home ? (
                <span className="text-muted-foreground max-sm:hidden">At home: hospital and arsenal below the map.</span>
            ) : (
                <>
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        className={cn(need.lowAmmo > 0 && 'border-map-warning text-map-warning')}
                        disabled={supplies < (war.run_costs.supply ?? 0)}
                        title="HQ drives an ammunition truck out; the squad walks over, rearms, and carries on"
                        onClick={() => ask('supply')}
                    >
                        <Package /> Ammo truck · {war.run_costs.supply}
                    </Button>
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        className={cn(need.walkingWounded > 0 && 'border-map-warning text-map-warning')}
                        disabled={need.walkingWounded === 0 || supplies < (war.run_costs.ambulance ?? 0)}
                        title={
                            need.walkingWounded === 0
                                ? need.wounded > 0
                                    ? 'The wounded are down: a medic first, then they can walk to an ambulance'
                                    : 'Nobody is wounded'
                                : 'HQ drives an ambulance out; the wounded walk to it and are driven to the hospital'
                        }
                        onClick={() => ask('ambulance')}
                    >
                        <Hospital /> Ambulance{need.walkingWounded > 0 ? ` (${need.walkingWounded})` : ''} · {war.run_costs.ambulance}
                    </Button>
                </>
            )}
        </div>
    );
}
