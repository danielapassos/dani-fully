import { act, createElement, useState } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { YouTubePublishingControls } from '@/components/compose/youtube-publishing-controls';
import { youtubeOptionsComplete } from '@/lib/compose/youtube';
import type { Account, YouTubePostOptions } from '@/types/compose';

const account: Account = {
    id: 'youtube-account',
    platform: 'youtube',
    handle: '@creator',
    display_name: 'Creator',
    avatar_url: null,
    max_text_length: 5000,
    x_premium: false,
};
let root: Root | null = null;
let container: HTMLDivElement | null = null;

afterEach(() => {
    act(() => root?.unmount());
    container?.remove();
    root = null;
    container = null;
});

function renderControls(initial?: YouTubePostOptions) {
    const changed = vi.fn();
    function Harness() {
        const [options, setOptions] = useState(initial);
        return createElement(YouTubePublishingControls, {
            account,
            options,
            onChange: (next: YouTubePostOptions) => {
                changed(next);
                setOptions(next);
            },
        });
    }
    container = document.createElement('div');
    document.body.append(container);
    root = createRoot(container);
    act(() => root?.render(createElement(Harness)));
    return { view: container, changed };
}

function choose(view: HTMLElement, text: string, value: string) {
    const label = Array.from(view.querySelectorAll('label')).find((element) =>
        element.textContent?.startsWith(text),
    );
    const select = label?.querySelector('select');
    if (!select) throw new Error(`Missing select: ${text}`);
    act(() => {
        select.value = value;
        select.dispatchEvent(new Event('change', { bubbles: true }));
    });
}

describe('YouTube publishing controls', () => {
    it('allows incremental choices without silently assigning disclosure answers', () => {
        const { view, changed } = renderControls();
        expect(
            Array.from(view.querySelectorAll('select'))
                .slice(0, 5)
                .map((select) => select.value),
        ).toEqual(['', '', '', '', '']);
        choose(view, 'Visibility', 'public');
        expect(changed).toHaveBeenLastCalledWith({
            category_id: '22',
            notify_subscribers: false,
            privacy_status: 'public',
        });
        expect(youtubeOptionsComplete(changed.mock.lastCall![0])).toBe(false);
        expect(view.textContent).toContain(
            'YouTube may restrict uploads from an unverified app to private',
        );
    });

    it('preserves every explicit selection including false declarations', () => {
        const { view, changed } = renderControls();
        choose(view, 'Visibility', 'unlisted');
        choose(view, 'Video format', 'short');
        choose(view, 'Is this video made for kids?', 'false');
        choose(view, 'Does it contain realistic altered', 'true');
        choose(view, 'Does it include a paid promotion?', 'false');
        choose(view, 'Category', '19');
        act(() =>
            view
                .querySelector<HTMLInputElement>('input[type="checkbox"]')!
                .click(),
        );
        expect(changed).toHaveBeenLastCalledWith({
            privacy_status: 'unlisted',
            format_intent: 'short',
            category_id: '19',
            made_for_kids: false,
            contains_synthetic_media: true,
            has_paid_product_placement: false,
            notify_subscribers: true,
        });
        expect(youtubeOptionsComplete(changed.mock.lastCall![0])).toBe(true);
        expect(view.textContent).toContain(
            'This upload will not be a public channel post',
        );
        expect(view.textContent).toContain(
            'Selecting Short does not crop or re-edit your file',
        );
        choose(view, 'Does it include a paid promotion?', '');
        expect(youtubeOptionsComplete(changed.mock.lastCall![0])).toBe(false);
    });

    it('renders stored choices without changing their category or declarations', () => {
        const { view, changed } = renderControls({
            privacy_status: 'private',
            format_intent: 'video',
            category_id: '28',
            made_for_kids: true,
            contains_synthetic_media: false,
            has_paid_product_placement: true,
            notify_subscribers: false,
        });
        expect(
            Array.from(view.querySelectorAll('select')).map(
                (select) => select.value,
            ),
        ).toEqual(['private', 'video', 'true', 'false', 'true', '28']);
        expect(changed).not.toHaveBeenCalled();
    });

    it('uses the displayed category and notification defaults when continuing a partial saved form', () => {
        const { view, changed } = renderControls({ privacy_status: 'public' });
        choose(view, 'Video format', 'video');
        expect(changed).toHaveBeenLastCalledWith({
            privacy_status: 'public',
            format_intent: 'video',
            category_id: '22',
            notify_subscribers: false,
        });
        expect(changed.mock.lastCall![0].made_for_kids).toBeUndefined();
        expect(
            changed.mock.lastCall![0].contains_synthetic_media,
        ).toBeUndefined();
        expect(
            changed.mock.lastCall![0].has_paid_product_placement,
        ).toBeUndefined();
    });
});
