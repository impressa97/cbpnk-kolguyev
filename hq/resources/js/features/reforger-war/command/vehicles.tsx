import { Button } from '@/components/ui/button';
import { Item } from '@/components/ui/item';
import type { WarCommand } from '@/lib/schemas/reforger';
import { cn } from '@/lib/utils';
import { LogOut, Truck, Wrench } from 'lucide-react';
import { boardable, serviceAt } from '../lib/orders';
import { warApi } from '../lib/war-api';
import type { Squad } from './types';

const CREW_SEATS = ['driver', 'gunner', 'commander'];

/** Refuel and repair a vehicle where it can be: at home free, beside a truck for supplies. */
export function ServiceButton({ war, vehicle, small = true }: { war: WarCommand; vehicle: WarCommand['vehicles'][number]; small?: boolean }) {
    const at = serviceAt(war, vehicle);
    const worn = vehicle.health < 100 || vehicle.fuel < 100;
    const why = at.busy
        ? 'Being serviced'
        : at.where === null
          ? 'Drive it home, or next to another of your trucks'
          : !worn
            ? 'Full tank, no damage'
            : at.cost > (war.economy.supplies ?? 0)
              ? 'Not enough supplies'
              : null;

    return (
        <Button
            type="button"
            size={small ? 'xs' : 'sm'}
            variant="outline"
            disabled={why !== null}
            title={why ?? (at.where === 'home' ? 'Refuel and repair at the depot, free' : `Refuel and repair from the truck, ${at.cost} supplies`)}
            onClick={() => warApi.serviceVehicle(vehicle.id)}
        >
            <Wrench /> {at.busy ? 'Servicing' : `Refuel & repair${at.cost > 0 ? ` · ${at.cost}` : ''}`}
        </Button>
    );
}

/**
 * The squad in a vehicle: who crews it (driver, gunner) and who rides in the back. The riders
 * can be unloaded as a squad of their own (they take the first empty squad and get out there),
 * so the crew keeps the vehicle and its gun; or everyone gets out.
 */
export function VehicleStrip({ war, squad }: { war: WarCommand; squad: Squad }) {
    const aboard = war.soldiers.filter((s) => s.squad_id === squad.id && s.location === 'field' && s.in_vehicle);
    if (aboard.length === 0) {
        return null;
    }
    const crew = aboard.filter((s) => CREW_SEATS.includes(s.seat ?? ''));
    const riders = aboard.filter((s) => !CREW_SEATS.includes(s.seat ?? ''));
    const vehicle = boardable(war, squad)[0];
    const free = war.squads.some((s) => s.mine && !s.managed && s.strength === 0);

    return (
        <Item variant="outline" size="sm" className="flex-col items-stretch gap-1 px-2 py-1.5 text-xs">
            <span className="flex flex-wrap items-center gap-x-1.5 gap-y-0.5">
                <Truck className="size-3.5 text-map-accent" />
                <span className="font-medium">{vehicle && vehicle.metres < 60 ? vehicle.model : 'In a vehicle'}</span>
                {vehicle && vehicle.metres < 60 ? (
                    <span className={cn('text-muted-foreground', (vehicle.health < 50 || vehicle.fuel < 25) && 'text-map-warning')}>
                        hull {vehicle.health}% · fuel {vehicle.fuel}%
                    </span>
                ) : null}
                {crew.map((s) => (
                    <span key={s.id}>
                        · {s.name} <span className="text-muted-foreground">{s.seat}</span>
                    </span>
                ))}
                {riders.length > 0 ? (
                    <span>
                        · {riders.length} riding <span className="text-muted-foreground">({riders.map((s) => s.name).join(', ')})</span>
                    </span>
                ) : null}
            </span>
            <span className="flex flex-wrap gap-1.5">
                {crew.length > 0 && riders.length > 0 ? (
                    <Button
                        type="button"
                        size="xs"
                        variant="outline"
                        disabled={!free}
                        title={
                            free ? 'The riders become a squad of their own and get out; the crew keeps the vehicle' : 'No empty squad to put them in'
                        }
                        onClick={() => warApi.squad.split(squad.id, { part: 'passengers', unload: true })}
                    >
                        <LogOut /> Unload {riders.length} as a new squad
                    </Button>
                ) : null}
                <Button
                    type="button"
                    size="xs"
                    variant="outline"
                    title="Order: everyone of the squad out"
                    onClick={() => warApi.order({ squad_id: squad.id, kind: 'dismount', x: squad.x, z: squad.z })}
                >
                    <LogOut /> Everyone out
                </Button>
                {vehicle && vehicle.metres < 60 ? <ServiceButton war={war} vehicle={vehicle} /> : null}
            </span>
        </Item>
    );
}
