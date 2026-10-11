/** @vitest-environment jsdom */

import { act, createElement } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import {
    afterEach,
    beforeAll,
    beforeEach,
    describe,
    expect,
    it,
    vi,
} from 'vitest';

import type { PlatformPreview } from '@/lib/compose/platform-preview';
import type { MediaView, PostFormat } from '@/types/compose';

import { InstagramPreview } from '../instagram-preview';
import { imageMedia, makePreview, videoMedia } from './fixtures';

let root: Root | null = null;
let container: HTMLDivElement | null = null;
let measuringImages: HTMLImageElement[] = [];

beforeEach(() => {
    measuringImages = [];
    vi.spyOn(window, 'Image').mockImplementation(function () {
        const image = document.createElement('img');
        measuringImages.push(image);

        return image;
    });
});

beforeAll(() => {
    globalThis.ResizeObserver = class {
        observe() {}
        unobserve() {}
        disconnect() {}
    };
    globalThis.PointerEvent = MouseEvent as unknown as typeof PointerEvent;
});

function swipe(el: HTMLElement, fromX: number, toX: number): void {
    act(() => {
        el.dispatchEvent(
            new MouseEvent('pointerdown', { clientX: fromX, bubbles: true }),
        );
        el.dispatchEvent(
            new MouseEvent('pointerup', { clientX: toX, bubbles: true }),
        );
    });
}

afterEach(() => {
    if (root) {
        act(() => root?.unmount());
    }
    container?.remove();
    root = null;
    container = null;
    vi.restoreAllMocks();
});

function loadedImageDimensions(
    image: HTMLImageElement,
    width: number,
    height: number,
): void {
    Object.defineProperties(image, {
        naturalWidth: { value: width, configurable: true },
        naturalHeight: { value: height, configurable: true },
    });
    act(() => image.dispatchEvent(new Event('load')));
}

function mediaFrame(el: HTMLElement): HTMLElement {
    return el.querySelector<HTMLElement>(
        '[aria-label="Instagram media preview"]',
    )!;
}

function rerender(preview: PlatformPreview): void {
    act(() => root?.render(createElement(InstagramPreview, { preview })));
}

function mount(preview: PlatformPreview): HTMLDivElement {
    container = document.createElement('div');
    document.body.append(container);
    root = createRoot(container);
    act(() => {
        root?.render(createElement(InstagramPreview, { preview }));
    });

    return container;
}

function renderFeed(count: number): HTMLDivElement {
    const media = Array.from({ length: count }, (_, i) => imageMedia(`m${i}`));

    return mount(makePreview('instagram', media));
}

function renderFormat(
    media: MediaView[],
    format: PostFormat,
    caption?: string,
): HTMLDivElement {
    return mount(makePreview('instagram', media, caption, format));
}

