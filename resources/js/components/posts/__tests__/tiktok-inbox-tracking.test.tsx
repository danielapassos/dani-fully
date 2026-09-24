import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';

import { TikTokInboxTracking } from '@/components/posts/tiktok-inbox-tracking';
import type { TargetView } from '@/types/compose';

const { postRequest } = vi.hoisted(() => ({ postRequest: vi.fn() }));
vi.mock('@inertiajs/react', () => ({ router: { post: postRequest } }));

function target(tracking: TargetView['inbox_tracking']): TargetView {
    return { id: 'target-1', inbox_tracking: tracking } as TargetView;
}

const waiting: NonNullable<TargetView['inbox_tracking']> = {
    can_refresh: true,
    status: 'awaiting_action',
    message: 'Waiting for you to finish posting in TikTok.',
    checked_at: null,
    public_posts: [],
};

beforeEach(() => vi.clearAllMocks());

it('checks the exact target without starting another upload', () => {
    render(<TikTokInboxTracking postId="post-1" target={target(waiting)} />);

    fireEvent.click(
        screen.getByRole('button', { name: 'Check TikTok publication' }),
    );

    expect(postRequest).toHaveBeenCalledWith(
        '/posts/post-1/targets/target-1/tiktok-inbox/refresh',
        {},
        expect.objectContaining({ preserveScroll: true }),
    );
    expect(
        screen.getByRole('button', { name: 'Checking TikTok…' }),
    ).toBeDisabled();
    expect(screen.queryByRole('link')).not.toBeInTheDocument();
});

it('shows every verified public post created from an inbox upload', () => {
    render(
        <TikTokInboxTracking
            postId="post-1"
            target={target({
                ...waiting,
                can_refresh: false,
                status: 'published',
                message: 'Two public TikTok posts verified.',
                public_posts: [
                    {
                        id: '123',
                        url: 'https://www.tiktok.com/@mommygorl/video/123',
                        caption: 'One',
                        created_at: null,
                    },
                    {
                        id: '456',
                        url: 'https://www.tiktok.com/@mommygorl/video/456',
                        caption: 'Two',
                        created_at: null,
                    },
                ],
            })}
        />,
    );

    expect(
        screen.getByRole('link', { name: 'View TikTok post 1' }),
    ).toHaveAttribute('href', 'https://www.tiktok.com/@mommygorl/video/123');
    expect(
        screen.getByRole('link', { name: 'View TikTok post 2' }),
    ).toHaveAttribute('href', 'https://www.tiktok.com/@mommygorl/video/456');
    expect(screen.queryByRole('button')).not.toBeInTheDocument();
});

it('does not offer inbox tracking for an unrelated target', () => {
    const { container } = render(
        <TikTokInboxTracking postId="post-1" target={target(null)} />,
    );

    expect(container).toBeEmptyDOMElement();
});
