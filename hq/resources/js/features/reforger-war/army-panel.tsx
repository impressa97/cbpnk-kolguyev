import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSub,
    DropdownMenuSubContent,
    DropdownMenuSubTrigger,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Progress } from '@/components/ui/progress';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import type { WarCommand, WarSoldierRow } from '@/lib/schemas/reforger';
import { cn } from '@/lib/utils';
import {
    ArrowRightLeft,
    Check,
    ChevronDown,
    ChevronsDownUp,
    ChevronsUpDown,
    Crosshair,
    Dumbbell,
    Ellipsis,
    GraduationCap,
    HeartPulse,
    Pencil,
    Siren,
    Skull,
    Truck,
    UserMinus,
    UserRound,
    Users,
} from 'lucide-react';
import { useEffect, useState, type DragEvent } from 'react';
import { DrillDialog, speciality } from './drill';
import { useWarSending, warApi } from './lib/war-api';
import { gridRef } from './map-geometry';
import { SIDE_DOT } from './side-style';
import { OrderSoldierDialog, SoldierDialog } from './soldier-dialogs';
import { howHeDied, latestOrder, soldierDoing, squadState, STATE_TONE, treatmentFor } from './war-format';

type Squad = WarCommand['squads'][number];

const FLAGS: Record<string, { label: string; tone: string; hint: string }> = {
    down: { label: 'Unconscious', tone: 'border-destructive/40 bg-destructive/15 text-destructive', hint: 'Needs a medic or a medevac now' },
    bleeding: { label: 'Bleeding', tone: 'border-destructive/40 bg-destructive/15 text-destructive', hint: 'Losing blood' },
    wounded: { label: 'Wounded', tone: 'border-map-warning/40 bg-map-warning/15 text-map-warning', hint: 'Health under 60' },
    under_fire: { label: 'Under fire', tone: 'border-destructive/30 bg-destructive/10 text-destructive', hint: 'In a fight right now' },
    shaken: {
        label: 'Shaken',
        tone: 'border-map-warning/40 bg-map-warning/15 text-map-warning',
        hint: 'Morale under 30 (a site rating: HQ pulls a worn-out squad back)',
    },
    low_ammo: { label: 'Low ammo', tone: 'border-map-warning/40 bg-map-warning/15 text-map-warning', hint: 'One spare magazine or none' },
    exhausted: { label: 'Exhausted', tone: '', hint: 'Stamina under 25' },
    mounted: { label: 'Aboard', tone: 'border-map-accent/40 bg-map-accent/10 text-map-accent', hint: 'In the squad vehicle' },
};

/** A man farther than this from his squad has been left behind. */
const STRAY_METRES = 300;

/** What a dragged soldier row carries: his id, for a drop on another squad. */
const DRAG_TYPE = 'application/x-war-soldier';

/**
 * Your squads, one row each, at the top of the command panel (ten from the start; the men are
 * what you pay for). A tap picks the squad and flies the map to it; the chevron unfolds its
 * people and what you can do for them: name it, recruit, train, send the recovered back. Drag a
 * man onto another squad's row to move him there. Empty squads wait behind one button. HQ's
 * detachments are not here: HQ runs them.
 */
