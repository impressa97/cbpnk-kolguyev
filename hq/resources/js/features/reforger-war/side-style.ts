import type { WarSideId } from '@/lib/schemas/reforger';
import { uiBadge } from '@/lib/ui-tokens';

export const SIDE_BADGE: Record<WarSideId, string> = {
    us: uiBadge.accent,
    ussr: uiBadge.danger,
    fia: uiBadge.warning,
};

export const SIDE_DOT: Record<WarSideId, string> = {
    us: 'bg-map-accent',
    ussr: 'bg-destructive',
    fia: 'bg-map-warning',
};
