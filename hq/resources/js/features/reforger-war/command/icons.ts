import {
    Anchor,
    Binoculars,
    Bomb,
    CloudFog,
    Eye,
    Flame,
    Footprints,
    Hand,
    House,
    LogIn,
    LogOut,
    Radar,
    Route,
    ShieldCheck,
    Siren,
    Sparkles,
    Swords,
    Users,
    Zap,
    type LucideIcon,
} from 'lucide-react';
import type { SquadOrderKind } from '../lib/orders';

/** The icon of each squad order, in the palette, the chain and the map's legend. */
export const ORDER_ICON: Record<SquadOrderKind, LucideIcon> = {
    move: Footprints,
    move_forced: Zap,
    halt: Hand,
    rest: House,
    defend: ShieldCheck,
    search: Radar,
    suppress: Siren,
    patrol: Route,
    follow: Users,
    attack: Swords,
    smoke: CloudFog,
    watch: Eye,
    mount: LogIn,
    dismount: LogOut,
    recon: Binoculars,
    sabotage: Bomb,
    raid: Anchor,
};

/** HQ fire missions by support id. */
export const FIRE_ICON: Record<string, LucideIcon> = { fire_he_small: Flame, fire_he_medium: Flame, smoke: CloudFog, illum: Sparkles };