export function SquadList({
    war,
    squads,
    selectedId,
    activeSoldierId,
    onSelect,
    onSelectSoldier,
}: {
    war: WarCommand;
    squads: Squad[];
    selectedId: number | null;
    activeSoldierId: number | null;
    /** Pick the squad and fly the map to it. */
    onSelect: (squadId: number) => void;
    onSelectSoldier: (squadId: number, soldierId: number) => void;
}) {
    const own = squads.filter((s) => !s.managed);
    const manned = own.filter((s) => s.strength > 0);
    const open = war.session.open;
    // Unfolding is its own choice: picking a squad does not unfold it.
    const [expanded, setExpanded] = useState<number[]>([]);
    const allOpen = manned.length > 0 && manned.every((s) => expanded.includes(s.id));
    const [showEmpty, setShowEmpty] = useState(false);
    const empty = own.filter((s) => s.strength === 0);
    const listed = showEmpty ? own : own.filter((s) => s.strength > 0 || s.id === selectedId);

    return (
        <div className="flex flex-col gap-1">
            <div className="flex items-center gap-2 px-0.5 text-xs tracking-widest text-muted-foreground uppercase">
                <Users className="size-3.5" /> Squads · {manned.length}/{own.length}
                <span className="tracking-normal normal-case">· keys 1–0</span>
                {manned.length > 1 ? (
                    <Button
                        type="button"
                        size="icon"
                        variant="ghost"
                        className="ml-auto size-6"
                        aria-label={allOpen ? 'Fold all squads' : 'Unfold all squads'}
                        title={allOpen ? 'Fold all squads' : 'Unfold all squads: every man'}
                        onClick={() => setExpanded(allOpen ? [] : manned.map((s) => s.id))}
                    >
                        {allOpen ? <ChevronsDownUp /> : <ChevronsUpDown />}
                    </Button>
                ) : null}
            </div>
            {listed.map((squad) => (
                <SquadItem
                    key={squad.id}
                    war={war}
                    squad={squad}
                    index={own.indexOf(squad) + 1}
                    others={own.filter((o) => o.id !== squad.id)}
                    picked={squad.id === selectedId}
                    expanded={expanded.includes(squad.id)}
                    onExpand={(on) => setExpanded((current) => (on ? [...current, squad.id] : current.filter((id) => id !== squad.id)))}
                    onSelect={() => onSelect(squad.id)}
                    activeSoldierId={activeSoldierId}
                    editable={open}
                    onSelectSoldier={onSelectSoldier}
                />
            ))}
            {empty.length > 0 ? (
                <Button
                    type="button"
                    size="sm"
                    variant="ghost"
                    className="h-7 self-start text-xs text-muted-foreground"
                    onClick={() => setShowEmpty(!showEmpty)}
                >
                    {showEmpty ? 'Hide the empty squads' : `+ ${empty.length} empty squads (men cost supplies, squads do not)`}
                </Button>
            ) : null}
        </div>
    );
}

