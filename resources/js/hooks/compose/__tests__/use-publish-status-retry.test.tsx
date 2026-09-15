import { act, createElement } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { usePublishStatus } from '@/hooks/compose/use-publish-status';
import type { PostView, TargetView } from '@/types/compose';

const mocks = vi.hoisted(() => ({ post: vi.fn() }));
vi.mock('@inertiajs/react', () => ({ useHttp: () => ({ post: mocks.post }) }));
vi.mock('@/routes/posts/targets', () => ({
    retry: ({ post, target }: { post: string; target: string }) => ({
        url: `/posts/${post}/targets/${target}/retry`,
    }),
}));

let root: Root | null = null;
let container: HTMLDivElement | null = null;

afterEach(() => {
    act(() => root?.unmount());
    container?.remove();
    root = null;
    container = null;
    mocks.post.mockReset();
});

function mount(target: Partial<TargetView>) {
    const pagePost = {
        id: 'post',
        status: 'failed',
        targets: [
            {
                id: 'target',
                platform: 'tiktok',
                status: 'failed',
                error_kind: 'network',
                can_retry: true,
                ...target,
            },
        ],
    } as PostView;
    let current: ReturnType<typeof usePublishStatus>;
    function Harness() {
        current = usePublishStatus({ pagePost });
        return null;
    }
    container = document.createElement('div');
    document.body.append(container);
    root = createRoot(container);
    act(() => root?.render(createElement(Harness)));
    return { state: () => current, pagePost };
}

describe('publish status retry guard', () => {
    it.each([
        'awaiting_action',
        'completed',
        'published',
        'publishing',
    ] as const)(
        'never resubmits a %s target even if a stale payload says it can retry',
        async (status) => {
            const { state } = mount({ status, can_retry: true });
            await act(async () => {
                await state().retry('target');
            });
            expect(mocks.post).not.toHaveBeenCalled();
        },
    );

    it('does not resubmit an unknown outcome or a missing target', async () => {
        const { state } = mount({ error_kind: 'unknown', can_retry: false });
        await act(async () => {
            await state().retry('target');
            await state().retry('missing');
        });
        expect(mocks.post).not.toHaveBeenCalled();
    });

    it('permits a safe retained-session retry only once while the request is in flight', async () => {
        const { state, pagePost } = mount({
            can_retry: true,
            remote_id: 'retained-provider-reference',
        });
        let finish: (value: { post: PostView }) => void = () => undefined;
        mocks.post.mockImplementation(
            () =>
                new Promise<{ post: PostView }>((resolve) => {
                    finish = resolve;
                }),
        );
        await act(async () => {
            const first = state().retry('target');
            const second = state().retry('target');
            expect(mocks.post).toHaveBeenCalledTimes(1);
            finish({ post: pagePost });
            await Promise.all([first, second]);
        });
        expect(state().retryingIds.size).toBe(0);
    });
});
