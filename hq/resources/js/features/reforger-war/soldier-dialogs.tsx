import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect, NativeSelectOptGroup, NativeSelectOption } from '@/components/ui/native-select';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import type { WarCommand, WarRole, WarSideId, WarSoldierRow } from '@/lib/schemas/reforger';
import { uiText } from '@/lib/ui-tokens';
import { usePage } from '@inertiajs/react';
import { GraduationCap, Pencil, UserMinus, UserPlus } from 'lucide-react';
import { useState, type ReactNode } from 'react';
import { useWarSending, warApi, type WarUpgradeRequest } from './lib/war-api';

const ROLE_TASKS = ['Line', 'Command', 'Fire support', 'Anti-armor', 'Recon', 'Support', 'Special forces', 'Suppressed', 'Plain clothes'] as const;

export function rolesFor(roles: WarRole[], side: WarSideId | null): WarRole[] {
    return side ? roles.filter((role) => role.sides.includes(side)) : [];
}

function RoleSelect({
    roles,
    side,
    value,
    onChange,
    id,
}: {
    roles: WarRole[];
    side: WarSideId | null;
    value: string;
    onChange: (id: string) => void;
    id: string;
}) {
    return (
        <NativeSelect id={id} value={value} onChange={(event) => onChange(event.target.value)} className="w-full">
            {ROLE_TASKS.map((task) => {
                const group = roles.filter((role) => role.task === task);

                return group.length === 0 ? null : (
                    <NativeSelectOptGroup key={task} label={task}>
                        {group.map((role) => (
                            <NativeSelectOption key={role.id} value={role.id}>
                                {role.name}
                                {side && role.weapons[side] ? ` · ${role.weapons[side]}` : ''} · {role.cost}
                            </NativeSelectOption>
                        ))}
                    </NativeSelectOptGroup>
                );
            })}
        </NativeSelect>
    );
}

/** A change to the roll; a refusal shows in the dialog that asked. */
function post(data: WarUpgradeRequest, onDone: () => void) {
    warApi.upgrade(data, { onSuccess: onDone, inline: true });
}

