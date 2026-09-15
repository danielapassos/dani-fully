import { render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';

import { StatusChip } from '@/components/posts/post-preview';
import { PublishedPostView } from '@/components/posts/published-post-view';
import type { PostView, TargetView } from '@/types/compose';

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: {} }),
    useHttp: () => ({ processing: false }),
    router: { reload: vi.fn(), post: vi.fn() },
    Deferred: ({ children }: { children: ReactNode }) => (
        <div data-testid="deferred-metrics">{children}</div>
    ),
}));

function uploadedPost(status: 'awaiting_action' | 'completed'): PostView {
    const target: TargetView = {
        id: 'target-1',
        connected_account_id: 'account-1',
        platform: status === 'awaiting_action' ? 'tiktok' : 'youtube',
        handle: '@definitelyrunninglate',
        display_name: 'Dani',
        avatar_url: null,
        sections: ['Private connection test'],
        content_override: null,
        auto_split: false,
        format: 'feed',
        issues: [],
        status,
        status_message:
            status === 'completed'
                ? 'Uploaded privately to YouTube. This video is not public.'
                : null,
        error_kind: null,
        error_message: null,
        attempts: 1,
        remote_id: 'stored-reference',
    };

    return {
        id: 'post-1',
        base_text: target.sections[0],
        segments: target.sections,
        status,
        published_at: null,
        updated_at: '2026-09-15T12:00:00Z',
        scheduled_at: null,
        auto_repost: false,
        destination: { kind: 'account', id: 'account-1' },
        targets: [target],
        media: [],
    };
}

describe('terminal upload details', () => {
    it.each(['awaiting_action', 'completed'] as const)(
        'renders the %s content and explanation without public links or metrics',
        (status) => {
            const { container } = render(
                <PublishedPostView post={uploadedPost(status)} showMetrics />,
            );

            expect(
                screen.getByText('Private connection test'),
            ).toBeInTheDocument();
            expect(
                screen.getByText(
                    status === 'awaiting_action'
                        ? 'Finish the post in TikTok. It is not live yet.'
                        : 'Uploaded privately to YouTube. This video is not public.',
                ),
            ).toBeInTheDocument();
            expect(
                screen.queryByTestId('deferred-metrics'),
            ).not.toBeInTheDocument();
            expect(
                screen.queryByRole('link', { name: /View on/ }),
            ).not.toBeInTheDocument();
            expect(
                screen.queryByRole('button', { name: /Retry|Refresh/ }),
            ).not.toBeInTheDocument();
            expect(container.querySelector('.animate-spin')).toBeNull();
            expect(container.textContent).not.toContain('Published');
        },
    );

    it('uses the inbox label on shared TikTok target previews', () => {
        render(<StatusChip status="awaiting_action" platform="tiktok" />);

        expect(screen.getByText('In TikTok inbox')).toBeInTheDocument();
    });
});
