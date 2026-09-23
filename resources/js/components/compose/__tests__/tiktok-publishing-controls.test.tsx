import { act, createElement, useState, type ReactNode } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { TikTokPublishingControls } from '@/components/compose/tiktok-publishing-controls';
import type {
    Account,
    TikTokCreatorInfo,
    TikTokPostOptions,
} from '@/types/compose';

const mocks = vi.hoisted(() => ({ get: vi.fn() }));
vi.mock('@inertiajs/react', () => ({
    useHttp: () => ({ get: mocks.get }),
    Link: ({ href, children }: { href: string; children: ReactNode }) =>
        createElement('a', { href }, children),
}));
vi.mock(
    '@/actions/App/Http/Controllers/ConnectedAccounts/TikTokCreatorInfoController',
    () => ({
        default: (id: string) => ({
            url: `/accounts/${id}/tiktok/creator-info`,
        }),
    }),
);

const account: Account = {
    id: 'tiktok-account',
    platform: 'tiktok',
    handle: '@saved-name',
    display_name: 'Saved name',
    avatar_url: null,
    max_text_length: 2200,
    x_premium: false,
    tiktok_direct_post_enabled: true,
};
const creator: TikTokCreatorInfo = {
    creator_username: 'current_name',
    creator_nickname: 'Current name',
    creator_avatar_url: null,
    privacy_level_options: ['PUBLIC_TO_EVERYONE', 'SELF_ONLY'],
    comment_disabled: false,
    duet_disabled: true,
    stitch_disabled: false,
    max_video_post_duration_sec: 60,
};

let root: Root | null = null;
let container: HTMLDivElement | null = null;

beforeEach(() => {
    mocks.get.mockReset();
    mocks.get.mockImplementation(
        (
            _url: string,
            callbacks: {
                onSuccess: (data: { creator: TikTokCreatorInfo }) => void;
            },
        ) => {
            callbacks.onSuccess({ creator });
            return Promise.resolve();
        },
    );
});

afterEach(() => {
    act(() => root?.unmount());
    container?.remove();
    root = null;
    container = null;
});

function renderControls(selectedAccount: Account = account): HTMLDivElement {
    function Harness() {
        const [options, setOptions] = useState<TikTokPostOptions>();
        return createElement(TikTokPublishingControls, {
            account: selectedAccount,
            options,
            durationSeconds: 12,
            onChange: setOptions,
            onCreatorInfo: () => undefined,
        });
    }
    container = document.createElement('div');
    document.body.append(container);
    root = createRoot(container);
    act(() => root?.render(createElement(Harness)));
    return container;
}

function checkbox(view: HTMLElement, label: string): HTMLInputElement {
    const element = Array.from(view.querySelectorAll('label')).find(
        (candidate) => candidate.textContent?.trim().startsWith(label),
    );
    const input = element?.querySelector<HTMLInputElement>(
        'input[type="checkbox"]',
    );
    if (!input) throw new Error(`Missing checkbox: ${label}`);
    return input;
}

describe('TikTok publishing controls', () => {
    it('shows the current creator and requires deliberate privacy and interaction choices', () => {
        const view = renderControls();
        expect(view.textContent).toContain('Current name · @current_name');
        expect(view.querySelector('select')?.value).toBe('');
        expect(
            Array.from(view.querySelectorAll('option')).map(
                (option) => option.value,
            ),
        ).toEqual(['', 'PUBLIC_TO_EVERYONE', 'SELF_ONLY']);
        expect(checkbox(view, 'Comments').checked).toBe(false);
        expect(checkbox(view, 'Duet').checked).toBe(false);
        expect(checkbox(view, 'Duet').disabled).toBe(true);
        expect(checkbox(view, 'Stitch').checked).toBe(false);
        expect(checkbox(view, 'Disclose commercial content').checked).toBe(
            false,
        );
        expect(checkbox(view, 'By posting').checked).toBe(false);
    });

    it('requires a disclosure choice and prevents private branded content', () => {
        const view = renderControls();
        act(() => checkbox(view, 'Disclose commercial content').click());
        expect(view.textContent).toContain(
            'Indicate whether commercial content promotes your brand',
        );
        const privacy = view.querySelector('select')!;
        act(() => {
            privacy.value = 'SELF_ONLY';
            privacy.dispatchEvent(new Event('change', { bubbles: true }));
        });
        expect(checkbox(view, 'Branded content').disabled).toBe(true);
        act(() => {
            privacy.value = 'PUBLIC_TO_EVERYONE';
            privacy.dispatchEvent(new Event('change', { bubbles: true }));
        });
        act(() => checkbox(view, 'Branded content').click());
        expect(view.textContent).toContain('Paid partnership');
        expect(
            view
                .querySelector('option[value="SELF_ONLY"]')
                ?.hasAttribute('disabled'),
        ).toBe(true);
        expect(checkbox(view, 'By posting').checked).toBe(false);
        expect(view.querySelector('a[href*="bc-policy"]')).not.toBeNull();
    });

    it('displays the safe provider restriction and blocks controls when the query fails', () => {
        mocks.get.mockImplementation(
            (
                _url: string,
                callbacks: {
                    onError: (errors: Record<string, string>) => void;
                },
            ) => {
                callbacks.onError({
                    creator:
                        'TikTok has reached its posting limit for now. Try again later.',
                });
                return Promise.resolve();
            },
        );
        const view = renderControls();
        expect(view.querySelector('[role="alert"]')?.textContent).toContain(
            'posting limit',
        );
        expect(view.querySelector('select')).toBeNull();
        expect(view.querySelector('input[type="checkbox"]')).toBeNull();
    });
});

