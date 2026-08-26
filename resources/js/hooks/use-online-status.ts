import { useEffect, useState } from 'react';

export function useOnlineStatus(): boolean {
    const [isOnline, setIsOnline] = useState(() =>
        typeof navigator === 'undefined' ? true : navigator.onLine,
    );

    useEffect(() => {
        const markOnline = (): void => setIsOnline(true);
        const markOffline = (): void => setIsOnline(false);

        window.addEventListener('online', markOnline);
        window.addEventListener('offline', markOffline);

        return () => {
            window.removeEventListener('online', markOnline);
            window.removeEventListener('offline', markOffline);
        };
    }, []);

    return isOnline;
}
