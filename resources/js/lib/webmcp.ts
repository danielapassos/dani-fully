import { xsrfHeader } from '@/lib/csrf';

export type WebMcpTool = {
    name: string;
    description: string;
    inputSchema: Record<string, unknown>;
    annotations: {
        readOnlyHint: boolean;
        consequentialHint: boolean;
        untrustedContentHint: boolean;
    };
};

type RegisteredTool = WebMcpTool & {
    execute: (
        input: Record<string, unknown>,
        options?: { signal?: AbortSignal },
    ) => Promise<string>;
};

type ModelContext = {
    registerTool: (
        tool: RegisteredTool,
        options?: { signal: AbortSignal },
    ) => void | Promise<void>;
    unregisterTool?: (name: string) => void;
};

type WebMcpOptions = {
    userId: string;
    workspaceId: string;
    discoveryUrl: string;
    executeUrl: (name: string) => string;
    isCurrent: () => boolean;
    onError?: (message: string) => void;
    onMutationSuccess?: () => void;
};

const registrationQueues = new WeakMap<ModelContext, Promise<void>>();

function modelContext(): { api: ModelContext; legacy: boolean } | null {
    if (typeof document === 'undefined' || typeof navigator === 'undefined') {
        return null;
    }

    const current = (document as Document & { modelContext?: ModelContext })
        .modelContext;
    if (typeof current?.registerTool === 'function') {
        return { api: current, legacy: false };
    }

    const legacy = (navigator as Navigator & { modelContext?: ModelContext })
        .modelContext;
    if (
        typeof legacy?.registerTool === 'function' &&
        typeof legacy.unregisterTool === 'function'
    ) {
        return { api: legacy, legacy: true };
    }

    return null;
}

function errorResult(message: string): string {
    return JSON.stringify({
        content: [{ type: 'text', text: message }],
        isError: true,
    });
}

function sameOriginUrl(value: string): URL {
    const url = new URL(value, window.location.href);
    if (url.origin !== window.location.origin) {
        throw new Error('WebMCP requires a same-origin Shoutrrr endpoint.');
    }

    return url;
}

async function responseResult(response: Response): Promise<string> {
    if (
        response.status === 401 ||
        response.status === 419 ||
        response.redirected
    ) {
        return errorResult(
            'Your Shoutrrr session expired. Sign in and refresh this page before using browser tools.',
        );
    }
    if (response.status === 429) {
        const retryAfter = response.headers.get('Retry-After');
        const delay =
            retryAfter && /^\d+$/.test(retryAfter)
                ? ` Wait ${retryAfter} seconds.`
                : '';

        return errorResult(
            `Shoutrrr is rate limiting requests.${delay} Check the existing post before repeating any mutation.`,
        );
    }
    if (response.status >= 500) {
        return errorResult(
            'Shoutrrr could not confirm the result. Check the existing post or upload before repeating a mutation.',
        );
    }

    const result: unknown = await response.json().catch(() => null);
    if (result && typeof result === 'object' && 'content' in result) {
        return JSON.stringify(result);
    }
    if (!response.ok) {
        const messages: Record<number, string> = {
            403: 'You do not have permission to use this tool in the current workspace.',
            404: 'This browser tool or workspace record is no longer available. Refresh the page.',
            409: 'Your account or workspace changed. Refresh the page before using browser tools.',
            422: 'The tool arguments are invalid. Check the schema and supplied values.',
        };

        return errorResult(
            messages[response.status] ??
                'Shoutrrr could not complete this tool request.',
        );
    }

    return errorResult(
        'Shoutrrr returned an unexpected result. Check the existing record before repeating a mutation.',
    );
}