function SquadItem({
    war,
    squad,
    index,
    others,
    picked,
    expanded,
    onExpand,
    onSelect,
    activeSoldierId,
    editable,
    onSelectSoldier,
}: {
    war: WarCommand;
    squad: Squad;
    /** Its hotkey, 1 to 10. */
    index: number;
    /** Your other squads: where a man can be moved to. */
    others: Squad[];
    picked: boolean;
    expanded: boolean;
    onExpand: (on: boolean) => void;
    onSelect: () => void;
    activeSoldierId: number | null;
    editable: boolean;
    onSelectSoldier?: (squadId: number, soldierId: number) => void;
}) {
    const [drop, setDrop] = useState(false);
    const sending = useWarSending();
    const state = squadState(squad, latestOrder(war.orders, squad.id));
    const people = war.soldiers.filter((s) => s.squad_id === squad.id);
    const field = people.filter((s) => s.location === 'field');
    const reserve = people.filter((s) => s.location === 'reserve');
    const fit = reserve.filter((s) => s.ready_at === null || new Date(s.ready_at).getTime() <= Date.now());
    const fallen = war.fallen.filter((s) => s.squad_id === squad.id);
    const aboard = field.filter((s) => s.in_vehicle).length;
    const crewCount = field.filter((s) => s.in_vehicle && ['driver', 'gunner', 'commander'].includes(s.seat ?? '')).length;
    const c = squad.condition;
    const empty = squad.strength === 0;

    // A man dragged from another squad's list lands here.
    const accepts = (event: DragEvent<HTMLDivElement>) => editable && event.dataTransfer.types.includes(DRAG_TYPE);

    return (
        <div
            className={cn(
                'rounded-md border transition-colors',
                picked ? 'border-map-accent bg-map-accent/5' : 'border-border',
                drop && 'border-dashed border-map-accent bg-map-accent/10',
            )}
            onDragOver={(event) => {
                if (accepts(event)) {
                    event.preventDefault();
                    setDrop(true);
                }
            }}
            onDragLeave={() => setDrop(false)}
            onDrop={(event) => {
                setDrop(false);
                const id = Number(event.dataTransfer.getData(DRAG_TYPE));
                if (accepts(event) && id && !people.some((s) => s.id === id)) {
                    event.preventDefault();
                    warApi.soldier.transfer(id, squad.id);
                }
            }}
        >
            <div className="flex items-center gap-1 pr-1">
                <Button
                    type="button"
                    variant="ghost"
                    onClick={onSelect}
                    className="h-auto min-w-0 flex-1 flex-col items-stretch gap-1 px-2 py-1.5 text-left font-normal whitespace-normal"
                    aria-label={`Pick ${squad.label} and show it on the map`}
                >
                    <span className="flex items-center gap-2 text-sm">
                        <span className={cn('size-2.5 shrink-0 rounded-full', SIDE_DOT[squad.side])} />
                        <span className="font-semibold tracking-wide uppercase">{squad.label}</span>
                        <span className="text-xs text-muted-foreground">
                            {empty ? 'no men yet' : `${c.field}/${squad.strength} · ${squad.tier_name}`}
                        </span>
                        {index <= 10 ? <span className="font-mono text-xs text-muted-foreground">{index % 10}</span> : null}
                        {field.some((s) => s.flags.includes('under_fire')) ? (
                            <Siren className="ml-auto size-3.5 animate-pulse text-destructive" aria-label="under fire" />
                        ) : null}
                        <Badge
                            variant="outline"
                            className={cn(!field.some((s) => s.flags.includes('under_fire')) && 'ml-auto', STATE_TONE[state.tone])}
                        >
                            {state.label}
                        </Badge>
                    </span>
                    {empty ? null : (
                        <span className="flex items-center gap-3 text-xs text-muted-foreground">
                            <span className="font-mono">{gridRef(squad.x, squad.z)}</span>
                            {c.wounded > 0 ? (
                                <span className="flex items-center gap-1 text-map-warning">
                                    <HeartPulse className="size-3.5" /> {c.wounded}
                                </span>
                            ) : null}
                            {aboard > 0 ? (
                                <span className="flex items-center gap-1 text-map-accent">
                                    <Truck className="size-3.5" />
                                    {crewCount > 0 ? `${crewCount} crew` : ''}
                                    {crewCount > 0 && aboard > crewCount ? ' · ' : ''}
                                    {aboard > crewCount ? `${aboard - crewCount} riding` : ''}
                                </span>
                            ) : null}
                            {c.reserve > 0 ? <span>{c.reserve} at base</span> : null}
                            {c.health !== null ? (
                                <span className="ml-auto w-16">
                                    <MiniBar value={c.health} />
                                </span>
                            ) : null}
                        </span>
                    )}
                </Button>
                <Button
                    type="button"
                    size="icon"
                    variant="ghost"
                    className="size-7 shrink-0"
                    aria-expanded={expanded}
                    aria-label={expanded ? `Fold ${squad.label}` : `Unfold ${squad.label}`}
                    onClick={() => onExpand(!expanded)}
                >
                    <ChevronDown className={cn('transition-transform', expanded && 'rotate-180')} />
                </Button>
            </div>
            {expanded ? (
                <div className="flex flex-col gap-2 border-t px-2 pt-2 pb-2">
                    {editable ? <RenameSquad squad={squad} /> : null}
                    {empty ? (
                        <p className="text-xs text-muted-foreground">
                            Recruit men into it, or drag some here from another squad. They appear at your home.
                        </p>
                    ) : null}
                    <ul className={cn('flex flex-col divide-y rounded-md border', field.length === 0 && 'hidden')}>
                        {field.map((soldier) => (
                            <SoldierItem
                                key={soldier.id}
                                war={war}
                                soldier={soldier}
                                active={soldier.id === activeSoldierId}
                                editable={editable}
                                others={others}
                                onPick={editable && onSelectSoldier ? () => onSelectSoldier(squad.id, soldier.id) : undefined}
                            />
                        ))}
                    </ul>
                    {reserve.length > 0 ? (
                        <div className="flex flex-col gap-1.5">
                            <span className="flex items-center gap-2 text-xs tracking-widest text-muted-foreground uppercase">
                                At home · recovering
                                {editable ? (
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="outline"
                                        className="ml-auto h-6 px-2 text-xs tracking-normal normal-case"
                                        disabled={fit.length === 0 || sending}
                                        title="They appear at home and make their way to the squad"
                                        onClick={() => warApi.support({ kind: 'deploy', squad_id: squad.id })}
                                    >
                                        Send back {fit.length > 0 ? fit.length : ''}
                                    </Button>
                                ) : null}
                            </span>
                            <ul className="flex flex-col divide-y rounded-md border border-dashed">
                                {reserve.map((soldier) => (
                                    <SoldierItem key={soldier.id} war={war} soldier={soldier} active={false} editable={editable} others={others} />
                                ))}
                            </ul>
                        </div>
                    ) : null}
                    {editable ? (
                        <div className="flex flex-wrap gap-2 pt-1">
                            <OrderSoldierDialog war={war} squadId={squad.id} disabled={squad.strength >= 12} />
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                className={cn(empty && 'hidden')}
                                disabled={squad.train_cost === null || (war.economy.supplies ?? 0) < (squad.train_cost ?? 0) || sending}
                                onClick={() => warApi.upgrade({ kind: 'train', squad_id: squad.id })}
                            >
                                <GraduationCap /> {squad.train_cost === null ? 'Veteran' : `${squad.train_name} · ${squad.train_cost}`}
                            </Button>
                        </div>
                    ) : null}
                    {fallen.length > 0 ? (
                        <details className="text-xs text-muted-foreground">
                            <summary className="flex cursor-pointer items-center gap-1 tracking-widest uppercase">
                                <Skull className="size-3" /> Memorial · {fallen.length}
                            </summary>
                            <div className="flex flex-col gap-1 pt-1">
                                {fallen.map((s) => (
                                    <span key={s.id}>
                                        {s.name} · {s.role_name} · {s.service} h
                                        {s.kills > 0 ? ` · ${s.kills} ${s.kills === 1 ? 'kill' : 'kills'}` : ''}
                                        {s.battles > 0 ? ` · ${s.battles} ${s.battles === 1 ? 'fight' : 'fights'}` : ''} ·{' '}
                                        {s.loss === 'killed' ? 'killed in action' : 'discharged'}
                                        {howHeDied(s.death) ? ` ${howHeDied(s.death)}` : ''}
                                        {s.note ? ` — ${s.note}` : ''}
                                    </span>
                                ))}
                            </div>
                        </details>
                    ) : null}
                </div>
            ) : null}
        </div>
    );
}

