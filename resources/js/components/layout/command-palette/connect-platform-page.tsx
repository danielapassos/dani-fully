import { router } from '@inertiajs/react';

import { CommandGroup, CommandItem } from '@/components/ui/command';
import { Plug } from '@/components/ui/icons';
import { index as accountsRoute } from '@/routes/accounts';

interface ConnectPlatformPageProps {
    run: (fn: () => void) => () => void;
}

export function ConnectPlatformPage({ run }: ConnectPlatformPageProps) {
    return (
        <CommandGroup heading="Connect account">
            <CommandItem
                value="connect account x bluesky linkedin facebook instagram tiktok youtube threads discord"
                onSelect={run(() => router.visit(accountsRoute().url))}
            >
                <Plug className="size-4" aria-hidden />
                Choose a platform on Accounts
            </CommandItem>
        </CommandGroup>
    );
}
