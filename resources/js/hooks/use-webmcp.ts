import { router, usePage } from '@inertiajs/react';
import { useEffect, useLayoutEffect, useRef } from 'react';

import {
    index,
    execute,
} from '@/actions/App/Http/Controllers/WebMcpController';
import { connectWebMcp } from '@/lib/webmcp';
import type { Auth, WorkspacesData } from '@/types';

export function useWebMcp(): void {
    const { auth, workspaces } = usePage<{
        auth: Auth;
        workspaces: WorkspacesData;
    }>().props;
    const userId = auth?.user?.id;
    const workspaceId = workspaces?.current?.id;
    const identity = useRef<string | null>(null);

    useLayoutEffect(() => {
        identity.current = `${userId}:${workspaceId}`;

        return () => {
            identity.current = null;
        };
    }, [userId, workspaceId]);

    useEffect(() => {
        if (!userId || !workspaceId) {
            return;
        }

        let active = true;
        const start = () =>
            connectWebMcp({
                userId,
                workspaceId,
                discoveryUrl: index.url(),
                executeUrl: (tool) => execute.url(tool),
                isCurrent: () =>
                    active && identity.current === `${userId}:${workspaceId}`,
                onError: (message) => console.warn(message),
                onMutationSuccess: () => router.reload(),
            });
        let stop = start();
        const hide = () => stop();
        const show = (event: PageTransitionEvent) => {
            if (event.persisted) {
                stop();
                stop = start();
            }
        };
        window.addEventListener('pagehide', hide);
        window.addEventListener('pageshow', show);

        return () => {
            active = false;
            stop();
            window.removeEventListener('pagehide', hide);
            window.removeEventListener('pageshow', show);
        };
    }, [userId, workspaceId]);
}