/** Registers browser tools only for the authenticated page's exact identity. */
export function connectWebMcp(options: WebMcpOptions): () => void {
    const context = modelContext();
    if (!context) {
        return () => {};
    }

    const lifecycle = new AbortController();
    const registeredNames = new Set<string>();
    const current = () => !lifecycle.signal.aborted && options.isCurrent();

    const unregisterLegacy = () => {
        for (const name of registeredNames) {
            context.api.unregisterTool?.(name);
        }
        registeredNames.clear();
    };

    const execute = async (
        tool: WebMcpTool,
        input: Record<string, unknown>,
        execution?: { signal?: AbortSignal },
    ): Promise<string> => {
        if (!current() || execution?.signal?.aborted) {
            return errorResult(
                'This browser tool belongs to an inactive page or cancelled request. Refresh the page.',
            );
        }
        if (!input || typeof input !== 'object' || Array.isArray(input)) {
            return errorResult('Tool arguments must be an object.');
        }
        if (tool.annotations.consequentialHint && input.confirm !== true) {
            return errorResult(
                'Get explicit human approval for this action, then call with confirm set to true.',
            );
        }

        const request = new AbortController();
        const abort = () => request.abort();
        lifecycle.signal.addEventListener('abort', abort, { once: true });
        execution?.signal?.addEventListener('abort', abort, { once: true });
        let sent = false;

        try {
            const url = sameOriginUrl(options.executeUrl(tool.name));
            if (!current() || execution?.signal?.aborted) {
                return errorResult(
                    'The page changed before the tool could run. Refresh the page.',
                );
            }
            sent = true;
            const response = await fetch(url, {
                method: 'POST',
                credentials: 'same-origin',
                redirect: 'error',
                cache: 'no-store',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    ...xsrfHeader(),
                },
                body: JSON.stringify({
                    user_id: options.userId,
                    workspace_id: options.workspaceId,
                    arguments: input,
                }),
                signal: request.signal,
            });
            const result = await responseResult(response);
            if (!current() || request.signal.aborted) {
                throw new Error('The page changed while the tool was running.');
            }
            if (
                !tool.annotations.readOnlyHint &&
                response.ok &&
                !JSON.parse(result).isError
            ) {
                options.onMutationSuccess?.();
            }

            return result;
        } catch {
            return errorResult(
                sent && !tool.annotations.readOnlyHint
                    ? 'The result of this mutation is unconfirmed; it may already have completed. Check the existing post or upload before retrying. No automatic retry was sent.'
                    : 'The tool request was interrupted. Refresh the page and check the current account and workspace.',
            );
        } finally {
            lifecycle.signal.removeEventListener('abort', abort);
            execution?.signal?.removeEventListener('abort', abort);
        }
    };

    const register = async () => {
        if (!current()) {
            return;
        }
        const url = sameOriginUrl(options.discoveryUrl);
        url.searchParams.set('user_id', options.userId);
        url.searchParams.set('workspace_id', options.workspaceId);
        const response = await fetch(url, {
            credentials: 'same-origin',
            redirect: 'error',
            cache: 'no-store',
            headers: { Accept: 'application/json' },
            signal: lifecycle.signal,
        });
        if (!response.ok) {
            throw new Error(
                'Browser tools could not load. Refresh Shoutrrr to check your session.',
            );
        }
        const catalog = (await response.json()) as {
            user_id: string;
            workspace_id: string;
            tools: WebMcpTool[];
        };
        if (!current()) {
            return;
        }
        if (
            catalog.user_id !== options.userId ||
            catalog.workspace_id !== options.workspaceId ||
            !Array.isArray(catalog.tools)
        ) {
            throw new Error(
                'Browser tools did not match the current account and workspace.',
            );
        }

        for (const tool of catalog.tools) {
            if (!current()) {
                break;
            }
            await context.api.registerTool(
                {
                    ...tool,
                    execute: (input, execution) =>
                        execute(tool, input, execution),
                },
                { signal: lifecycle.signal },
            );
            registeredNames.add(tool.name);
            if (!current() && context.legacy) {
                unregisterLegacy();
            }
        }
    };

    // Serialize registration across StrictMode and rapid workspace changes. An old
    // preview browser's late register must not remove a newer tool with the same name.
    const previous = registrationQueues.get(context.api) ?? Promise.resolve();
    const pending = previous.then(register).catch(() => {
        if (current()) {
            options.onError?.(
                'WebMCP tools could not load. Refresh Shoutrrr to try again.',
            );
        }
        lifecycle.abort();
        if (context.legacy) {
            unregisterLegacy();
        }
    });
    registrationQueues.set(context.api, pending);

    return () => {
        lifecycle.abort();
        if (context.legacy) {
            unregisterLegacy();
        }
    };
}