/** Flags that are the game's word on a man. Morale (a site rating) and his seat (said in the line) are left out. */
const SHOWN_FLAGS = ['down', 'bleeding', 'wounded', 'under_fire', 'low_ammo', 'exhausted'];

/**
 * One man on one line: how he is (dot), who he is, what the game has him doing, his health and
 * spare magazines. A tap picks him for his own orders; the menu holds the rest.
 */
function SoldierItem({
    war,
    soldier,
    active,
    editable,
    others = [],
    onPick,
}: {
    war: WarCommand;
    soldier: WarSoldierRow;
    active: boolean;
    editable: boolean;
    others?: Squad[];
    onPick?: () => void;
}) {
    const [dialog, setDialog] = useState<'edit' | 'train' | 'discharge' | null>(null);
    const [drilling, setDrilling] = useState(false);
    const special = speciality(soldier);
    const treating = treatmentFor(war.orders, soldier.id);
    const flags = soldier.flags.filter((flag) => SHOWN_FLAGS.includes(flag) && FLAGS[flag]);
    const reserve = soldier.location === 'reserve';
    // Left far behind his squad (stuck, or a recruit who never caught up): he can lead his own.
    const at = war.markers.find((m) => m.id === soldier.id);
    const squad = war.squads.find((q) => q.id === soldier.squad_id);
    const apart = at && squad && !reserve ? Math.hypot(at.x - squad.x, at.z - squad.z) : 0;
    const straggler = apart > STRAY_METRES ? apart : null;
    const doing = reserve
        ? soldier.ready_at && new Date(soldier.ready_at).getTime() > Date.now()
            ? `recovering, fit at ${new Date(soldier.ready_at).toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' })}`
            : 'fit to go back'
        : soldierDoing(soldier) || 'no report yet';
    const dot = soldier.down
        ? 'bg-muted-foreground'
        : soldier.bleeding || soldier.health < 30
          ? 'bg-destructive'
          : soldier.health < 60
            ? 'bg-map-warning'
            : 'bg-map-success';

    return (
        <li
            className={cn('flex flex-col gap-1 px-2 py-1.5', active && 'bg-map-accent/10', reserve && 'opacity-75', editable && 'cursor-grab')}
            draggable={editable}
            onDragStart={(event) => {
                event.dataTransfer.setData(DRAG_TYPE, String(soldier.id));
                event.dataTransfer.effectAllowed = 'move';
            }}
        >
            <div className="flex items-center gap-2">
                <Button
                    type="button"
                    variant="ghost"
                    disabled={!onPick}
                    onClick={onPick}
                    className="h-auto min-w-0 flex-1 justify-start gap-2 px-1 py-0.5 text-left font-normal whitespace-normal disabled:opacity-100"
                    title={
                        onPick
                            ? `${soldier.name}: stance, pace, the medic, another squad${soldier.note ? ` — ${soldier.note}` : ''}`
                            : soldier.note || undefined
                    }
                >
                    <span className={cn('size-2 shrink-0 rounded-full', dot)} aria-hidden />
                    <span className="flex min-w-0 flex-col">
                        <span className="truncate text-sm font-medium">
                            {soldier.name} <span className="text-xs font-normal text-muted-foreground">{soldier.role_name}</span>
                        </span>
                        <span className="truncate text-xs text-muted-foreground">{doing}</span>
                        {special ? <span className="truncate text-xs text-map-accent">{special}</span> : null}
                    </span>
                </Button>
                <span className="flex shrink-0 flex-col items-end text-xs leading-tight tabular-nums">
                    <span className={cn(soldier.health < 30 ? 'text-destructive' : soldier.health < 60 ? 'text-map-warning' : 'text-foreground')}>
                        {soldier.health}%
                    </span>
                    {soldier.mags !== null ? <span className="text-muted-foreground">{soldier.mags} mags</span> : null}
                    {soldier.kills > 0 ? <span className="text-muted-foreground">{soldier.kills} kills</span> : null}
                    {soldier.ai_skill !== 'regular' ? (
                        <span
                            className={cn('uppercase', soldier.ai_skill === 'expert' ? 'text-map-success' : 'text-map-accent')}
                            title="The game's AI skill: tighter aim. It grows with hours, fights survived, kills, and squad training."
                        >
                            {soldier.ai_skill}
                        </span>
                    ) : null}
                </span>
                {editable ? (
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <Button type="button" size="icon" variant="ghost" className="size-7 shrink-0" aria-label={`Manage ${soldier.name}`}>
                                <Ellipsis />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end">
                            {onPick ? (
                                <DropdownMenuItem onSelect={onPick}>
                                    <Crosshair /> His orders
                                </DropdownMenuItem>
                            ) : null}
                            {others.length > 0 ? (
                                <DropdownMenuSub>
                                    <DropdownMenuSubTrigger>
                                        <ArrowRightLeft /> Move to
                                    </DropdownMenuSubTrigger>
                                    <DropdownMenuSubContent>
                                        {others.map((to) => (
                                            <DropdownMenuItem
                                                key={to.id}
                                                disabled={to.strength >= 12}
                                                onSelect={() => warApi.soldier.transfer(soldier.id, to.id)}
                                            >
                                                {to.label} <span className="ml-auto pl-3 text-xs text-muted-foreground">{to.strength} men</span>
                                            </DropdownMenuItem>
                                        ))}
                                    </DropdownMenuSubContent>
                                </DropdownMenuSub>
                            ) : null}
                            <DropdownMenuItem onSelect={() => setDialog('edit')}>
                                <Pencil /> Name and note
                            </DropdownMenuItem>
                            <DropdownMenuItem onSelect={() => setDrilling(true)}>
                                <Dumbbell /> Drill
                            </DropdownMenuItem>
                            <DropdownMenuItem onSelect={() => setDialog('train')}>
                                <GraduationCap /> Retrain
                            </DropdownMenuItem>
                            {straggler !== null ? (
                                <DropdownMenuItem onSelect={() => warApi.soldier.detach(soldier.id)}>
                                    <UserRound /> Lead his own squad
                                </DropdownMenuItem>
                            ) : null}
                            <DropdownMenuItem variant="destructive" onSelect={() => setDialog('discharge')}>
                                <UserMinus /> Discharge
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                ) : null}
            </div>
            {flags.length > 0 || treating || soldier.seat || straggler !== null ? (
                <div className="flex flex-wrap gap-1 pl-4">
                    {straggler !== null ? (
                        <Button
                            type="button"
                            size="xs"
                            variant="outline"
                            className="h-5 border-map-warning/50 bg-map-warning/10 text-map-warning"
                            title="Far from his squad and not keeping up: make him lead a squad of his own, then give it orders"
                            disabled={!editable}
                            onClick={() => warApi.soldier.detach(soldier.id)}
                        >
                            {(straggler / 1000).toFixed(1)} km behind · own squad
                        </Button>
                    ) : null}
                    {soldier.seat ? (
                        <Badge variant="outline" className="border-map-accent/40 bg-map-accent/10 text-map-accent">
                            {soldier.seat}
                        </Badge>
                    ) : null}
                    {treating ? (
                        <Badge variant="outline" className={cn('', STATE_TONE.busy)}>
                            Medic coming
                        </Badge>
                    ) : null}
                    {flags.map((flag) => (
                        <Tooltip key={flag}>
                            <TooltipTrigger asChild>
                                <Badge variant="outline" className={cn('', FLAGS[flag].tone)}>
                                    {FLAGS[flag].label}
                                </Badge>
                            </TooltipTrigger>
                            <TooltipContent>{FLAGS[flag].hint}</TooltipContent>
                        </Tooltip>
                    ))}
                </div>
            ) : null}
            {dialog ? <SoldierDialog war={war} soldier={soldier} mode={dialog} open onOpenChange={(open) => !open && setDialog(null)} /> : null}
            {drilling && squad ? <DrillDialog war={war} squad={squad} soldier={soldier} open onOpenChange={setDrilling} /> : null}
        </li>
    );
}

