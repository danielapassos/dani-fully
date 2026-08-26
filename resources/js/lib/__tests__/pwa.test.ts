import { describe, expect, it, vi } from 'vitest';

import { registerPwa } from '@/lib/pwa';

describe('registerPwa', () => {
    it('registers the root service worker for a loaded production app', () => {
        const register = vi.fn().mockResolvedValue(undefined);
        const windowObject = { addEventListener: vi.fn() };

        registerPwa({
            isProduction: true,
            serviceWorker: { register } as unknown as ServiceWorkerContainer,
            windowObject: windowObject as unknown as Window,
            documentReadyState: 'complete',
        });

        expect(register).toHaveBeenCalledWith('/sw.js', {
            scope: '/',
            updateViaCache: 'none',
        });
    });

    it('does not register in development or unsupported browsers', () => {
        const register = vi.fn().mockResolvedValue(undefined);
        const windowObject = { addEventListener: vi.fn() };

        registerPwa({
            isProduction: false,
            serviceWorker: { register } as unknown as ServiceWorkerContainer,
            windowObject: windowObject as unknown as Window,
            documentReadyState: 'complete',
        });
        registerPwa({
            isProduction: true,
            serviceWorker: undefined,
            windowObject: windowObject as unknown as Window,
            documentReadyState: 'complete',
        });

        expect(register).not.toHaveBeenCalled();
    });
});
