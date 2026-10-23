import { Button } from '@/components/ui/button';
import { NativeSelect, NativeSelectOption } from '@/components/ui/native-select';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import type { WarCommand, WarSoldierRow } from '@/lib/schemas/reforger';
import { ArrowRightLeft, HeartPulse, UserRound, X } from 'lucide-react';
import { useState } from 'react';
import { useWarSending, warApi, type WarPace, type WarStance } from '../lib/war-api';
import { soldierDoing, treatmentFor } from '../war-format';
import type { Squad } from './types';

const STANCE: Array<{ id: WarSoldierRow['stance_order']; label: string }> = [
    { id: 'auto', label: 'Auto' },
    { id: 'stand', label: 'Stand' },
    { id: 'crouch', label: 'Crouch' },
    { id: 'prone', label: 'Prone' },
];
const PACE: Array<{ id: WarSoldierRow['pace_order']; label: string }> = [
    { id: 'auto', label: 'Auto' },
    { id: 'walk', label: 'Walk' },
    { id: 'run', label: 'Run' },
    { id: 'sprint', label: 'Sprint' },
];

/**
 * What a squad leader can tell one man, as the game has it: his stance and pace (the game's
 * per-soldier AI settings), the squad's medic to treat him (the heal command), or another of
 * your squads to join (group membership). Shows what his AI is doing right now.
 */
export function SoldierCard({
    war,
    squads,
    squad,
    soldier,
    onClose,
}: {
    war: WarCommand;
    squads: Squad[];
    squad: Squad;
    soldier: WarSoldierRow;
    onClose: () => void;
}) {
    const sending = useWarSending();
    const medic = war.soldiers.some(
        (s) => s.squad_id === squad.id && s.location === 'field' && s.role.includes('medic') && !s.down && s.id !== soldier.id,
    );
    const treating = treatmentFor(war.orders, soldier.id);
    const hurt = soldier.health < 100 || soldier.bleeding || soldier.down;
    const others = squads.filter((s) => s.id !== squad.id);
    const [picked, setTo] = useState<number | null>(null);
    const to = others.some((s) => s.id === picked) ? picked : (others[0]?.id ?? null);
    const posture = (body: { stance?: WarStance; pace?: WarPace }) => warApi.soldier.posture(soldier.id, body);
    const heal = () => warApi.order({ squad_id: squad.id, kind: 'heal', soldier_id: soldier.id, x: squad.x, z: squad.z });

    return (
        <div className="flex flex-col gap-2">
            <div className="flex items-center gap-2 px-1 text-sm">
                <UserRound className="size-4 shrink-0 text-muted-foreground" />
                <span className="truncate font-medium">{soldier.name}</span>
                <span className="truncate text-xs text-muted-foreground">
                    {soldier.role_name} · {squad.label} · {soldier.health}%{soldier.bleeding ? ' · bleeding' : ''}
                    {soldier.down ? ' · down' : ''}
                </span>
                <Button
                    type="button"
                    size="icon-sm"
                    variant="ghost"
                    className="ml-auto shrink-0"
                    aria-label="Back to the squad's orders"
                    onClick={onClose}
                >
                    <X />
                </Button>
            </div>
            <p className="px-1 text-xs text-muted-foreground">In the game: {soldierDoing(soldier) || 'no report yet'}</p>
            <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                {(
                    [
                        ['Stance', STANCE, soldier.stance_order, (v: string) => posture({ stance: v as WarStance })],
                        ['Pace', PACE, soldier.pace_order, (v: string) => posture({ pace: v as WarPace })],
                    ] as const
                ).map(([label, choices, value, set]) => (
                    <div key={label} className="flex flex-col gap-0.5">
                        <span className="px-0.5 text-xs text-muted-foreground">{label}</span>
                        <ToggleGroup
                            type="single"
                            variant="outline"
                            size="sm"
                            className="w-full"
                            value={value}
                            onValueChange={(v) => v && v !== value && set(v)}
                        >
                            {choices.map((c) => (
                                <ToggleGroupItem key={c.id} value={c.id} className="flex-1" disabled={sending}>
                                    {c.label}
                                </ToggleGroupItem>
                            ))}
                        </ToggleGroup>
                    </div>
                ))}
            </div>
            <div className="flex flex-wrap gap-2">
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    className="flex-1"
                    disabled={!medic || !hurt || treating !== null || sending}
                    onClick={heal}
                >
                    <HeartPulse /> {treating ? 'Medic on his way' : !medic ? 'No medic on his feet' : hurt ? 'Medic, treat him' : 'Not hurt'}
                </Button>
                {others.length > 0 ? (
                    <div className="flex flex-1 gap-1">
                        <NativeSelect
                            className="min-w-0 flex-1"
                            size="sm"
                            value={to ?? ''}
                            onChange={(e) => setTo(Number(e.target.value))}
                            aria-label="Move to squad"
                        >
                            {others.map((s) => (
                                <NativeSelectOption key={s.id} value={s.id}>
                                    To {s.label} · {s.strength} men
                                </NativeSelectOption>
                            ))}
                        </NativeSelect>
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            disabled={to === null || sending}
                            aria-label="Move him to that squad"
                            onClick={() => to !== null && warApi.soldier.transfer(soldier.id, to, { onSuccess: onClose })}
                        >
                            <ArrowRightLeft />
                        </Button>
                    </div>
                ) : null}
            </div>
        </div>
    );
}
