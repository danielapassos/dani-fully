import { cleanup, renderHook } from '@testing-library/react';
import { act, useLayoutEffect } from 'react';
import {
    afterEach,
    beforeEach,
    describe,
    expect,
    it,
    vi,
    type Mock,
} from 'vitest';

import { useWebMcp } from '@/hooks/use-webmcp';
import { connectWebMcp } from '@/lib/webmcp';

type PageProps = {
    auth: { user: { id: string } | null } | null;
    workspaces: { current: { id: string } | null } | null;
};

const { page, reload, discoveryUrl, executeUrl } = vi.hoisted(() => ({
    page: {
        props: {
            auth: { user: { id: 'user-1' } },
            workspaces: { current: { id: 'workspace-1' } },
        } as PageProps,
    },
    reload: vi.fn(),
    discoveryUrl: vi.fn(() => '/webmcp/tools'),
    executeUrl: vi.fn((name: string) => `/webmcp/tools/${name}`),
}));

vi.mock('@inertiajs/react', () => ({
    usePage: () => page,
    router: { reload },
}));

vi.mock('@/actions/App/Http/Controllers/WebMcpController', () => ({
    index: { url: discoveryUrl },
    execute: { url: executeUrl },
}));

vi.mock('@/lib/webmcp', () => ({ connectWebMcp: vi.fn() }));

const stops: Mock<() => void>[] = [];

function identity(userId = 'user-1', workspaceId = 'workspace-1'): PageProps {
    return {
        auth: { user: { id: userId } },
        workspaces: { current: { id: workspaceId } },
    };
}

function pageTransition(name: 'pagehide' | 'pageshow', persisted: boolean) {
    const event = new Event(name);
    Object.defineProperty(event, 'persisted', { value: persisted });
    act(() => {
        window.dispatchEvent(event);
    });
}

beforeEach(() => {
    page.props = identity();
    stops.length = 0;
    vi.mocked(connectWebMcp).mockReset();
    vi.mocked(connectWebMcp).mockImplementation(() => {
        const stop = vi.fn<() => void>();
        stops.push(stop);

        return stop;
    });
});

afterEach(() => {
    cleanup();
    vi.clearAllMocks();
    vi.restoreAllMocks();
});