/** Order a named soldier into a squad, at the role's price. */
export function OrderSoldierDialog({ war, squadId, disabled }: { war: WarCommand; squadId: number; disabled?: boolean }) {
    const errors = usePage().props.errors as Record<string, string | undefined>;
    const offered = rolesFor(war.catalog.roles, war.enlisted_side);
    const [open, setOpen] = useState(false);
    const [roleId, setRoleId] = useState(offered[0]?.id ?? 'rifleman');
    const [name, setName] = useState('');
    const [note, setNote] = useState('');
    const role = offered.find((r) => r.id === roleId);
    const supplies = war.economy.supplies ?? 0;

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button type="button" size="sm" variant="outline" disabled={disabled}>
                    <UserPlus /> Order soldier
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Order a soldier</DialogTitle>
                    <DialogDescription>
                        A person, not a slot. They start with no hours on the island; every hour adds 1 to each stat.
                    </DialogDescription>
                </DialogHeader>
                <div className="grid gap-3">
                    <Field label="Role" htmlFor="order-role">
                        <RoleSelect id="order-role" roles={offered} side={war.enlisted_side} value={roleId} onChange={setRoleId} />
                    </Field>
                    {role ? (
                        <p className="text-xs text-muted-foreground">
                            Aim {role.aim} · grit {role.grit} · fieldcraft {role.fieldcraft}
                        </p>
                    ) : null}
                    <Field label="Name" htmlFor="order-name">
                        <Input id="order-name" value={name} maxLength={24} onChange={(event) => setName(event.target.value)} />
                    </Field>
                    <Field label="Note only you see" htmlFor="order-note">
                        <Input id="order-note" value={note} maxLength={80} onChange={(event) => setNote(event.target.value)} />
                    </Field>
                    {errors.name || errors.note || errors.supplies ? (
                        <p className={uiText.danger}>{errors.name ?? errors.note ?? errors.supplies}</p>
                    ) : null}
                </div>
                <DialogFooter>
                    <span className="mr-auto self-center text-xs text-muted-foreground">{supplies} supplies on hand</span>
                    <Button
                        type="button"
                        disabled={!role || name.trim() === '' || supplies < (role?.cost ?? 0)}
                        onClick={() =>
                            post({ kind: 'order', squad_id: squadId, role: roleId, name, note }, () => {
                                setOpen(false);
                                setName('');
                                setNote('');
                            })
                        }
                    >
                        Order {role?.name} · {role?.cost}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

/** Rename, keep a note, retrain, or discharge one soldier. Opened from the soldier's menu. */
export function SoldierDialog({
    war,
    soldier,
    mode,
    open,
    onOpenChange,
}: {
    war: WarCommand;
    soldier: WarSoldierRow;
    mode: 'edit' | 'train' | 'discharge';
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const choices = rolesFor(war.catalog.roles, war.enlisted_side).filter((role) => role.id !== soldier.role);
    const [name, setName] = useState(soldier.name);
    const [note, setNote] = useState(soldier.note);
    const [roleId, setRoleId] = useState(choices[0]?.id ?? '');
    const role = choices.find((r) => r.id === roleId);
    const supplies = war.economy.supplies ?? 0;
    const close = () => onOpenChange(false);
    const base = { squad_id: soldier.squad_id, soldier_id: soldier.id };

    const content: Record<typeof mode, { icon: ReactNode; title: string; body: ReactNode; action: ReactNode }> = {
        edit: {
            icon: <Pencil className="size-4" />,
            title: `${soldier.name}`,
            body: (
                <div className="grid gap-3">
                    <Field label="Name" htmlFor="edit-name">
                        <Input id="edit-name" value={name} maxLength={24} onChange={(event) => setName(event.target.value)} />
                    </Field>
                    <Field label="Note only you see" htmlFor="edit-note">
                        <Input id="edit-note" value={note} maxLength={80} onChange={(event) => setNote(event.target.value)} />
                    </Field>
                </div>
            ),
            action: (
                <Button type="button" onClick={() => post({ kind: 'remember', ...base, name, note }, close)}>
                    Save
                </Button>
            ),
        },
        train: {
            icon: <GraduationCap className="size-4" />,
            title: `Retrain ${soldier.name}`,
            body: (
                <div className="grid gap-2">
                    <p className="text-sm text-muted-foreground">
                        Now a {soldier.role_name}. A new role costs its full price and keeps the {soldier.service} hours served.
                    </p>
                    <RoleSelect id="train-role" roles={choices} side={war.enlisted_side} value={roleId} onChange={setRoleId} />
                </div>
            ),
            action: (
                <Button type="button" disabled={!role || supplies < role.cost} onClick={() => post({ kind: 'tune', ...base, role: roleId }, close)}>
                    Train as {role?.name} · {role?.cost}
                </Button>
            ),
        },
        discharge: {
            icon: <UserMinus className="size-4" />,
            title: `Discharge ${soldier.name}?`,
            body: (
                <p className="text-sm text-muted-foreground">
                    They go home. Nothing spent on them comes back; their name and hours stay on the roll.
                </p>
            ),
            action: (
                <Button type="button" variant="destructive" onClick={() => post({ kind: 'dismiss', ...base }, close)}>
                    Discharge
                </Button>
            ),
        },
    };
    const view = content[mode];

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle className="flex items-center gap-2">
                        {view.icon}
                        {view.title}
                    </DialogTitle>
                    <DialogDescription>
                        {soldier.role_name} · {soldier.service} h on the island · {soldier.battles} fights survived
                    </DialogDescription>
                </DialogHeader>
                {view.body}
                <DialogFooter>{view.action}</DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function Field({ label, htmlFor, children }: { label: string; htmlFor: string; children: ReactNode }) {
    return (
        <div className="grid gap-1.5">
            <Label htmlFor={htmlFor}>{label}</Label>
            {children}
        </div>
    );
}

/**
 * Reinforcements: pick a role, then confirm. The man joins the squad with one of his side's
 * names, appears at home and makes his own way to the squad, as a recruit does in the game.
 * Nothing is bought on the first tap.
 */
export function ReinforcePopover({ war, squad }: { war: WarCommand; squad: WarCommand['squads'][number] }) {
    const [open, setOpen] = useState(false);
    const sending = useWarSending();
    const [picked, setPicked] = useState<string | null>(null);
    const offered = rolesFor(war.catalog.roles, war.enlisted_side);
    const supplies = war.economy.supplies ?? 0;
    const full = squad.strength >= 12;
    const role = offered.find((r) => r.id === picked) ?? null;

    return (
        <Popover
            open={open}
            onOpenChange={(next) => {
                setOpen(next);
                setPicked(null);
            }}
        >
            <PopoverTrigger asChild>
                <Button type="button" size="xs" variant="outline" disabled={full} title={full ? 'The squad is full' : 'Send men to this squad'}>
                    <UserPlus /> Men
                </Button>
            </PopoverTrigger>
            <PopoverContent align="end" className="flex w-72 flex-col gap-1 p-2">
                <p className="px-1 pb-1 text-xs text-muted-foreground">
                    {squad.label}: {squad.strength}/12 · {supplies} supplies. New men appear at home and walk out to the squad.
                </p>
                <div className="flex max-h-56 flex-col overflow-y-auto">
                    {ROLE_TASKS.map((task) => {
                        const group = offered.filter((r) => r.task === task);

                        return group.length === 0 ? null : (
                            <div key={task} className="flex flex-col">
                                <span className="px-1 pt-1 text-xs text-muted-foreground">{task}</span>
                                <ToggleGroup
                                    type="single"
                                    size="sm"
                                    className="w-full flex-col items-stretch"
                                    value={picked ?? ''}
                                    onValueChange={(id) => id && setPicked(id)}
                                    aria-label={task}
                                >
                                    {group.map((r) => (
                                        <ToggleGroupItem
                                            key={r.id}
                                            value={r.id}
                                            disabled={supplies < r.cost}
                                            className="justify-start gap-2 rounded-md px-1.5 text-left text-xs"
                                        >
                                            <span className="flex-1 truncate">
                                                {r.name}
                                                {war.enlisted_side && r.weapons[war.enlisted_side] ? (
                                                    <span className="text-muted-foreground"> · {r.weapons[war.enlisted_side]}</span>
                                                ) : null}
                                            </span>
                                            <span className="font-mono text-map-warning">{r.cost}</span>
                                        </ToggleGroupItem>
                                    ))}
                                </ToggleGroup>
                            </div>
                        );
                    })}
                </div>
                <Button
                    type="button"
                    size="sm"
                    className="mt-1"
                    disabled={!role || sending || supplies < (role?.cost ?? 0)}
                    onClick={() =>
                        role && warApi.upgrade({ kind: 'order', squad_id: squad.id, role: role.id, name: '' }, { onSuccess: () => setPicked(null) })
                    }
                >
                    <UserPlus /> {role ? `Send a ${role.name.toLowerCase()} · ${role.cost}` : 'Pick a role'}
                </Button>
            </PopoverContent>
        </Popover>
    );
}
