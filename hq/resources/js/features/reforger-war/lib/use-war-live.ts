import { WarCommandSchema, type WarCommand } from '@/lib/schemas/reforger';
import { useEffect, useState } from 'react';

export type WarLink = 'off' | 'connecting' | 'live' | 'lost';

/**
 * What the map's light says: is the island reporting, and is this page still hearing the desk.
 *   game     the mod sent a tick in the last 30 s
 *   stale    the server has not reported for 30 s
 *   linking / lost   the page's poll of GET /desk
 */
export type WarFeedLight = 'off' | 'linking' | 'lost' | 'game' | 'stale';

const FRESH_MS = 30_000;
const POLL_MS = 2_000;

export function useFeedLight(feed: WarCommand['feed'], link: WarLink): WarFeedLight {
    const [now, setNow] = useState(() => Date.now());
    useEffect(() => {
        const timer = window.setInterval(() => setNow(Date.now()), 5000);

        return () => window.clearInterval(timer);
    }, []);

    if (link === 'lost') {
        return 'lost';
    }
    if (link === 'connecting') {
        return 'linking';
    }
    if (!feed || now - new Date(feed.at).getTime() > FRESH_MS) {
        return link === 'off' ? 'off' : 'stale';
    }

    return feed.source;
}

/**
 * The war desk, live. Starts from the page props and replaces them from GET /desk every couple
 * of seconds. A commander's own order still wins, because Inertia reloads the props.
 */
export function useWarLive(initial: WarCommand): { war: WarCommand; link: WarLink; pushedAt: number | null } {
    const [war, setWar] = useState(initial);
    const [link, setLink] = useState<WarLink>('connecting');
    const [pushedAt, setPushedAt] = useState<number | null>(null);

    useEffect(() => setWar(initial), [initial]);

    useEffect(() => {
        let stop = false;

        const tick = async () => {
            try {
                const res = await fetch('/desk', { headers: { Accept: 'application/json' } });
                if (!res.ok) {
                    throw new Error(String(res.status));
                }
                const parsed = WarCommandSchema.safeParse(await res.json());
                if (stop) {
                    return;
                }
                if (parsed.success) {
                    setWar(parsed.data);
                }
                setLink('live');
                setPushedAt(Date.now());
            } catch {
                if (!stop) {
                    setLink('lost');
                }
            }
        };

        void tick();
        const timer = window.setInterval(() => void tick(), POLL_MS);

        return () => {
            stop = true;
            window.clearInterval(timer);
        };
    }, []);

    return { war, link, pushedAt };
}
