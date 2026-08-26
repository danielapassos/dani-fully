type PwaRegistrationOptions = {
    isProduction?: boolean;
    serviceWorker?: Pick<ServiceWorkerContainer, 'register'>;
    windowObject?: Pick<Window, 'addEventListener'>;
    documentReadyState?: DocumentReadyState;
};

/**
 * Register the root-scoped worker only for production bundles. Development
 * keeps Vite HMR free from stale service-worker caches.
 */
export function registerPwa({
    isProduction = import.meta.env.PROD,
    serviceWorker = typeof navigator === 'undefined'
        ? undefined
        : navigator.serviceWorker,
    windowObject = typeof window === 'undefined' ? undefined : window,
    documentReadyState = typeof document === 'undefined'
        ? 'loading'
        : document.readyState,
}: PwaRegistrationOptions = {}): void {
    if (!isProduction || !serviceWorker || !windowObject) {
        return;
    }

    const register = (): void => {
        void serviceWorker
            .register('/sw.js', {
                scope: '/',
                updateViaCache: 'none',
            })
            .catch(() => undefined);
    };

    if (documentReadyState === 'complete') {
        register();

        return;
    }

    windowObject.addEventListener('load', register, { once: true });
}