describe('InstagramPreview feed', () => {
    it('shows the carousel counter and steps through slides', () => {
        const el = renderFeed(3);

        expect(el.textContent).toContain('1/3');

        const next = el.querySelector<HTMLButtonElement>(
            '[aria-label="Next photo"]',
        );
        act(() => next?.click());
        expect(el.textContent).toContain('2/3');
    });

    it('advances and rewinds the carousel with a swipe', () => {
        const el = renderFeed(3);
        const carousel = mediaFrame(el);
        expect(carousel).not.toBeNull();

        swipe(carousel!, 220, 60);
        expect(el.textContent).toContain('2/3');

        swipe(carousel!, 60, 220);
        expect(el.textContent).toContain('1/3');
    });

    it('ignores a tap that does not cross the swipe threshold', () => {
        const el = renderFeed(3);
        const carousel = mediaFrame(el);

        swipe(carousel!, 120, 110);
        expect(el.textContent).toContain('1/3');
    });

    it('jumps straight to a slide when its dot is clicked', () => {
        const el = renderFeed(3);
        const thirdDot = el.querySelector<HTMLButtonElement>(
            '[aria-label="Go to photo 3"]',
        );
        act(() => thirdDot?.click());
        expect(el.textContent).toContain('3/3');
    });

    it('renders no carousel chrome for a single photo', () => {
        const el = renderFeed(1);

        expect(el.querySelector('[aria-label="Next photo"]')).toBeNull();
        expect(el.textContent).not.toContain('1/1');
    });

    it.each([
        ['portrait', 1080, 1350],
        ['square', 1080, 1080],
        ['landscape', 1200, 800],
    ] as const)('keeps a %s source uncropped', (_label, width, height) => {
        const media = { ...imageMedia('source'), width, height };
        const el = renderFormat([media], 'feed');
        const image = el.querySelector<HTMLImageElement>(
            'img[src*="source.jpg"]',
        );

        expect(mediaFrame(el).style.aspectRatio).toBe(String(width / height));
        expect(image?.classList.contains('object-contain')).toBe(true);
        expect(image?.getAttribute('src')).toBe(media.url);
        expect(measuringImages).toHaveLength(0);
        expect(el.textContent).toContain(
            'Source preview. Instagram may adjust framing.',
        );
    });

    it('keeps the first slide ratio while containing differently sized slides', () => {
        const el = renderFormat(
            [
                { ...imageMedia('portrait'), width: 1080, height: 1350 },
                { ...imageMedia('landscape'), width: 1200, height: 800 },
            ],
            'feed',
        );
        act(() =>
            el
                .querySelector<HTMLButtonElement>('[aria-label="Next photo"]')
                ?.click(),
        );

        expect(el.textContent).toContain('2/2');
        expect(mediaFrame(el).style.aspectRatio).toBe('0.8');
        expect(
            el
                .querySelector('img[src*="landscape.jpg"]')
                ?.classList.contains('object-contain'),
        ).toBe(true);
    });

    it('uses loaded source dimensions even after navigating past the first slide', () => {
        const el = renderFeed(2);
        expect(mediaFrame(el).style.aspectRatio).toBe('1');
        act(() =>
            el
                .querySelector<HTMLButtonElement>('[aria-label="Next photo"]')
                ?.click(),
        );
        loadedImageDimensions(measuringImages[0], 1080, 1350);

        expect(el.textContent).toContain('2/2');
        expect(mediaFrame(el).style.aspectRatio).toBe('0.8');
        expect(el.querySelector('img[src*="m1.jpg"]')).not.toBeNull();
    });

    it('reads the intrinsic ratio when an older image is already cached', () => {
        vi.mocked(window.Image).mockImplementationOnce(function () {
            const image = document.createElement('img');
            Object.defineProperties(image, {
                complete: { value: true },
                naturalWidth: { value: 1080 },
                naturalHeight: { value: 1350 },
            });

            return image;
        });

        const el = renderFeed(1);

        expect(mediaFrame(el).style.aspectRatio).toBe('0.8');
    });

    it('resets the frame and slide when the first image is reordered', () => {
        const portrait = {
            ...imageMedia('portrait'),
            width: 1080,
            height: 1350,
        };
        const square = { ...imageMedia('square'), width: 1080, height: 1080 };
        const el = renderFormat([portrait, square], 'feed');
        act(() =>
            el
                .querySelector<HTMLButtonElement>('[aria-label="Next photo"]')
                ?.click(),
        );

        rerender(makePreview('instagram', [square, portrait]));

        expect(mediaFrame(el).style.aspectRatio).toBe('1');
        expect(el.textContent).toContain('1/2');
        expect(el.querySelector('img[src*="square.jpg"]')).not.toBeNull();
    });

    it('ignores a stale load when the first source URL changes', () => {
        const original = imageMedia('first');
        const el = renderFormat([original], 'feed');
        const originalProbe = measuringImages[0];
        loadedImageDimensions(originalProbe, 1080, 1350);
        expect(mediaFrame(el).style.aspectRatio).toBe('0.8');

        rerender(
            makePreview('instagram', [
                {
                    ...original,
                    url: 'https://cdn.example.test/replacement.jpg',
                },
            ]),
        );
        loadedImageDimensions(originalProbe, 1200, 800);
        expect(mediaFrame(el).style.aspectRatio).toBe('1');

        loadedImageDimensions(measuringImages[1], 1200, 800);
        expect(mediaFrame(el).style.aspectRatio).toBe('1.5');
    });

    it('keeps the full source visible when dimensions cannot be loaded', () => {
        const el = renderFormat(
            [{ ...imageMedia('unknown'), width: 1080, height: 0 }],
            'feed',
        );
        loadedImageDimensions(measuringImages[0], 0, 0);
        act(() => measuringImages[0].dispatchEvent(new Event('error')));

        expect(mediaFrame(el).style.aspectRatio).toBe('1');
        expect(
            el
                .querySelector('img[src*="unknown.jpg"]')
                ?.classList.contains('object-contain'),
        ).toBe(true);
    });

    it('prompts for media when the post has none', () => {
        const el = renderFeed(0);

        expect(el.textContent).toContain(
            'Instagram posts always include media',
        );
    });

    it('links hashtags in the caption', () => {
        const el = renderFormat(
            [imageMedia('m0')],
            'feed',
            'Golden hour #harbor',
        );

        const link = el.querySelector<HTMLAnchorElement>(
            'a[href*="explore/tags/harbor"]',
        );
        expect(link?.textContent).toBe('#harbor');
    });
});

