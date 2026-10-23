import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { useIsMobile } from '@/hooks/use-mobile';
import type { WarCommand } from '@/lib/schemas/reforger';
import { cn } from '@/lib/utils';
import { HScroll } from '../h-scroll';
import { ORDER_GROUPS, ORDER_KEYS, orderBlocked, orderSpec, type SquadOrderKind } from '../lib/orders';
import { ORDER_ICON } from './icons';
import type { Draft, Squad } from './types';

/**
 * Every order the squad can take, by group: one tap picks what the map will do. A phone scrolls
 * the one row sideways; a desktop has the room to show every order.
 */
export function Palette({ war, squad, draft, onKind }: { war: WarCommand; squad: Squad; draft: Draft; onKind: (kind: SquadOrderKind) => void }) {
    const phone = useIsMobile();
    const row = ORDER_GROUPS.map((group) => (
        <div key={group.label} className="flex shrink-0 flex-col gap-0.5">
            <span className="px-0.5 text-xs text-muted-foreground">{group.label}</span>
            <ToggleGroup
                type="single"
                variant="outline"
                size="sm"
                spacing={1}
                className="flex-wrap justify-start"
                value={(group.kinds as readonly string[]).includes(draft.kind) ? draft.kind : ''}
                onValueChange={(kind) => kind && onKind(kind as SquadOrderKind)}
                aria-label={group.label}
            >
                {group.kinds.map((kind) => {
                    const Icon = ORDER_ICON[kind];
                    const why = orderBlocked(war, squad, kind);
                    const spec = orderSpec(war, kind);

                    return (
                        // The tooltip sits on the item's face: a trigger on the item itself would
                        // take over its data-state and hide which order is picked.
                        <ToggleGroupItem
                            key={kind}
                            value={kind}
                            aria-label={spec.label}
                            className={cn('h-auto min-w-14 p-0 text-xs leading-none', why && 'opacity-50')}
                        >
                            <Tooltip>
                                <TooltipTrigger asChild>
                                    <span className="flex size-full flex-col items-center gap-0.5 px-1 py-1">
                                        <Icon />
                                        {spec.label}
                                    </span>
                                </TooltipTrigger>
                                <TooltipContent className="max-w-72">
                                    {why ?? spec.help}
                                    {ORDER_KEYS[kind] ? (
                                        <kbd className="ml-1.5 rounded border px-1 font-mono text-[10px] uppercase">{ORDER_KEYS[kind]}</kbd>
                                    ) : null}
                                </TooltipContent>
                            </Tooltip>
                        </ToggleGroupItem>
                    );
                })}
            </ToggleGroup>
        </div>
    ));

    return phone ? (
        <HScroll>
            <div className="flex w-max gap-2 pb-2">{row}</div>
        </HScroll>
    ) : (
        <div className="flex flex-wrap gap-x-2 gap-y-1">{row}</div>
    );
}
