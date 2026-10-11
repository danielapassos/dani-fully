import { fireEvent, render, screen } from '@testing-library/react';
import { expect, it, vi } from 'vitest';

import { PhotoMusicControls } from '@/components/compose/photo-music-controls';
import type { Account } from '@/types/compose';

const account: Account = {
    id: 'instagram',
    platform: 'instagram',
    handle: '@creator',
    display_name: 'Creator',
    avatar_url: null,
    max_text_length: 2200,
    x_premium: false,
};

it('makes Instagram native completion explicit and allows clearing the note', () => {
    const onChange = vi.fn();
    render(
        <PhotoMusicControls
            account={account}
            track="Song — Artist"
            onChange={onChange}
        />,
    );
    expect(
        screen.getByLabelText('Music to add in Instagram (optional)'),
    ).toHaveAttribute('maxlength', '200');
    expect(
        screen.getByText(/stays a draft for native completion/),
    ).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Clear music note' }));
    expect(onChange).toHaveBeenCalledExactlyOnceWith('');
});

it('saves a TikTok song note without promising a licensed track selection', () => {
    const onChange = vi.fn();
    render(
        <PhotoMusicControls
            account={{ ...account, platform: 'tiktok' }}
            track=""
            onChange={onChange}
        />,
    );
    fireEvent.change(
        screen.getByLabelText('Music to add in TikTok (optional)'),
        { target: { value: 'Song — Artist' } },
    );
    expect(onChange).toHaveBeenCalledExactlyOnceWith('Song — Artist');
    expect(
        screen.getByText(/does not select licensed music automatically/),
    ).toBeInTheDocument();
    expect(
        screen.queryByRole('button', { name: 'Clear music note' }),
    ).not.toBeInTheDocument();
});
