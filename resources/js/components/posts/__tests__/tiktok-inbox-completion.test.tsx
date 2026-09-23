import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';

import { TikTokInboxCompletion } from '@/components/posts/tiktok-inbox-completion';

const caption =
    'ASMR tabi shoes unboxing @woodchucksato\n\n#TabiShoes #ShoeUnboxing #ASMR #WoodChuckSato';
const handoff = {
    kind: 'tiktok_inbox' as const,
    caption,
    instructions:
        'Open the upload notification in your TikTok Inbox to finish posting.',
};

const originalClipboard = Object.getOwnPropertyDescriptor(
    navigator,
    'clipboard',
);
afterEach(() => {
    vi.restoreAllMocks();
    if (originalClipboard) {
        Object.defineProperty(navigator, 'clipboard', originalClipboard);
    } else {
        Reflect.deleteProperty(navigator, 'clipboard');
    }
});

function mockClipboard(writeText: ReturnType<typeof vi.fn>) {
    Object.defineProperty(navigator, 'clipboard', {
        configurable: true,
        value: { writeText },
    });
}

it('copies the exact approved caption including mentions and line breaks', async () => {
    const writeText = vi.fn().mockResolvedValue(undefined);
    mockClipboard(writeText);
    render(<TikTokInboxCompletion handoff={handoff} />);
    expect(screen.getByLabelText('Caption to paste in TikTok')).toHaveValue(
        caption,
    );
    expect(screen.getByLabelText('Caption to paste in TikTok')).toHaveAttribute(
        'readonly',
    );
    fireEvent.click(screen.getByRole('button', { name: 'Copy caption' }));
    await waitFor(() =>
        expect(screen.getByRole('status')).toHaveTextContent('Caption copied.'),
    );
    expect(writeText).toHaveBeenCalledExactlyOnceWith(caption);
    expect(screen.getByRole('status')).toHaveTextContent(
        'Paste it over any text TikTok prefilled',
    );
});

it('selects the saved caption for manual copying when clipboard access fails', async () => {
    const writeText = vi.fn().mockRejectedValue(new Error('Permission denied'));
    mockClipboard(writeText);
    vi.spyOn(console, 'warn').mockImplementation(() => undefined);
    render(<TikTokInboxCompletion handoff={handoff} />);
    fireEvent.click(screen.getByRole('button', { name: 'Copy caption' }));
    await waitFor(() =>
        expect(screen.getByRole('status')).toHaveTextContent(
            'copy it manually',
        ),
    );
    const field = screen.getByLabelText<HTMLTextAreaElement>(
        'Caption to paste in TikTok',
    );
    expect(field).toHaveFocus();
    expect(field.selectionStart).toBe(0);
    expect(field.selectionEnd).toBe(caption.length);
    expect(screen.queryByText(/Caption copied/)).not.toBeInTheDocument();
});

it('does not offer to copy a nonexistent caption', () => {
    render(<TikTokInboxCompletion handoff={{ ...handoff, caption: '' }} />);
    expect(screen.getByRole('button', { name: 'Copy caption' })).toBeDisabled();
    expect(screen.queryByText(/Caption copied/)).not.toBeInTheDocument();
});
