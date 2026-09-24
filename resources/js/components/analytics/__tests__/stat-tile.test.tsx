import { render, screen } from '@testing-library/react';
import { expect, it } from 'vitest';

import { StatTile } from '../stat-tile';

it('distinguishes missing measurements from a measured zero', () => {
    const { rerender } = render(
        <StatTile
            label="Followers"
            metric={{ value: null, delta: null }}
            caption="Connected accounts"
            deltaLabel="change"
        />,
    );
    expect(screen.getByText('—')).toBeInTheDocument();
    expect(screen.queryByText('0')).not.toBeInTheDocument();

    rerender(
        <StatTile
            label="Followers"
            metric={{ value: 0, delta: null }}
            caption="Connected accounts"
            deltaLabel="change"
        />,
    );
    expect(screen.getByText('0')).toBeInTheDocument();
});
