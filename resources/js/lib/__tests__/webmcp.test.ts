import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { connectWebMcp, type WebMcpTool } from '@/lib/webmcp';

type RegisteredTool = WebMcpTool & {
    execute: (
        arguments_: Record<string, unknown>,
        options?: { signal?: AbortSignal },
    ) => Promise<string>;
};

const readTool: WebMcpTool = {
    name: 'list_posts',
    description: 'List posts in the current workspace.',
    inputSchema: {
        type: 'object',
        properties: { limit: { type: 'integer' } },
        additionalProperties: false,
    },
    annotations: {
        readOnlyHint: true,
        consequentialHint: false,
        untrustedContentHint: true,
    },
};

const publishTool: WebMcpTool = {
    name: 'publish_post',
    description: 'Publish an explicitly approved post.',
    inputSchema: {
        type: 'object',
        properties: {
            post_id: { type: 'string' },
            confirm: { type: 'boolean' },
        },
        required: ['post_id', 'confirm'],
        additionalProperties: false,
    },
    annotations: {
        readOnlyHint: false,
        consequentialHint: true,
        untrustedContentHint: false,
    },
};

const cleanups: (() => void)[] = [];

function response(body: unknown, status = 200, headers: HeadersInit = {}) {
    const responseHeaders = new Headers(headers);
    responseHeaders.set('Content-Type', 'application/json');

    return new Response(JSON.stringify(body), {
        status,
        headers: responseHeaders,
    });
}

function requestUrl(input: RequestInfo | URL): URL {
    return new URL(input instanceof Request ? input.url : input);
}

function discovery(tools: WebMcpTool[] = [readTool]) {
    return response({ user_id: 'user-1', workspace_id: 'workspace-1', tools });
}

function deferred<T>() {
    let resolve!: (value: T) => void;
    let reject!: (reason: unknown) => void;
    const promise = new Promise<T>((resolvePromise, rejectPromise) => {
        resolve = resolvePromise;
        reject = rejectPromise;
    });

    return { promise, resolve, reject };
}

function connect(overrides: Partial<Parameters<typeof connectWebMcp>[0]> = {}) {
    const cleanup = connectWebMcp({
        userId: 'user-1',
        workspaceId: 'workspace-1',
        discoveryUrl: '/webmcp/tools',
        executeUrl: (name) => `/webmcp/tools/${name}`,
        isCurrent: () => true,
        ...overrides,
    });
    cleanups.push(cleanup);

    return cleanup;
}

function documentContext() {
    const registerTool = vi
        .fn<
            (
                tool: RegisteredTool,
                options: { signal: AbortSignal },
            ) => Promise<void>
        >()
        .mockResolvedValue(undefined);
    Object.assign(document, { modelContext: { registerTool } });

    return { registerTool };
}

function legacyContext() {
    const registerTool =
        vi.fn<(tool: RegisteredTool) => void | Promise<void>>();
    const unregisterTool = vi.fn<(name: string) => void>();
    Object.assign(navigator, {
        modelContext: { registerTool, unregisterTool },
    });

    return { registerTool, unregisterTool };
}

beforeEach(() => {
    vi.stubGlobal('document', { cookie: 'XSRF-TOKEN=token%2Bvalue' });
    vi.stubGlobal('navigator', {});
    vi.stubGlobal('window', {
        location: {
            href: 'https://shoutrrr.test/dashboard',
            origin: 'https://shoutrrr.test',
        },
    });
    vi.stubGlobal('fetch', vi.fn<typeof fetch>());
});

afterEach(() => {
    for (const cleanup of cleanups.splice(0)) {
        cleanup();
    }
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
});

