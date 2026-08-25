import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { describe, expect, it } from 'vitest';

describe('sidebar footer card variants', () => {
    const source = readFileSync(
        resolve(
            process.cwd(),
            'resources/js/components/layout/sidebar-footer-card.tsx',
        ),
        'utf8',
    );

    it('chooses the variant from features.billing', () => {
        expect(source).toContain('features?.billing');
    });

    it('shows an Upgrade call to action when not subscribed', () => {
        expect(source).toContain('Upgrade');
        expect(source).toContain('billing.manageUrl');
    });

    it('hides the chip for subscribed workspaces', () => {
        expect(source).toContain('billing.subscribed');
        expect(source).not.toContain("'Manage'");
        expect(source).not.toContain('Active subscription');
    });

    it('does not render the upstream community promos', () => {
        expect(source).not.toContain('Star on GitHub');
        expect(source).not.toContain('Sponsor');
        expect(source).not.toContain('community.repoUrl');
        expect(source).not.toContain('community.sponsorUrl');
    });
});
