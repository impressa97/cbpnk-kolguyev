/** Desk colours. Named like the city HUD so the copied panel classes resolve. */
export const uiBadge = {
    accent: 'border-primary/40 bg-primary/10 text-primary',
    success: 'border-chart-2/40 bg-chart-2/10 text-chart-2',
    warning: 'border-chart-4/40 bg-chart-4/10 text-chart-4',
    danger: 'border-destructive/40 bg-destructive/10 text-destructive',
    muted: 'text-muted-foreground',
    violet: 'border-chart-3/40 bg-chart-3/10 text-chart-3',
} as const;

export const uiText = {
    success: 'text-chart-2',
    warning: 'text-chart-4',
    danger: 'text-destructive',
    accent: 'text-primary',
    accentBright: 'text-primary',
    subtle: 'text-muted-foreground',
    credits: 'text-chart-4',
} as const;

export const uiInteractive = {
    rowActive: 'bg-primary/5',
    dropZone: 'border-border hover:border-primary/50 hover:bg-muted/30',
    dropZoneActive: 'border-primary bg-primary/5',
    playBtn: 'bg-primary/10 text-primary hover:bg-primary/20',
    playBtnFilled: 'bg-primary text-primary-foreground hover:bg-primary/90',
    link: 'text-primary underline',
    selectedRing: 'border-primary ring-2 ring-primary/30',
    iconMuted: 'text-primary/40',
    waveformBar: 'bg-primary/20',
} as const;
