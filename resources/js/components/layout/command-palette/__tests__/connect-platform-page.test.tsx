/** @vitest-environment jsdom */

import { fireEvent, render, screen } from '@testing-library/react';
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';

import { ConnectPlatformPage } from '@/components/layout/command-palette/connect-platform-page';
import { Command, CommandList } from '@/components/ui/command';
import { index as accountsRoute } from '@/routes/accounts';

const { visit } = vi.hoisted(() => ({ visit: vi.fn() }));

vi.mock('@inertiajs/react', () => ({
    router: { visit },
}));

describe('ConnectPlatformPage', () => {
    beforeAll(() => {
        Element.prototype.scrollIntoView = function scrollIntoView() {};
    });

    beforeEach(() => visit.mockReset());

    it('routes every provider search to the capability-aware Accounts page', () => {
        render(
            <Command>
                <CommandList>
                    <ConnectPlatformPage run={(fn) => fn} />
                </CommandList>
            </Command>,
        );

        fireEvent.click(screen.getByText('Choose a platform on Accounts'));

        expect(visit).toHaveBeenCalledOnce();
        expect(visit).toHaveBeenCalledWith(accountsRoute().url);
        expect(screen.queryByText('X')).not.toBeInTheDocument();
        expect(screen.queryByText('LinkedIn')).not.toBeInTheDocument();
        expect(screen.queryByText('Bluesky')).not.toBeInTheDocument();
    });
});
