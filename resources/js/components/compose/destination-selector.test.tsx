import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import DestinationSelector, {
    composerAccountIds,
    normalizePublishingDestination,
} from '@/components/compose/destination-selector';
import type { Account, AccountSet, Destination } from '@/types/compose';

function account(
    id: string,
    handle: string,
    over: Partial<Account> = {},
): Account {
    return {
        id,
        platform: 'x',
        handle,
        display_name: handle,
        avatar_url: null,
        status: 'active',
        max_text_length: 280,
        x_premium: false,
        ...over,
    };
}

const accounts = [account('a1', '@one'), account('a2', '@two')];

function open(
    destination: Destination,
    roster: Account[] = accounts,
    sets: AccountSet[] = [],
) {
    const onChange = vi.fn();
    render(
        <DestinationSelector
            accounts={roster}
            sets={sets}
            destination={destination}
            onChange={onChange}
        />,
    );
    fireEvent.click(screen.getByRole('button', { name: 'Post destination' }));

    return onChange;
}

describe('DestinationSelector "All accounts" toggle', () => {
    it('deselects everything when all accounts are selected', () => {
        const onChange = open({ kind: 'all' });

        fireEvent.click(screen.getByRole('button', { name: /all accounts/i }));

        expect(onChange).toHaveBeenCalledWith({ kind: 'none' });
    });

    it('selects everything when nothing is selected', () => {
        const onChange = open({ kind: 'none' });

        fireEvent.click(screen.getByRole('button', { name: /all accounts/i }));

        expect(onChange).toHaveBeenCalledWith({ kind: 'all' });
    });

    it('lets the last account be deselected down to none', () => {
        const onChange = open({ kind: 'account', id: 'a1' });

        fireEvent.click(screen.getByRole('button', { name: /@one/i }));

        expect(onChange).toHaveBeenCalledWith({ kind: 'none' });
    });
});

describe('DestinationSelector trigger label', () => {
    it('reads "No accounts" for an empty selection', () => {
        render(
            <DestinationSelector
                accounts={accounts}
                sets={[]}
                destination={{ kind: 'none' }}
                onChange={vi.fn()}
            />,
        );

        expect(
            screen.getByRole('button', { name: 'Post destination' }),
        ).toHaveTextContent('No accounts');
    });
});

describe('DestinationSelector publishing readiness', () => {
    const unavailableReason =
        'Reconnect TikTok to grant the video upload permission.';
    const mixedAccounts = [
        account('a1', '@ready'),
        account('a2', '@needs-scope', {
            platform: 'tiktok',
            publishing_ready: false,
            publishing_unavailable_reason: unavailableReason,
        }),
    ];

    it('excludes unavailable accounts when selecting All', () => {
        const onChange = open({ kind: 'none' }, mixedAccounts);

        fireEvent.click(screen.getByRole('button', { name: /all accounts/i }));

        expect(onChange).toHaveBeenCalledWith({
            kind: 'account',
            id: 'a1',
        });
    });

    it('shows the unavailable reason and an Accounts recovery action', () => {
        open({ kind: 'all' }, mixedAccounts);

        expect(screen.getByText(unavailableReason)).toBeInTheDocument();
        expect(
            screen.getByRole('button', {
                name: 'Manage @needs-scope on Accounts',
            }),
        ).toBeInTheDocument();
    });

    it('selects only ready members from a mixed account set', () => {
        const sets: AccountSet[] = [
            {
                id: 'set-1',
                name: 'Video channels',
                connected_account_ids: ['a1', 'a2'],
            },
        ];
        const onChange = open({ kind: 'none' }, mixedAccounts, sets);

        fireEvent.click(
            screen.getByRole('button', { name: /video channels/i }),
        );

        expect(onChange).toHaveBeenCalledWith({
            kind: 'account',
            id: 'a1',
        });
    });

    it('keeps legacy account fixtures selectable when readiness is absent', () => {
        const onChange = open({ kind: 'none' });

        fireEvent.click(screen.getByRole('button', { name: /all accounts/i }));

        expect(onChange).toHaveBeenCalledWith({ kind: 'all' });
    });

    it('normalizes a brand-new All destination to explicit ready accounts', () => {
        expect(
            normalizePublishingDestination({ kind: 'all' }, mixedAccounts, []),
        ).toEqual({ kind: 'account', id: 'a1' });
    });

    it('normalizes a brand-new mixed set before its first autosave', () => {
        const sets: AccountSet[] = [
            {
                id: 'set-1',
                name: 'Video channels',
                connected_account_ids: ['a1', 'a2'],
            },
        ];

        expect(
            normalizePublishingDestination(
                { kind: 'set', id: 'set-1' },
                mixedAccounts,
                sets,
            ),
        ).toEqual({ kind: 'account', id: 'a1' });
    });

    it('retains unavailable stored targets for a persisted draft', () => {
        expect(
            composerAccountIds(true, { kind: 'all' }, mixedAccounts, []),
        ).toEqual(['a1', 'a2']);
        expect(
            composerAccountIds(false, { kind: 'all' }, mixedAccounts, []),
        ).toEqual(['a1']);
    });
});
