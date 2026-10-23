/** Desk colours. */
export const uiBadge = {
    accent: 'border-primary/40 bg-primary/10 text-primary',
    warning: 'border-chart-4/40 bg-chart-4/10 text-chart-4',
    danger: 'border-destructive/40 bg-destructive/10 text-destructive',
} as const;

export const uiText = {
    danger: 'text-destructive',
    accent: 'text-primary',
    subtle: 'text-muted-foreground',
    credits: 'text-chart-4',
} as const;

export const uiInteractive = {
    selectedRing: 'border-primary ring-2 ring-primary/30',
} as const;
