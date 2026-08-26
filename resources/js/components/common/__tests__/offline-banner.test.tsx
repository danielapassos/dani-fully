import { fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';

import { OfflineBanner } from '@/components/common/offline-banner';

function setOnline(value: boolean): void {
    Object.defineProperty(navigator, 'onLine', {
        configurable: true,
        value,
    });
}

describe('OfflineBanner', () => {
    afterEach(() => setOnline(true));

    it('appears offline and clears after reconnecting', () => {
        setOnline(false);
        render(<OfflineBanner />);

        expect(screen.getByRole('status')).toHaveTextContent(
            'Keep Shoutrrr open',
        );

        setOnline(true);
        fireEvent(window, new Event('online'));

        expect(screen.queryByRole('status')).not.toBeInTheDocument();
    });
});
