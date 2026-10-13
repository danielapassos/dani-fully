import { fireEvent, render, screen } from '@testing-library/react';
import { expect, it, vi } from 'vitest';

import { PhotoMusicControls } from '@/components/compose/photo-music-controls';
import type { Account, PhotoMusicOptions } from '@/types/compose';

const account: Account = {
    id: 'instagram',
    platform: 'instagram',
    handle: '@creator',
    display_name: 'Creator',
    avatar_url: null,
    max_text_length: 2200,
    x_premium: false,
};
const plan: PhotoMusicOptions = {
    mode: 'native',
    required: true,
    track: 'Song',
    artist: 'Artist',
    version: 'Official remix',
    start_seconds: 12,
    duration_seconds: 15,
    music_volume: 20,
    original_volume: 100,
    sync_notes: 'Keep narration clear',
};

it('offers native finishing without implying a catalog picker or automatic attachment', () => {
    const onChange = vi.fn();
    render(<PhotoMusicControls account={account} onChange={onChange} />);
    fireEvent.click(
        screen.getByRole('button', { name: 'Plan music in Instagram' }),
    );
    expect(onChange).toHaveBeenCalledExactlyOnceWith({
        mode: 'native',
        track: '',
        required: true,
    });
    expect(screen.getByText(/cannot browse or attach/)).toBeInTheDocument();
});

it('keeps Instagram held and preserves the exact plan when changing volume', () => {
    const onChange = vi.fn();
    render(
        <PhotoMusicControls
            account={account}
            options={plan}
            caption="Approved caption"
            onChange={onChange}
        />,
    );
    expect(screen.getByText(/stays a draft in Shoutrrr/)).toBeInTheDocument();
    expect(
        (screen.getByLabelText('Caption and music plan') as HTMLTextAreaElement)
            .value,
    ).toContain('Official remix');
    fireEvent.change(screen.getByLabelText('Music volume (%)'), {
        target: { value: '0' },
    });
    expect(onChange).toHaveBeenLastCalledWith({ ...plan, music_volume: 0 });
    fireEvent.click(
        screen.getByRole('button', { name: 'Use no added music instead' }),
    );
    expect(onChange).toHaveBeenLastCalledWith({
        mode: 'none',
        track: '',
        required: false,
    });
});

it('invalidates the manual availability check when the song changes', () => {
    const onChange = vi.fn();
    render(
        <PhotoMusicControls
            account={account}
            options={{
                ...plan,
                availability_checked_at: '2026-10-12T12:00:00Z',
            }}
            onChange={onChange}
        />,
    );
    fireEvent.change(screen.getByLabelText('Song title'), {
        target: { value: 'Other song' },
    });
    expect(onChange).toHaveBeenLastCalledWith({
        ...plan,
        track: 'Other song',
        availability_checked_at: null,
    });
});

it('describes TikTok inbox delivery without claiming a live post or selecting music', () => {
    render(
        <PhotoMusicControls
            account={{
                ...account,
                platform: 'tiktok',
                tiktok_inbox_enabled: true,
            }}
            options={plan}
            onChange={vi.fn()}
        />,
    );
    expect(
        screen.getByText(/Upload once to the TikTok inbox/),
    ).toHaveTextContent('not a live post');
    expect(
        screen.getByText(/Downloaded media contains only its existing sound/),
    ).toBeInTheDocument();
});

it('persists a deliberate audio decision instead of clearing it to an unreviewed draft', () => {
    const onChange = vi.fn();
    render(
        <PhotoMusicControls
            account={account}
            options={plan}
            onChange={onChange}
        />,
    );
    fireEvent.click(
        screen.getByRole('button', { name: 'Use file’s existing audio' }),
    );
    expect(onChange).toHaveBeenLastCalledWith({
        mode: 'existing_audio',
        track: '',
        required: false,
    });
    fireEvent.click(screen.getByRole('button', { name: 'No added music' }));
    expect(onChange).toHaveBeenLastCalledWith({
        mode: 'none',
        track: '',
        required: false,
    });
});

it('includes the selected cover without inserting it into video media', () => {
    render(
        <PhotoMusicControls
            account={account}
            options={plan}
            coverId="cover-123"
            onChange={vi.fn()}
        />,
    );
    const cover = screen.getByRole('link', {
        name: 'Download selected Instagram cover',
    });
    expect(cover).toHaveAttribute('href', '/media/cover-123/raw');
    expect(cover).toHaveAttribute('download');
});
