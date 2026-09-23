import { act, createElement, type ReactNode } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';

import { SubmitBar } from '@/components/compose/submit-bar';
import type { ScheduleTray } from '@/lib/compose/composer-state';
import type { AccountBlock } from '@/lib/compose/precheck';

const mocks = vi.hoisted(() => ({
    post: vi.fn(),
    put: vi.fn(),
    transform: vi.fn(),
    save: vi.fn(),
    ensure: vi.fn(),
    optimistic: vi.fn(),
}));
vi.mock('@inertiajs/react', () => ({
    useHttp: () => ({
        post: mocks.post,
        put: mocks.put,
        transform: mocks.transform,
        processing: false,
    }),
    router: { visit: vi.fn() },
    Link: ({ href, children }: { href: string; children: ReactNode }) =>
        createElement('a', { href }, children),
}));

const blocked: AccountBlock[] = [
    {
        accountId: 'tiktok',
        handle: '@dani',
        platform: 'tiktok',
        reasons: ['publishing_unavailable', 'tiktok_creator_unavailable'],
        publishingUnavailableReason:
            'TikTok Accounts API app approval is required before public publishing.',
    },
];
let root: Root;
let container: HTMLDivElement;
beforeEach(() => {
    vi.clearAllMocks();
    mocks.save.mockResolvedValue(undefined);
    mocks.optimistic.mockReturnValue(() => undefined);
    mocks.post.mockResolvedValue(undefined);
    mocks.put.mockResolvedValue(undefined);
    container = document.createElement('div');
    document.body.append(container);
    root = createRoot(container);
});
afterEach(() => {
    act(() => root.unmount());
    container.remove();
});

function renderBar(blocks: AccountBlock[], mode: ScheduleTray['mode'] = 'now') {
    act(() =>
        root.render(
            createElement(SubmitBar, {
                tray: { mode, pickedAt: '2026-10-01T12:00:00Z' },
                postId: 'post-id',
                blockedAccounts: blocks,
                limits: [],
                onSaveDraft: mocks.save,
                onEnsurePost: mocks.ensure,
                onOptimisticSubmit: mocks.optimistic,
                onServerPost: () => undefined,
            }),
        ),
    );
}

function button(label: string): HTMLButtonElement {
    const found = Array.from(container.querySelectorAll('button')).find(
        (item) => item.textContent?.includes(label),
    );
    if (!found) throw new Error(`Missing button ${label}`);
    return found;
}

it.each([
    ['now', 'Publish now'],
    ['queue', 'Add to queue'],
    ['pick', 'Schedule'],
] as const)(
    'blocks %s and keyboard submission while preserving Save draft',
    async (mode, label) => {
        renderBar(blocked, mode);
        expect(button(label).disabled).toBe(true);
        expect(button('Save draft').disabled).toBe(false);
        expect(container.textContent).toContain(
            blocked[0].publishingUnavailableReason,
        );
        expect(container.textContent).not.toContain('Refresh TikTok');
        await act(async () => {
            document.dispatchEvent(
                new KeyboardEvent('keydown', {
                    key: 'Enter',
                    metaKey: true,
                    bubbles: true,
                }),
            );
            document.dispatchEvent(
                new KeyboardEvent('keydown', {
                    key: 'Enter',
                    ctrlKey: true,
                    bubbles: true,
                }),
            );
        });
        expect(mocks.save).not.toHaveBeenCalled();
        expect(mocks.post).not.toHaveBeenCalled();
        expect(mocks.put).not.toHaveBeenCalled();
        await act(async () => button('Save draft').click());
        expect(mocks.save).toHaveBeenCalledTimes(1);
    },
);

it('clears the immediate block when a ready destination replaces the unavailable account', async () => {
    renderBar(blocked);
    renderBar([]);
    expect(button('Publish now').disabled).toBe(false);
    expect(container.textContent).not.toContain('app approval');
    await act(async () => button('Publish now').click());
    expect(mocks.post).toHaveBeenCalledTimes(1);
});

it('retains editable content validation instead of treating it as setup failure', async () => {
    renderBar([
        {
            accountId: 'ig',
            handle: '@dani',
            platform: 'instagram',
            reasons: ['video_required'],
        },
    ]);
    expect(button('Publish now').disabled).toBe(false);
    await act(async () => button('Publish now').click());
    expect(mocks.post).not.toHaveBeenCalled();
    expect(container.textContent).toContain('video');
});