describe('InstagramPreview story', () => {
    it('shows only the first attachment with no carousel or caption', () => {
        const el = renderFormat(
            [imageMedia('m0'), videoMedia('m1'), imageMedia('m2')],
            'story',
            'Golden hour #harbor',
        );

        // A Story publishes just the first media item — no carousel, no
        // second segment, and no caption.
        expect(el.querySelector('img[src*="m0"]')).not.toBeNull();
        expect(el.querySelector('img[src*="m2"]')).toBeNull();
        expect(el.querySelector('video')).toBeNull();
        expect(el.querySelector('[aria-label="Next story"]')).toBeNull();
        expect(el.querySelector('[aria-label="Next photo"]')).toBeNull();
        expect(el.textContent).not.toContain('#harbor');
        expect(el.textContent).not.toContain('View all comments');
    });

    it('plays a video story when the first attachment is a video', () => {
        const el = renderFormat([videoMedia('v0')], 'story');

        expect(el.querySelector('video')).not.toBeNull();
    });

    it('prompts for media when the story has none', () => {
        const el = renderFormat([], 'story');

        expect(el.textContent).toContain('preview your story');
    });
});

describe('InstagramPreview reels', () => {
    it('shows trial configuration in the preview without a publication claim', () => {
        const el = mount({
            ...makePreview(
                'instagram',
                [videoMedia('trial')],
                'Caption',
                'reels',
            ),
            instagramOptions: {
                cover_media_id: null,
                trial_params: { graduation_strategy: 'MANUAL' },
            },
        });
        expect(el.textContent).toContain('Trial Reel configured');
        expect(el.textContent).toContain('Share with followers manually');
        expect(el.textContent).not.toContain('Published');
    });
    it('plays the first video and keeps the caption', () => {
        const el = renderFormat(
            [imageMedia('m0'), videoMedia('v1')],
            'reels',
            'Golden hour #harbor',
        );

        expect(el.querySelector('video')).not.toBeNull();
        const link = el.querySelector<HTMLAnchorElement>(
            'a[href*="explore/tags/harbor"]',
        );
        expect(link?.textContent).toBe('#harbor');
    });

    it('prompts for a video when the reel has none', () => {
        const el = renderFormat([imageMedia('m0')], 'reels');

        expect(el.querySelector('video')).toBeNull();
        expect(el.textContent).toContain('Reels are single-video posts');
    });
});
