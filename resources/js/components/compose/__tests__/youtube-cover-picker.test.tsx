import { act, fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { YouTubeCoverPicker } from '@/components/compose/youtube-cover-picker';
import type { Account, MediaView } from '@/types/compose';

const mocks = vi.hoisted(() => ({
    get: vi.fn(),
    post: vi.fn(),
    transform: vi.fn(),
}));
vi.mock('@inertiajs/react', () => ({ useHttp: () => mocks }));
vi.mock('@/actions/App/Http/Controllers/Posts/YouTubeCoverController', () => ({
    default: {
        index: (id: string, options?: { query: Record<string, string> }) => ({
            url: `/posts/${id}/youtube-covers?${new URLSearchParams(options?.query)}`,
        }),
    },
}));
vi.mock('@/actions/App/Http/Controllers/Posts/PostMediaController', () => ({
    default: { store: (id: string) => ({ url: `/posts/${id}/media` }) },
}));

const account: Account = {
    id: 'youtube-account',
    platform: 'youtube',
    handle: '@creator',
    display_name: null,
    avatar_url: null,
    max_text_length: 5000,
    x_premium: false,
};
const cover: MediaView = {
    id: 'cover',
    url: 'https://media.test/cover.jpg',
    mime: 'image/jpeg',
    kind: 'image',
    alt_text: 'Mountain cover',
    duration_seconds: null,
    position: 0,
    edit_settings: null,
    source_url: null,
    edit_url: '/media/cover',
    source_edit_url: null,
};
const defaults = {
    account,
    postId: 'post',
    thumbnailMediaId: undefined,
    formatIntent: undefined,
    canChoose: true,
    onChange: vi.fn(),
    onUploadingChange: vi.fn(),
};

beforeEach(() => {
    vi.clearAllMocks();
    mocks.get
        .mockReset()
        .mockResolvedValue({ media: [cover], next_cursor: null });
    mocks.post.mockReset().mockResolvedValue({ media: cover });
});

describe('YouTube cover picker', () => {
    it.each([
        {
            formatIntent: 'short' as const,
            previewClass: 'h-32',
            galleryClass: 'aspect-[9/16]',
        },
        {
            formatIntent: 'video' as const,
            previewClass: 'h-20',
            galleryClass: 'aspect-video',
        },
    ])(
        'previews $formatIntent covers without cropping the selected image',
        async ({ formatIntent, previewClass, galleryClass }) => {
            await act(async () => {
                render(
                    <YouTubeCoverPicker
                        {...defaults}
                        formatIntent={formatIntent}
                        thumbnailMediaId="cover"
                    />,
                );
            });
            expect(
                screen.getByRole('img', { name: 'Mountain cover' }),
            ).toHaveClass(previewClass, 'object-contain');
            await act(async () => {
                fireEvent.click(
                    screen.getByRole('button', {
                        name: 'Choose existing cover',
                    }),
                );
            });
            expect(
                screen
                    .getByRole('button', { name: 'Use Mountain cover' })
                    .querySelector('img'),
            ).toHaveClass(galleryClass, 'object-contain');
        },
    );

    it('requires a saved draft without choosing a cover automatically', () => {
        render(<YouTubeCoverPicker {...defaults} postId={null} />);
        expect(
            screen.getByText('Save this draft to choose an existing cover.'),
        ).toBeInTheDocument();
        expect(mocks.get).not.toHaveBeenCalled();
        expect(defaults.onChange).not.toHaveBeenCalled();
        expect(
            screen.queryByRole('button', { name: 'Upload cover' }),
        ).not.toBeInTheDocument();
    });

    it('explains channel eligibility and private-on-failure behavior while keeping a selected cover removable', async () => {
        await act(async () => {
            render(
                <YouTubeCoverPicker
                    {...defaults}
                    thumbnailMediaId="cover"
                    canChoose={false}
                />,
            );
        });
        expect(
            screen.getByText(/YouTube checks whether your channel/),
        ).toHaveTextContent(
            'If applying the cover fails, the video stays private',
        );
        expect(mocks.get.mock.calls[0][0]).toContain(
            '/youtube-covers?selected=cover',
        );
        expect(
            screen.getByRole('img', { name: 'Mountain cover' }),
        ).toHaveAttribute('src', cover.url);
        expect(
            screen.getByRole('button', { name: 'Upload cover' }),
        ).toBeDisabled();
        fireEvent.click(screen.getByRole('button', { name: 'Remove cover' }));
        expect(defaults.onChange).toHaveBeenCalledExactlyOnceWith(null);
    });

    it('paginates choices and changes only the selected reference', async () => {
        mocks.get
            .mockResolvedValueOnce({ media: [cover], next_cursor: 'next' })
            .mockResolvedValueOnce({
                media: [
                    cover,
                    { ...cover, id: 'second', alt_text: 'Second cover' },
                ],
                next_cursor: null,
            });
        render(<YouTubeCoverPicker {...defaults} />);
        await act(async () => {
            fireEvent.click(
                screen.getByRole('button', { name: 'Choose existing cover' }),
            );
        });
        await act(async () => {
            fireEvent.click(
                screen.getByRole('button', { name: 'Load more covers' }),
            );
        });
        expect(mocks.get.mock.calls[1][0]).toContain('cursor=next');
        expect(
            screen.getAllByRole('button', { name: 'Use Mountain cover' }),
        ).toHaveLength(1);
        fireEvent.click(
            screen.getByRole('button', { name: 'Use Second cover' }),
        );
        expect(defaults.onChange).toHaveBeenCalledExactlyOnceWith('second');
        expect(mocks.post).not.toHaveBeenCalled();
    });

    it('uploads the original File and blocks publishing until it finishes', async () => {
        let finish!: (value: { media: MediaView }) => void;
        mocks.post.mockReturnValueOnce(
            new Promise((resolve) => {
                finish = resolve;
            }),
        );
        render(<YouTubeCoverPicker {...defaults} />);
        const file = new File(['original bytes'], 'cover.jpg', {
            type: 'image/jpeg',
        });
        await act(async () => {
            fireEvent.change(
                screen.getByLabelText('Upload YouTube cover image'),
                { target: { files: [file] } },
            );
        });
        expect(mocks.transform.mock.calls[0][0]().file).toBe(file);
        expect(mocks.post.mock.calls[0][0]).toBe('/posts/post/media');
        expect(defaults.onUploadingChange).toHaveBeenLastCalledWith(true);
        expect(defaults.onChange).not.toHaveBeenCalled();
        await act(async () => {
            finish({ media: cover });
        });
        expect(defaults.onChange).toHaveBeenCalledExactlyOnceWith('cover');
        expect(defaults.onUploadingChange).toHaveBeenLastCalledWith(false);
    });

    it('releases the upload guard and ignores a response after moving to another draft', async () => {
        let finish!: (value: { media: MediaView }) => void;
        mocks.post.mockReturnValueOnce(
            new Promise((resolve) => {
                finish = resolve;
            }),
        );
        const view = render(<YouTubeCoverPicker {...defaults} />);
        await act(async () => {
            fireEvent.change(
                screen.getByLabelText('Upload YouTube cover image'),
                {
                    target: {
                        files: [
                            new File(['bytes'], 'cover.png', {
                                type: 'image/png',
                            }),
                        ],
                    },
                },
            );
        });
        view.rerender(
            <YouTubeCoverPicker {...defaults} postId="another-post" />,
        );
        expect(defaults.onUploadingChange).toHaveBeenLastCalledWith(false);
        await act(async () => {
            finish({ media: cover });
        });
        expect(defaults.onChange).not.toHaveBeenCalled();
        expect(screen.queryByRole('img')).not.toBeInTheDocument();
    });

    it('rejects unsupported files without uploading them', async () => {
        render(<YouTubeCoverPicker {...defaults} />);
        await act(async () => {
            fireEvent.change(
                screen.getByLabelText('Upload YouTube cover image'),
                {
                    target: {
                        files: [
                            new File(['gif'], 'cover.gif', {
                                type: 'image/gif',
                            }),
                        ],
                    },
                },
            );
        });
        expect(screen.getByRole('alert')).toHaveTextContent('JPEG or PNG');
        expect(mocks.post).not.toHaveBeenCalled();
        expect(defaults.onChange).not.toHaveBeenCalled();
    });
});
