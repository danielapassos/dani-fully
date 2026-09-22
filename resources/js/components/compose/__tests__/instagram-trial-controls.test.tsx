import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import {
    InstagramTrialControls,
    InstagramTrialSummary,
} from '@/components/compose/instagram-trial-controls';
import type { Account } from '@/types/compose';

const account: Account = {
    id: 'instagram',
    platform: 'instagram',
    handle: '@creator',
    display_name: null,
    avatar_url: null,
    max_text_length: 2200,
    x_premium: false,
};

describe('Instagram Trial Reel controls', () => {
    it('requires opt-in, defaults to manual sharing, and requires a separate auto-sharing choice', () => {
        const onChange = vi.fn();
        const props = {
            account,
            canChoose: true,
            onChange,
            options: undefined,
        };
        const view = render(<InstagramTrialControls {...props} />);
        expect(
            screen.getByRole('switch', { name: 'Trial Reel' }),
        ).not.toBeChecked();
        expect(screen.queryByRole('radio')).not.toBeInTheDocument();
        expect(onChange).not.toHaveBeenCalled();
        fireEvent.click(screen.getByRole('switch', { name: 'Trial Reel' }));
        expect(onChange).toHaveBeenLastCalledWith('MANUAL');
        view.rerender(
            <InstagramTrialControls
                {...props}
                options={{
                    cover_media_id: 'cover',
                    trial_params: { graduation_strategy: 'MANUAL' },
                }}
            />,
        );
        expect(
            screen.getByRole('radio', {
                name: 'Share with followers manually',
            }),
        ).toBeChecked();
        expect(
            screen.getByText(/open this Reel in Instagram/),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('radio', { name: /Automatically share/ }),
        ).not.toBeChecked();
        fireEvent.click(
            screen.getByRole('radio', { name: /Automatically share/ }),
        );
        expect(onChange).toHaveBeenLastCalledWith('SS_PERFORMANCE');
        fireEvent.click(screen.getByRole('switch', { name: 'Trial Reel' }));
        expect(onChange).toHaveBeenLastCalledWith(null);
        expect(screen.getByText(/non-followers first/)).toBeInTheDocument();
    });

    it('does not offer trial opt-in without an eligible Instagram video', () => {
        const onChange = vi.fn();
        const view = render(
            <InstagramTrialControls
                account={account}
                options={undefined}
                canChoose={false}
                onChange={onChange}
            />,
        );
        expect(screen.queryByRole('switch')).not.toBeInTheDocument();
        view.rerender(
            <InstagramTrialControls
                account={{ ...account, platform: 'youtube' }}
                options={undefined}
                canChoose
                onChange={onChange}
            />,
        );
        expect(screen.queryByRole('switch')).not.toBeInTheDocument();
        expect(onChange).not.toHaveBeenCalled();
    });

    it('keeps an invalid existing trial visible and removable without silently publishing a regular Reel', () => {
        const onChange = vi.fn();
        render(
            <InstagramTrialControls
                account={account}
                options={{
                    cover_media_id: null,
                    trial_params: { graduation_strategy: 'MANUAL' },
                }}
                canChoose={false}
                onChange={onChange}
            />,
        );
        expect(screen.getByRole('alert')).toHaveTextContent(
            'exactly one video',
        );
        expect(
            screen.getByRole('radio', { name: /Automatically share/ }),
        ).toBeDisabled();
        expect(
            screen.getByRole('switch', { name: 'Trial Reel' }),
        ).toBeChecked();
        expect(onChange).not.toHaveBeenCalled();
        fireEvent.click(screen.getByRole('switch', { name: 'Trial Reel' }));
        expect(onChange).toHaveBeenLastCalledWith(null);
    });

    it('describes saved configuration without claiming the trial was published', () => {
        render(
            <InstagramTrialSummary
                options={{
                    cover_media_id: null,
                    trial_params: { graduation_strategy: 'SS_PERFORMANCE' },
                }}
            />,
        );
        expect(
            screen.getByLabelText('Configured Instagram Trial Reel'),
        ).toHaveTextContent('Trial Reel configured');
        expect(screen.getByText(/Automatically share/)).toBeInTheDocument();
        expect(screen.queryByText(/Published/)).not.toBeInTheDocument();
    });
});