describe('connectWebMcp', () => {
    it('does nothing in unsupported browsers', () => {
        expect(() => connect()()).not.toThrow();
        expect(fetch).not.toHaveBeenCalled();
    });

    it('does not use a legacy implementation that cannot unregister tools', () => {
        const registerTool = vi.fn();
        Object.assign(navigator, { modelContext: { registerTool } });

        connect();

        expect(fetch).not.toHaveBeenCalled();
        expect(registerTool).not.toHaveBeenCalled();
    });

    it('prefers the document API and preserves the server schema and annotations', async () => {
        const current = documentContext();
        const legacy = legacyContext();
        vi.mocked(fetch).mockResolvedValueOnce(
            discovery([readTool, publishTool]),
        );

        const cleanup = connect();

        await vi.waitFor(() =>
            expect(current.registerTool).toHaveBeenCalledTimes(2),
        );
        expect(legacy.registerTool).not.toHaveBeenCalled();
        expect(current.registerTool.mock.calls[0][0]).toMatchObject(readTool);
        expect(current.registerTool.mock.calls[1][0]).toMatchObject(
            publishTool,
        );
        expect(fetch).toHaveBeenCalledTimes(1);
        const discoveryUrl = requestUrl(vi.mocked(fetch).mock.calls[0][0]);
        expect(discoveryUrl.origin).toBe('https://shoutrrr.test');
        expect(discoveryUrl.searchParams.get('user_id')).toBe('user-1');
        expect(discoveryUrl.searchParams.get('workspace_id')).toBe(
            'workspace-1',
        );
        expect(vi.mocked(fetch).mock.calls[0][1]).toMatchObject({
            credentials: 'same-origin',
        });

        const signal = current.registerTool.mock.calls[0][1].signal;
        expect(signal.aborted).toBe(false);
        cleanup();
        expect(signal.aborted).toBe(true);
    });

    it('unregisters each legacy tool once when the page is disposed', async () => {
        const legacy = legacyContext();
        vi.mocked(fetch).mockResolvedValueOnce(
            discovery([readTool, publishTool]),
        );

        const cleanup = connect();

        await vi.waitFor(() =>
            expect(legacy.registerTool).toHaveBeenCalledTimes(2),
        );
        cleanup();
        cleanup();

        expect(legacy.unregisterTool.mock.calls).toEqual([
            [readTool.name],
            [publishTool.name],
        ]);
    });

    it('executes once with the bound identity, exact arguments and the current CSRF token', async () => {
        const { registerTool } = documentContext();
        const result = { content: [{ type: 'text', text: 'Post queued.' }] };
        vi.mocked(fetch)
            .mockResolvedValueOnce(discovery([publishTool]))
            .mockResolvedValueOnce(response(result));
        connect();
        await vi.waitFor(() => expect(registerTool).toHaveBeenCalledTimes(1));
        document.cookie = 'XSRF-TOKEN=new%2Btoken';
        const arguments_ = { post_id: 'post-1', confirm: true };

        const output = await registerTool.mock.calls[0][0].execute(arguments_);

        expect(JSON.parse(output)).toEqual(result);
        expect(fetch).toHaveBeenCalledTimes(2);
        const [url, request] = vi.mocked(fetch).mock.calls[1];
        expect(requestUrl(url).href).toBe(
            'https://shoutrrr.test/webmcp/tools/publish_post',
        );
        expect(request).toMatchObject({
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-XSRF-TOKEN': 'new+token',
            },
        });
        expect(JSON.parse(request?.body as string)).toEqual({
            user_id: 'user-1',
            workspace_id: 'workspace-1',
            arguments: arguments_,
        });
    });

    it.each([undefined, false, 'true', 1])(
        'refuses a consequential action without explicit true confirmation (%s)',
        async (confirm) => {
            const { registerTool } = documentContext();
            vi.mocked(fetch).mockResolvedValueOnce(discovery([publishTool]));
            connect();
            await vi.waitFor(() =>
                expect(registerTool).toHaveBeenCalledTimes(1),
            );

            const output = await registerTool.mock.calls[0][0].execute({
                post_id: 'post-1',
                confirm,
            });

            expect(JSON.parse(output)).toMatchObject({ isError: true });
            expect(output).toContain('explicit human approval');
            expect(fetch).toHaveBeenCalledTimes(1);
        },
    );

    it('preserves structured server validation errors', async () => {
        const { registerTool } = documentContext();
        const result = {
            isError: true,
            content: [
                {
                    type: 'text',
                    text: 'The approved post must contain video media.',
                },
            ],
            structuredContent: { field: 'post_id', code: 'media_required' },
        };
        vi.mocked(fetch)
            .mockResolvedValueOnce(discovery([publishTool]))
            .mockResolvedValueOnce(response(result, 422));
        const onMutationSuccess = vi.fn();
        connect({ onMutationSuccess });
        await vi.waitFor(() => expect(registerTool).toHaveBeenCalledTimes(1));

        const output = await registerTool.mock.calls[0][0].execute({
            post_id: 'post-1',
            confirm: true,
        });

        expect(JSON.parse(output)).toEqual(result);
        expect(onMutationSuccess).not.toHaveBeenCalled();
        expect(fetch).toHaveBeenCalledTimes(2);
    });

    it.each([
        [401, 'session expired'],
        [419, 'session expired'],
        [403, 'permission'],
        [409, 'account or workspace changed'],
        [422, 'arguments are invalid'],
        [500, 'could not confirm'],
    ])(
        'returns an actionable error for HTTP %s without resubmitting',
        async (status, message) => {
            const { registerTool } = documentContext();
            vi.mocked(fetch)
                .mockResolvedValueOnce(discovery())
                .mockResolvedValueOnce(
                    new Response('<html>Private error page</html>', {
                        status: Number(status),
                    }),
                );
            connect();
            await vi.waitFor(() =>
                expect(registerTool).toHaveBeenCalledTimes(1),
            );

            const output = await registerTool.mock.calls[0][0].execute({});

            expect(JSON.parse(output)).toMatchObject({ isError: true });
            expect(output).toContain(message);
            expect(output).not.toContain('Private error page');
            expect(fetch).toHaveBeenCalledTimes(2);
        },
    );

    it('reports Retry-After on rate limiting without retrying the mutation', async () => {
        const { registerTool } = documentContext();
        vi.mocked(fetch)
            .mockResolvedValueOnce(discovery([publishTool]))
            .mockResolvedValueOnce(response({}, 429, { 'Retry-After': '45' }));
        connect();
        await vi.waitFor(() => expect(registerTool).toHaveBeenCalledTimes(1));

        const output = await registerTool.mock.calls[0][0].execute({
            post_id: 'post-1',
            confirm: true,
        });

        expect(JSON.parse(output)).toMatchObject({ isError: true });
        expect(output).toContain('Wait 45 seconds');
        expect(output).toContain('Check the existing post');
        expect(fetch).toHaveBeenCalledTimes(2);
    });

    it('does not retry a mutation after a lost network response', async () => {
        const { registerTool } = documentContext();
        vi.mocked(fetch)
            .mockResolvedValueOnce(discovery([publishTool]))
            .mockRejectedValueOnce(new TypeError('Failed to fetch'));
        const onMutationSuccess = vi.fn();
        connect({ onMutationSuccess });
        await vi.waitFor(() => expect(registerTool).toHaveBeenCalledTimes(1));

        const output = await registerTool.mock.calls[0][0].execute({
            post_id: 'post-1',
            confirm: true,
        });

        expect(JSON.parse(output)).toMatchObject({ isError: true });
        expect(output).toContain('unconfirmed');
        expect(output).toContain('may already have completed');
        expect(output).toContain('No automatic retry was sent');
        expect(fetch).toHaveBeenCalledTimes(2);
        expect(onMutationSuccess).not.toHaveBeenCalled();
    });

    it('notifies the page after a confirmed mutation without repeating the request', async () => {
        const { registerTool } = documentContext();
        vi.mocked(fetch)
            .mockResolvedValueOnce(discovery([publishTool]))
            .mockResolvedValueOnce(
                response({
                    content: [{ type: 'text', text: 'Post queued.' }],
                    isError: false,
                }),
            );
        const onMutationSuccess = vi.fn();
        connect({ onMutationSuccess });
        await vi.waitFor(() => expect(registerTool).toHaveBeenCalledTimes(1));

        await registerTool.mock.calls[0][0].execute({
            post_id: 'post-1',
            confirm: true,
        });

        expect(onMutationSuccess).toHaveBeenCalledTimes(1);
        expect(fetch).toHaveBeenCalledTimes(2);
    });

    it.each([
        { user_id: 'different-user', workspace_id: 'workspace-1' },
        { user_id: 'user-1', workspace_id: 'different-workspace' },
    ])(
        'refuses discovery for a different authenticated identity (%j)',
        async (identity) => {
            const { registerTool } = documentContext();
            vi.mocked(fetch).mockResolvedValueOnce(
                response({ ...identity, tools: [readTool] }),
            );
            const onError = vi.fn();

            connect({ onError });

            await vi.waitFor(() => expect(onError).toHaveBeenCalledTimes(1));
            expect(registerTool).not.toHaveBeenCalled();
            expect(fetch).toHaveBeenCalledTimes(1);
        },
    );

    it('refuses stale tool callbacks after cleanup', async () => {
        const { registerTool } = documentContext();
        vi.mocked(fetch).mockResolvedValueOnce(discovery());
        const cleanup = connect();
        await vi.waitFor(() => expect(registerTool).toHaveBeenCalledTimes(1));
        cleanup();

        const output = await registerTool.mock.calls[0][0].execute({});

        expect(JSON.parse(output)).toMatchObject({ isError: true });
        expect(output).toContain('inactive page');
        expect(fetch).toHaveBeenCalledTimes(1);
    });

    it('refuses a callback when the workspace changed before invocation', async () => {
        const { registerTool } = documentContext();
        vi.mocked(fetch).mockResolvedValueOnce(discovery());
        let current = true;
        connect({ isCurrent: () => current });
        await vi.waitFor(() => expect(registerTool).toHaveBeenCalledTimes(1));
        current = false;

        const output = await registerTool.mock.calls[0][0].execute({});

        expect(JSON.parse(output)).toMatchObject({ isError: true });
        expect(fetch).toHaveBeenCalledTimes(1);
    });

    it('discards an old workspace response and reports uncertain mutation state', async () => {
        const { registerTool } = documentContext();
        const pending = deferred<Response>();
        vi.mocked(fetch)
            .mockResolvedValueOnce(discovery([publishTool]))
            .mockReturnValueOnce(pending.promise);
        let current = true;
        const onMutationSuccess = vi.fn();
        connect({ isCurrent: () => current, onMutationSuccess });
        await vi.waitFor(() => expect(registerTool).toHaveBeenCalledTimes(1));
        const execution = registerTool.mock.calls[0][0].execute({
            post_id: 'post-1',
            confirm: true,
        });
        current = false;
        pending.resolve(
            response({
                content: [
                    { type: 'text', text: 'Previous workspace private data' },
                ],
            }),
        );

        const output = await execution;

        expect(JSON.parse(output)).toMatchObject({ isError: true });
        expect(output).toContain('unconfirmed');
        expect(output).not.toContain('Previous workspace private data');
        expect(onMutationSuccess).not.toHaveBeenCalled();
        expect(fetch).toHaveBeenCalledTimes(2);
    });

    it('aborts an in-flight request when the browser cancels execution', async () => {
        const { registerTool } = documentContext();
        const pending = deferred<Response>();
        vi.mocked(fetch)
            .mockResolvedValueOnce(discovery())
            .mockReturnValueOnce(pending.promise);
        connect();
        await vi.waitFor(() => expect(registerTool).toHaveBeenCalledTimes(1));
        const cancellation = new AbortController();
        const execution = registerTool.mock.calls[0][0].execute(
            {},
            { signal: cancellation.signal },
        );
        const requestSignal = vi.mocked(fetch).mock.calls[1][1]?.signal;

        cancellation.abort();
        expect(requestSignal?.aborted).toBe(true);
        pending.resolve(
            response({
                content: [{ type: 'text', text: 'Cancelled private data' }],
            }),
        );

        expect(await execution).not.toContain('Cancelled private data');
        expect(fetch).toHaveBeenCalledTimes(2);
    });

    it('serializes late legacy registration so cleanup cannot remove a newer registration', async () => {
        const { registerTool, unregisterTool } = legacyContext();
        const pendingRegistration = deferred<void>();
        const events: string[] = [];
        registerTool
            .mockImplementationOnce(() => {
                events.push('register old');
                return pendingRegistration.promise;
            })
            .mockImplementationOnce(() => {
                events.push('register new');
            });
        unregisterTool.mockImplementation(() => {
            events.push('unregister old');
        });
        vi.mocked(fetch)
            .mockResolvedValueOnce(discovery())
            .mockResolvedValueOnce(discovery());
        const oldCleanup = connect();
        await vi.waitFor(() => expect(registerTool).toHaveBeenCalledTimes(1));
        oldCleanup();
        connect();
        expect(fetch).toHaveBeenCalledTimes(1);

        pendingRegistration.resolve();

        await vi.waitFor(() => expect(registerTool).toHaveBeenCalledTimes(2));
        expect(events).toEqual([
            'register old',
            'unregister old',
            'register new',
        ]);
        oldCleanup();
        expect(unregisterTool).toHaveBeenCalledTimes(1);
        expect(fetch).toHaveBeenCalledTimes(2);
    });

    it('cleans up partial legacy registrations when a later registration fails', async () => {
        const { registerTool, unregisterTool } = legacyContext();
        registerTool
            .mockImplementationOnce(() => {})
            .mockRejectedValueOnce(new Error('Registration denied'));
        vi.mocked(fetch).mockResolvedValueOnce(
            discovery([readTool, publishTool]),
        );
        const onError = vi.fn();
        connect({ onError });

        await vi.waitFor(() => expect(onError).toHaveBeenCalledTimes(1));

        expect(unregisterTool).toHaveBeenCalledExactlyOnceWith(readTool.name);
        expect(await registerTool.mock.calls[0][0].execute({})).toContain(
            'inactive page',
        );
        expect(fetch).toHaveBeenCalledTimes(1);
    });

    it('aborts all modern registrations when a later registration fails', async () => {
        const { registerTool } = documentContext();
        registerTool
            .mockResolvedValueOnce(undefined)
            .mockRejectedValueOnce(new Error('Registration denied'));
        vi.mocked(fetch).mockResolvedValueOnce(
            discovery([readTool, publishTool]),
        );
        const onError = vi.fn();
        connect({ onError });

        await vi.waitFor(() => expect(onError).toHaveBeenCalledTimes(1));

        expect(registerTool.mock.calls[0][1].signal.aborted).toBe(true);
        expect(fetch).toHaveBeenCalledTimes(1);
    });

    it('does not contact a cross-origin discovery endpoint', async () => {
        const { registerTool } = documentContext();
        const onError = vi.fn();
        connect({ discoveryUrl: 'https://outside.test/webmcp/tools', onError });

        await vi.waitFor(() => expect(onError).toHaveBeenCalledTimes(1));

        expect(fetch).not.toHaveBeenCalled();
        expect(registerTool).not.toHaveBeenCalled();
    });

    it('does not transmit tool arguments to a cross-origin execution endpoint', async () => {
        const { registerTool } = documentContext();
        vi.mocked(fetch).mockResolvedValueOnce(discovery([publishTool]));
        connect({ executeUrl: () => 'https://outside.test/collect' });
        await vi.waitFor(() => expect(registerTool).toHaveBeenCalledTimes(1));

        const output = await registerTool.mock.calls[0][0].execute({
            post_id: 'private-post',
            confirm: true,
        });

        expect(JSON.parse(output)).toMatchObject({ isError: true });
        expect(fetch).toHaveBeenCalledTimes(1);
    });
});
