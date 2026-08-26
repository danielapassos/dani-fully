import { useOnlineStatus } from '@/hooks/use-online-status';

export function OfflineBanner() {
    const isOnline = useOnlineStatus();

    if (isOnline) {
        return null;
    }

    return (
        <div
            role="status"
            aria-live="polite"
            className="fixed inset-x-3 bottom-[calc(0.75rem+env(safe-area-inset-bottom))] z-[100] rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 text-center text-xs font-medium text-amber-950 shadow-lg dark:border-amber-800 dark:bg-amber-950 dark:text-amber-100"
        >
            You’re offline. Keep Shoutrrr open—drafts can’t sync yet.
        </div>
    );
}