describe('useWebMcp', () => {
    it.each<PageProps>([
        { auth: null, workspaces: { current: { id: 'workspace-1' } } },
        {
            auth: { user: null },
            workspaces: { current: { id: 'workspace-1' } },
        },
        { auth: { user: { id: 'user-1' } }, workspaces: null },
        { auth: { user: { id: 'user-1' } }, workspaces: { current: null } },
    ])(
        'does not discover browser tools without a complete signed-in identity (%j)',
        (props) => {
            page.props = props;

            renderHook(() => useWebMcp());

            expect(connectWebMcp).not.toHaveBeenCalled();
            expect(discoveryUrl).not.toHaveBeenCalled();
        },
    );

    it('binds registration to the signed-in user, active workspace and generated routes', () => {
        renderHook(() => useWebMcp());

        expect(connectWebMcp).toHaveBeenCalledTimes(1);
        const options = vi.mocked(connectWebMcp).mock.calls[0][0];
        expect(options).toMatchObject({
            userId: 'user-1',
            workspaceId: 'workspace-1',
            discoveryUrl: '/webmcp/tools',
        });
        expect(options.isCurrent()).toBe(true);
        expect(options.executeUrl('list_posts')).toBe(
            '/webmcp/tools/list_posts',
        );
        expect(executeUrl).toHaveBeenCalledWith('list_posts');
    });

    it('keeps the registration through ordinary renders with the same identity', () => {
        const { rerender } = renderHook(() => useWebMcp());
        page.props = identity();

        rerender();

        expect(connectWebMcp).toHaveBeenCalledTimes(1);
        expect(stops[0]).not.toHaveBeenCalled();
        expect(vi.mocked(connectWebMcp).mock.calls[0][0].isCurrent()).toBe(
            true,
        );
    });

    it.each([
        ['user-1', 'workspace-2'],
        ['user-2', 'workspace-1'],
    ])(
        'retires the old identity when the user or workspace changes to %s/%s',
        (userId, workspaceId) => {
            const { rerender } = renderHook(() => useWebMcp());
            const oldOptions = vi.mocked(connectWebMcp).mock.calls[0][0];
            page.props = identity(userId, workspaceId);

            rerender();

            expect(stops[0]).toHaveBeenCalledTimes(1);
            expect(oldOptions.isCurrent()).toBe(false);
            expect(connectWebMcp).toHaveBeenCalledTimes(2);
            const newOptions = vi.mocked(connectWebMcp).mock.calls[1][0];
            expect(newOptions).toMatchObject({ userId, workspaceId });
            expect(newOptions.isCurrent()).toBe(true);
            expect(stops[1]).not.toHaveBeenCalled();
        },
    );

    it('invalidates old callbacks during the identity-change layout phase before passive cleanup', () => {
        const observer = () => {};
        const { rerender } = renderHook(
            ({ afterLayout }) => {
                useWebMcp();
                useLayoutEffect(afterLayout, [afterLayout]);
            },
            { initialProps: { afterLayout: observer } },
        );
        const oldOptions = vi.mocked(connectWebMcp).mock.calls[0][0];
        const phaseStates: { current: boolean; cleanupCalls: number }[] = [];
        page.props = identity('user-1', 'workspace-2');

        rerender({
            afterLayout: () => {
                phaseStates.push({
                    current: oldOptions.isCurrent(),
                    cleanupCalls: stops[0].mock.calls.length,
                });
            },
        });

        expect(phaseStates).toEqual([{ current: false, cleanupCalls: 0 }]);
        expect(stops[0]).toHaveBeenCalledTimes(1);
    });

    it('retires registrations when authentication disappears', () => {
        const { rerender } = renderHook(() => useWebMcp());
        const oldOptions = vi.mocked(connectWebMcp).mock.calls[0][0];
        page.props = { auth: null, workspaces: null };

        rerender();

        expect(oldOptions.isCurrent()).toBe(false);
        expect(stops[0]).toHaveBeenCalledTimes(1);
        expect(connectWebMcp).toHaveBeenCalledTimes(1);
    });

    it('cleans up tools and page lifecycle listeners on unmount', () => {
        const { unmount } = renderHook(() => useWebMcp());
        const options = vi.mocked(connectWebMcp).mock.calls[0][0];

        unmount();
        pageTransition('pagehide', true);
        pageTransition('pageshow', true);

        expect(options.isCurrent()).toBe(false);
        expect(stops[0]).toHaveBeenCalledTimes(1);
        expect(connectWebMcp).toHaveBeenCalledTimes(1);
    });

    it('disposes tools on pagehide and recreates them only for a persisted pageshow', () => {
        renderHook(() => useWebMcp());

        pageTransition('pagehide', true);

        expect(stops[0]).toHaveBeenCalledTimes(1);
        pageTransition('pageshow', false);
        expect(connectWebMcp).toHaveBeenCalledTimes(1);

        pageTransition('pageshow', true);

        expect(connectWebMcp).toHaveBeenCalledTimes(2);
        expect(stops[0]).toHaveBeenCalledTimes(2);
        expect(stops[1]).not.toHaveBeenCalled();
        expect(vi.mocked(connectWebMcp).mock.calls[1][0].isCurrent()).toBe(
            true,
        );
    });

    it('removes old workspace lifecycle listeners before a restored page reconnects', () => {
        const { rerender } = renderHook(() => useWebMcp());
        page.props = identity('user-2', 'workspace-2');
        rerender();

        pageTransition('pageshow', true);

        expect(connectWebMcp).toHaveBeenCalledTimes(3);
        expect(vi.mocked(connectWebMcp).mock.calls[2][0]).toMatchObject({
            userId: 'user-2',
            workspaceId: 'workspace-2',
        });
        expect(stops[0]).toHaveBeenCalledTimes(1);
        expect(stops[1]).toHaveBeenCalledTimes(1);
    });

    it('refreshes visible state only when the connector confirms a mutation', () => {
        renderHook(() => useWebMcp());
        expect(reload).not.toHaveBeenCalled();

        vi.mocked(connectWebMcp).mock.calls[0][0].onMutationSuccess?.();

        expect(reload).toHaveBeenCalledTimes(1);
        expect(connectWebMcp).toHaveBeenCalledTimes(1);
    });

    it('retires the first StrictMode registration and keeps exactly one active registration', () => {
        const { unmount } = renderHook(() => useWebMcp(), {
            reactStrictMode: true,
        });

        expect(connectWebMcp).toHaveBeenCalledTimes(2);
        expect(stops[0]).toHaveBeenCalledTimes(1);
        expect(stops[1]).not.toHaveBeenCalled();
        expect(vi.mocked(connectWebMcp).mock.calls[0][0].isCurrent()).toBe(
            false,
        );
        expect(vi.mocked(connectWebMcp).mock.calls[1][0].isCurrent()).toBe(
            true,
        );

        unmount();

        expect(stops[0]).toHaveBeenCalledTimes(1);
        expect(stops[1]).toHaveBeenCalledTimes(1);
    });
});
