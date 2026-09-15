import { fireEvent, render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';

import { RecentFeed } from '@/components/dashboard/recent-feed';
import type { PostRowData } from '@/components/posts/post-row';

vi.mock('@inertiajs/react', () => ({
    Link: ({ children }: { children?: ReactNode }) => children,
}));

vi.mock('@/components/posts/post-row', () => ({
    PostRow: ({ post }: { post: PostRowData }) => <p>{post.base_text}</p>,
}));

function post(status: PostRowData['status'], text: string): PostRowData {
    return {
        id: status,
        base_text: text,
        status,
        status_label: status,
        author: null,
        target_count: 1,
        updated_at: '2026-09-15T12:00:00Z',
        scheduled_at: null,
        published_at: null,
        platforms: [],
        targets: [],
        media_count: 0,
        media_preview: null,
    };
}

describe('recent upload outcomes', () => {
    it('keeps inbox and private uploads in All without calling them published', () => {
        const mixed = post('partial', 'Mixed upload outcome');
        mixed.targets = [
            {
                id: 'private',
                platform: 'youtube',
                status: 'completed',
                error_kind: null,
                error_message: null,
                attempts: 1,
            },
            {
                id: 'failed',
                platform: 'tiktok',
                status: 'failed',
                error_kind: 'validation',
                error_message: 'Invalid video',
                attempts: 1,
            },
        ];
        render(
            <RecentFeed
                posts={[
                    post('awaiting_action', 'TikTok inbox test'),
                    post('completed', 'Private YouTube test'),
                    post('published', 'Public post'),
                    mixed,
                ]}
            />,
        );

        expect(screen.getByText('TikTok inbox test')).toBeInTheDocument();
        expect(screen.getByText('Private YouTube test')).toBeInTheDocument();
        expect(screen.getByText('Mixed upload outcome')).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: /Published/ }));
        expect(screen.queryByText('TikTok inbox test')).not.toBeInTheDocument();
        expect(
            screen.queryByText('Private YouTube test'),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByText('Mixed upload outcome'),
        ).not.toBeInTheDocument();
        expect(screen.getByText('Public post')).toBeInTheDocument();
    });
});
