import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Progress } from '@/components/ui/progress';
import type { WarCommand, WarSoldierRow } from '@/lib/schemas/reforger';
import { warApi } from './lib/war-api';

type Squad = WarCommand['squads'][number];
type Drilled = 'aim' | 'fieldcraft' | 'grit' | 'endurance';

/** What each stat does in the game, the mod's own numbers (CBPNK_WarBridge.ApplySkill). */
function drillEffect(stat: Drilled, value: number, tier: number, skill: WarSoldierRow['ai_skill']): string {
    const over = (top: number) => Math.min(1, Math.max(0, (value - 40) / 60)) * top;
    if (stat === 'aim') {
        const next = value + 10 * tier < 60 ? ' · veteran at 60' : value + 10 * tier < 80 ? ' · expert at 80' : '';
        const kick = Math.round(over(35));
        return `aims as ${skill}${kick > 0 ? ` · recoil −${kick}%` : ''}${next}`;
    }
    if (stat === 'fieldcraft') {
        return `spots ×${Math.min(1.8, 1 + Math.max(0, value - 40) * 0.012 + tier * 0.05).toFixed(2)}`;
    }
    if (stat === 'endurance') {
        const mags = Math.min(3, Math.max(0, Math.floor((value - 40) / 20)));
        return value > 40 ? `gets his breath back${mags > 0 ? ` · +${mags} mags` : ''}` : 'as any man';
    }
    const bleed = Math.round(over(50));
    return `${value >= 70 ? 'alert on his own' : 'alert on his own at 70'}${bleed > 0 ? ` · bleeds −${bleed}%` : ''}`;
}

const DRILLS: Array<{ id: Drilled; label: string; help: string }> = [
    {
        id: 'aim',
        label: 'Aim',
        help: "The game's aiming skill: regular, veteran from 60, expert from 80 (squad training adds 10 a tier). His weapon kicks less, up to −35%.",
    },
    { id: 'fieldcraft', label: 'Fieldcraft', help: "How soon and how far he spots the enemy (the game's perception), up to ×1.8." },
    {
        id: 'grit',
        label: 'Grit',
        help: "From 70 he is never taken by surprise: vigilant on his own, whatever the squad's readiness. He bleeds slower, up to −50%.",
    },
    {
        id: 'endurance',
        label: 'Endurance',
        help: 'He gets his breath back on the move and runs longer; one more spare magazine at resupply for every 20 over 40, up to three.',
    },
];

/** His drilled stats at a glance for the roster: the one or two he is built on. */
export function speciality(soldier: WarSoldierRow): string | null {
    const value: Record<Drilled, number> = {
        aim: soldier.aim_effective,
        fieldcraft: soldier.fieldcraft_effective,
        grit: soldier.grit_effective,
        endurance: soldier.endurance_effective,
    };
    const strong = DRILLS.filter(({ id }) => value[id] >= 60).map(({ id, label }) => `${label} ${value[id]}`);

    return strong.length > 0 ? strong.join(' · ') : null;
}

/**
 * Drill one man in one thing, ten points a time, each step dearer: a specialist is made by
 * drilling his one or two things up. What he has is what he bought plus what he learnt in the
 * field. Squad management, not the battle map: it is opened from the man's menu in the roster.
 */
export function DrillDialog({
    war,
    squad,
    soldier,
    open,
    onOpenChange,
}: {
    war: WarCommand;
    squad: Squad;
    soldier: WarSoldierRow;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const supplies = war.economy.supplies ?? 0;
    const value: Record<Drilled, number> = {
        aim: soldier.aim_effective,
        fieldcraft: soldier.fieldcraft_effective,
        grit: soldier.grit_effective,
        endurance: soldier.endurance_effective,
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>Drill {soldier.name}</DialogTitle>
                    <DialogDescription>
                        {soldier.role_name} · {squad.label}. Ten points a step, each step dearer; field experience adds on top. {supplies} supplies.
                    </DialogDescription>
                </DialogHeader>
                <div className="flex flex-col gap-3">
                    {DRILLS.map(({ id, label, help }) => {
                        const cost = soldier.drill[id];

                        return (
                            <div key={id} className="flex flex-col gap-1">
                                <div className="flex items-center gap-2 text-sm">
                                    <span className="w-20 shrink-0 font-medium">{label}</span>
                                    <Progress value={value[id]} className="h-1.5 flex-1" aria-label={`${label} ${value[id]}`} />
                                    <span className="w-7 shrink-0 text-right tabular-nums">{value[id]}</span>
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="outline"
                                        className="w-24 shrink-0"
                                        disabled={cost === null || cost > supplies}
                                        aria-label={`Drill ${soldier.name}'s ${label.toLowerCase()}`}
                                        onClick={() => warApi.upgrade({ kind: 'drill', squad_id: squad.id, soldier_id: soldier.id, stat: id })}
                                    >
                                        {cost === null ? 'At the top' : `+10 · ${cost}`}
                                    </Button>
                                </div>
                                <p className="text-xs text-muted-foreground">
                                    <span className="text-foreground">{drillEffect(id, value[id], squad.tier, soldier.ai_skill)}</span> — {help}
                                </p>
                            </div>
                        );
                    })}
                </div>
            </DialogContent>
        </Dialog>
    );
}