/** The commander's own name for a squad; cleared, it goes back to its callsign. */
function RenameSquad({ squad }: { squad: Squad }) {
    const [editing, setEditing] = useState(false);
    const [name, setName] = useState(squad.label);
    useEffect(() => setName(squad.label), [squad.label]);

    if (!editing) {
        return (
            <Button type="button" size="sm" variant="ghost" className="h-7 self-start px-2 text-xs" onClick={() => setEditing(true)}>
                <Pencil /> Rename
            </Button>
        );
    }

    return (
        <form
            className="flex items-center gap-2"
            onSubmit={(event) => {
                event.preventDefault();
                warApi.squad.rename(squad.id, name.trim(), { onSuccess: () => setEditing(false) });
            }}
        >
            <Input
                autoFocus
                className="h-8 max-w-56"
                value={name}
                maxLength={24}
                aria-label="Squad name"
                placeholder="Squad name (empty: its callsign)"
                onChange={(event) => setName(event.target.value)}
                onKeyDown={(event) => event.key === 'Escape' && setEditing(false)}
            />
            <Button type="submit" size="sm" variant="ghost" aria-label="Save the name">
                <Check /> Save
            </Button>
        </form>
    );
}

function MiniBar({ value }: { value: number }) {
    const tone = value < 30 ? 'bg-destructive' : value < 60 ? 'bg-map-warning' : 'bg-map-success';

    return <Progress value={value} className="h-1" indicatorClassName={tone} aria-label={`Health ${value}`} />;
}
