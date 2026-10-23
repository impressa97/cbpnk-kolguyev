import { Badge } from '@/components/ui/badge';
import type { WarCommand } from '@/lib/schemas/reforger';
import { cn } from '@/lib/utils';
import { Clock, CloudFog, CloudRain, Coins, Moon, Sun, Wind } from 'lucide-react';
import { SIDE_BADGE } from './side-style';
import { countdown } from './war-format';

/**
 * One line over the map: your side, supplies and what comes in, the week's clock, and the
 * island's time and weather. Wraps on a phone.
 */
export function HqHeader({ war }: { war: WarCommand }) {
    const side = war.sides.find((entry) => entry.id === war.enlisted_side) ?? null;
    const supplies = war.economy.supplies;
    const world = war.world;
    const night = world !== null && (world.hour < world.sunrise || world.hour >= world.sunset);

    return (
        <div className="flex flex-wrap items-center gap-x-4 gap-y-1 rounded-lg border bg-card px-3 py-1.5 text-xs">
            {side ? (
                <Badge variant="outline" className={cn('font-semibold tracking-wide uppercase', SIDE_BADGE[side.id])}>
                    {side.name}
                </Badge>
            ) : (
                <span className="text-muted-foreground">Not enlisted</span>
            )}
            {supplies !== null ? (
                <span className="flex items-center gap-1" title={`Up to ${war.economy.supply_cap}`}>
                    <Coins className="size-3.5 text-map-warning" />
                    <span className="font-mono font-semibold text-map-warning tabular-nums">{supplies}</span>
                    <span className="text-muted-foreground">
                        / {war.economy.supply_cap} · +{war.income.base}/h{war.income.territory > 0 ? ` +${war.income.territory}/h ground` : ''}
                    </span>
                </span>
            ) : null}
            <span className="flex items-center gap-1" title="Until the week ends">
                <Clock className="size-3.5 text-muted-foreground" />
                <span className="font-mono tabular-nums">
                    {war.session.open ? countdown(war.session.seconds_left) : war.session.ends_at ? 'Over' : 'Not started'}
                </span>
            </span>
            {world ? (
                <span
                    className="flex flex-wrap items-center gap-x-2 text-muted-foreground"
                    title={`Sun ${clock(world.sunrise)}–${clock(world.sunset)}`}
                >
                    <span className="flex items-center gap-1 text-foreground">
                        {night ? <Moon className="size-3.5" /> : <Sun className="size-3.5" />}
                        <span className="font-mono tabular-nums">{clock(world.hour)}</span>
                    </span>
                    {world.weather}
                    <span className="flex items-center gap-0.5">
                        <CloudRain className="size-3" /> {world.rain}%
                    </span>
                    <span className="flex items-center gap-0.5">
                        <CloudFog className="size-3" /> {world.fog}%
                    </span>
                    <span className="flex items-center gap-0.5">
                        <Wind className="size-3" /> {world.wind.toFixed(0)} m/s
                    </span>
                </span>
            ) : (
                <span className="text-muted-foreground">No report from the island yet</span>
            )}
        </div>
    );
}

function clock(hour: number): string {
    const h = Math.floor(hour) % 24;
    const m = Math.floor((hour - Math.floor(hour)) * 60);

    return `${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}`;
}