it('shows the exact setup blocker without fetching or offering a futile refresh', () => {
    const reason =
        'TikTok Accounts API app approval is required before public publishing.';
    const view = renderControls({
        ...account,
        publishing_ready: false,
        publishing_unavailable_reason: reason,
    });
    expect(view.querySelector('[role="alert"]')?.textContent).toBe(reason);
    expect(view.textContent).not.toContain('Refresh settings');
    expect(view.textContent).not.toContain('could not load');
    expect(view.querySelector('a')?.getAttribute('href')).toBe('/accounts');
    expect(view.querySelector('select')).toBeNull();
    expect(mocks.get).not.toHaveBeenCalled();
});

it('starts loading when account setup becomes ready without changing saved options', () => {
    const onChange = vi.fn();
    const onCreatorInfo = vi.fn();
    const options: TikTokPostOptions = {
        privacy_level: 'PUBLIC_TO_EVERYONE',
        disable_comment: false,
        disable_duet: true,
        disable_stitch: true,
        commercial_content: false,
        brand_organic_toggle: false,
        brand_content_toggle: false,
        is_aigc: false,
        music_usage_confirmed: true,
        branded_content_policy_confirmed: false,
    };
    container = document.createElement('div');
    document.body.append(container);
    root = createRoot(container);
    const props = { options, durationSeconds: 12, onChange, onCreatorInfo };
    act(() =>
        root?.render(
            createElement(TikTokPublishingControls, {
                ...props,
                account: { ...account, publishing_ready: false },
            }),
        ),
    );
    expect(mocks.get).not.toHaveBeenCalled();
    expect(onChange).not.toHaveBeenCalled();
    act(() =>
        root?.render(
            createElement(TikTokPublishingControls, {
                ...props,
                account: { ...account, publishing_ready: true },
            }),
        ),
    );
    expect(mocks.get).toHaveBeenCalledTimes(1);
    expect(container.querySelector('select')?.value).toBe('PUBLIC_TO_EVERYONE');
    expect(onChange).not.toHaveBeenCalled();
});

it('ignores an in-flight creator response when the selected destination becomes blocked', () => {
    let respond: ((data: { creator: TikTokCreatorInfo }) => void) | undefined;
    mocks.get.mockImplementation(
        (_url: string, callbacks: { onSuccess: typeof respond }) => {
            respond = callbacks.onSuccess;
            return Promise.resolve();
        },
    );
    const onCreatorInfo = vi.fn();
    const onChange = vi.fn();
    container = document.createElement('div');
    document.body.append(container);
    root = createRoot(container);
    const props = {
        options: undefined,
        durationSeconds: 12,
        onChange,
        onCreatorInfo,
    };
    act(() =>
        root?.render(
            createElement(TikTokPublishingControls, { ...props, account }),
        ),
    );
    act(() =>
        root?.render(
            createElement(TikTokPublishingControls, {
                ...props,
                account: {
                    ...account,
                    id: 'blocked-destination',
                    publishing_ready: false,
                    publishing_unavailable_reason: 'App approval required.',
                },
            }),
        ),
    );
    act(() => respond?.({ creator }));
    expect(container.textContent).toContain('App approval required.');
    expect(container.querySelector('select')).toBeNull();
    expect(onCreatorInfo).not.toHaveBeenCalledWith(creator);
    expect(mocks.get).toHaveBeenCalledTimes(1);
});
