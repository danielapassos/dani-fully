import { act, fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { InstagramCoverPicker } from '@/components/compose/instagram-cover-picker';
import type { Account, MediaView } from '@/types/compose';

const mocks = vi.hoisted(() => ({
    get: vi.fn(),
    post: vi.fn(),
    transform: vi.fn(),
}));
vi.mock('@inertiajs/react', () => ({ useHttp: () => mocks }));
vi.mock(
    '@/actions/App/Http/Controllers/Posts/InstagramCoverController',
    () => ({
        default: {
            index: (
                id: string,
                options?: { query: Record<string, string> },
            ) => ({
                url: `/posts/${id}/instagram-covers?${new URLSearchParams(options?.query)}`,
            }),
        },
    }),
);
vi.mock('@/actions/App/Http/Controllers/Posts/PostMediaController', () => ({
    default: { store: (id: string) => ({ url: `/posts/${id}/media` }) },
}));

const account: Account = {
    id: 'instagram-account',
    platform: 'instagram',
    handle: '@creator',
    display_name: null,
    avatar_url: null,
    max_text_length: 2200,
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
    options: undefined,
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

describe('Instagram cover picker', () => {
    it('requires a saved draft and never chooses content automatically', () => {
        render(<InstagramCoverPicker {...defaults} postId={null} />);
        expect(
            screen.getByText('Save this draft to choose an existing cover.'),
        ).toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'Upload cover' }),
        ).not.toBeInTheDocument();
        expect(mocks.get).not.toHaveBeenCalled();
        expect(defaults.onChange).not.toHaveBeenCalled();
    });

    it('loads choices on request and only changes the cover reference on selection', async () => {
        render(<InstagramCoverPicker {...defaults} />);
        expect(mocks.get).not.toHaveBeenCalled();
        await act(async () => {
            fireEvent.click(
                screen.getByRole('button', { name: 'Choose existing cover' }),
            );
        });
        expect(defaults.onChange).not.toHaveBeenCalled();
        fireEvent.click(
            screen.getByRole('button', { name: 'Use Mountain cover' }),
        );
        expect(defaults.onChange).toHaveBeenCalledExactlyOnceWith({
            cover_media_id: 'cover',
        });
        expect(mocks.post).not.toHaveBeenCalled();
    });

    it('recovers an older selected image and allows removing it after the format becomes invalid', async () => {
        await act(async () => {
            render(
                <InstagramCoverPicker
                    {...defaults}
                    options={{ cover_media_id: 'cover' }}
                    canChoose={false}
                />,
            );
        });
        expect(mocks.get.mock.calls[0][0]).toContain('selected=cover');
        expect(
            screen.getByRole('img', { name: 'Mountain cover' }),
        ).toHaveAttribute('src', cover.url);
        expect(screen.getByRole('alert')).toHaveTextContent(
            'exactly one video',
        );
        expect(
            screen.getByRole('button', { name: 'Choose existing cover' }),
        ).toBeDisabled();
        expect(
            screen.getByRole('button', { name: 'Upload cover' }),
        ).toBeDisabled();
        fireEvent.click(screen.getByRole('button', { name: 'Remove cover' }));
        expect(defaults.onChange).toHaveBeenCalledExactlyOnceWith({
            cover_media_id: null,
        });
    });

    it('keeps an unavailable selected cover removable without clearing it automatically', async () => {
        mocks.get.mockResolvedValue({ media: [], next_cursor: null });
        await act(async () => {
            render(
                <InstagramCoverPicker
                    {...defaults}
                    options={{ cover_media_id: 'missing' }}
                />,
            );
        });
        expect(
            screen.getByText('Selected cover preview unavailable.'),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Remove cover' }),
        ).toBeEnabled();
        expect(defaults.onChange).not.toHaveBeenCalled();
    });

    it('paginates without duplicating the selected image', async () => {
        mocks.get
            .mockResolvedValueOnce({ media: [cover], next_cursor: 'next' })
            .mockResolvedValueOnce({
                media: [
                    cover,
                    { ...cover, id: 'other', alt_text: 'Other cover' },
                ],
                next_cursor: null,
            });
        render(<InstagramCoverPicker {...defaults} />);
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
        expect(
            screen.getByRole('button', { name: 'Use Other cover' }),
        ).toBeInTheDocument();
    });

    it('uploads the original File and holds the publishing upload gate until it settles', async () => {
        let resolveUpload!: (value: { media: MediaView }) => void;
        mocks.post.mockReturnValue(
            new Promise((resolve) => {
                resolveUpload = resolve;
            }),
        );
        render(<InstagramCoverPicker {...defaults} />);
        const file = new File(['original bytes'], 'cover.jpg', {
            type: 'image/jpeg',
        });
        await act(async () => {
            fireEvent.change(
                screen.getByLabelText('Upload Instagram cover image'),
                { target: { files: [file] } },
            );
        });
        expect(mocks.transform.mock.calls[0][0]().file).toBe(file);
        expect(mocks.post.mock.calls[0][0]).toBe('/posts/post/media');
        expect(defaults.onUploadingChange).toHaveBeenLastCalledWith(true);
        expect(defaults.onChange).not.toHaveBeenCalled();
        await act(async () => {
            resolveUpload({ media: cover });
        });
        expect(defaults.onChange).toHaveBeenCalledExactlyOnceWith({
            cover_media_id: 'cover',
        });
        expect(defaults.onUploadingChange).toHaveBeenLastCalledWith(false);
    });

    it('rejects an unsupported upload before sending it', async () => {
        render(<InstagramCoverPicker {...defaults} />);
        const file = new File(['gif'], 'cover.gif', { type: 'image/gif' });
        await act(async () => {
            fireEvent.change(
                screen.getByLabelText('Upload Instagram cover image'),
                { target: { files: [file] } },
            );
        });
        expect(screen.getByRole('alert')).toHaveTextContent('JPEG or PNG');
        expect(mocks.post).not.toHaveBeenCalled();
        expect(defaults.onChange).not.toHaveBeenCalled();
    });

    it('ignores gallery results from the previous post after navigation', async () => {
        let resolveOld!: (value: {
            media: MediaView[];
            next_cursor: null;
        }) => void;
        mocks.get.mockReturnValueOnce(
            new Promise((resolve) => {
                resolveOld = resolve;
            }),
        );
        const view = render(
            <InstagramCoverPicker
                {...defaults}
                options={{ cover_media_id: 'cover' }}
            />,
        );
        view.rerender(
            <InstagramCoverPicker {...defaults} postId="next-post" />,
        );
        await act(async () => {
            resolveOld({ media: [cover], next_cursor: null });
        });
        expect(screen.queryByRole('img')).not.toBeInTheDocument();
        expect(defaults.onChange).not.toHaveBeenCalled();
        await act(async () => {
            fireEvent.click(
                screen.getByRole('button', { name: 'Choose existing cover' }),
            );
        });
        expect(mocks.get.mock.calls[1][0]).toContain(
            '/posts/next-post/instagram-covers',
        );
    });
});
