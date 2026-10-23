import { WarCommandPanel } from '@/features/reforger-war';
import type { WarCommand } from '@/lib/schemas/reforger';

type ServerCard = {
    host: string;
    port: number;
    name: string;
    password: string;
};

export default function ReforgerPage({ war, server }: { war: WarCommand; server: ServerCard }) {
    return (
        <div className="min-h-screen bg-background text-foreground">
            <div className="flex flex-wrap items-baseline gap-x-4 gap-y-1 border-b px-4 py-2 text-sm">
                <span className="font-medium">Kolguyev</span>
                <span>
                    {server.host}:{server.port}
                </span>
                <span>{server.name}</span>
                <span>{server.password === '' ? 'open' : `password ${server.password}`}</span>
            </div>
            <WarCommandPanel war={war} realtime={null} />
        </div>
    );
}
