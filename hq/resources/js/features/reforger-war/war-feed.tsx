import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { ScrollArea } from '@/components/ui/scroll-area';
import type { WarEvent } from '@/lib/schemas/reforger';
import { uiText } from '@/lib/ui-tokens';
import { cn } from '@/lib/utils';
import { Coins, Crosshair, Flag, Flame, Footprints, Radio, Shield, Siren, Skull, Star, Truck, UserPlus, type LucideIcon } from 'lucide-react';

const KIND: Record<WarEvent['kind'], { icon: LucideIcon; tone: string }> = {
    join: { icon: UserPlus, tone: uiText.accent },
    loss: { icon: Skull, tone: uiText.danger },
    move: { icon: Footprints, tone: uiText.subtle },
    supply: { icon: Coins, tone: uiText.credits },
    kill: { icon: Crosshair, tone: uiText.accent },
    vehicle: { icon: Truck, tone: uiText.subtle },
    fire: { icon: Flame, tone: uiText.subtle },
    territory: { icon: Flag, tone: uiText.accent },
    objective: { icon: Star, tone: uiText.credits },
    hq: { icon: Shield, tone: uiText.subtle },
    threat: { icon: Siren, tone: uiText.danger },
};

/**
 * Radio log: everything that happens to your side (men joining, killed, kills, orders and how
 * they went, vehicles, HQ fire and logistics, objectives, HQ detachments, supplies), ground
 * changing hands for everyone, and other sides only when a squad lands.
 */
export function WarFeed({ events }: { events: WarEvent[] }) {
    return (
        <Card className="gap-3">
            <CardHeader>
                <CardTitle className="flex items-center gap-2 text-sm tracking-widest uppercase">
                    <Radio className="size-4" /> Radio log
                </CardTitle>
                <CardDescription>
                    Everything that happens to your side, ground changing hands, and other sides only when a squad lands.
                </CardDescription>
            </CardHeader>
            <CardContent>
                {events.length === 0 ? (
                    <p className="text-sm text-muted-foreground">The log starts when someone enlists.</p>
                ) : (
                    <ScrollArea className="h-80 pr-3">
                        <ul className="flex flex-col gap-2.5">
                            {events.map((event) => {
                                const { icon: Icon, tone } = KIND[event.kind];

                                return (
                                    <li key={event.id} className="flex gap-2">
                                        <Icon className={cn('mt-0.5 size-3.5 shrink-0', tone)} />
                                        <span className="flex flex-col">
                                            <time className="text-xs text-muted-foreground" dateTime={event.at}>
                                                {new Date(event.at).toLocaleString(undefined, {
                                                    month: 'short',
                                                    day: 'numeric',
                                                    hour: '2-digit',
                                                    minute: '2-digit',
                                                })}
                                            </time>
                                            <span className={cn('text-sm', tone)}>{event.text}</span>
                                        </span>
                                    </li>
                                );
                            })}
                        </ul>
                    </ScrollArea>
                )}
            </CardContent>
        </Card>
    );
}
