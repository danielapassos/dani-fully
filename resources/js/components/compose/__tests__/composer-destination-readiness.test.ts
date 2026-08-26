import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import { describe, expect, it } from 'vitest';

const source = () =>
    readFileSync(
        resolve(process.cwd(), 'resources/js/components/compose/composer.tsx'),
        'utf8',
    );

describe('composer destination readiness normalization', () => {
    it('never marks an existing draft dirty to remove a temporarily unavailable target', () => {
        const composer = source();
        const guard = composer.indexOf('if (post !== null) {');
        const normalize = composer.indexOf(
            'const destination = normalizePublishingDestination(',
            guard,
        );
        const dirtyingDispatch = composer.indexOf(
            "dispatch({ type: 'setDestination', destination });",
            normalize,
        );

        expect(guard).toBeGreaterThan(-1);
        expect(normalize).toBeGreaterThan(guard);
        expect(dirtyingDispatch).toBeGreaterThan(normalize);
        const guardBody = composer.slice(guard, normalize);
        expect(guardBody).toContain('return;');
        expect(guardBody).not.toContain('postCapabilities');
    });

    it('keeps readiness normalization wired for a brand-new composer', () => {
        const composer = source();

        expect(composer).toContain(
            'normalizePublishingDestination(\n            state.destination,\n            accounts,\n            sets,',
        );
        expect(composer).toContain('if (destination !== state.destination) {');
    });

    it('resolves every stored target into persisted-draft tabs and precheck', () => {
        const composer = source();

        expect(composer).toContain(
            'const destinationAccountIds = composerAccountIds(\n        post !== null,',
        );
        expect(composer).toContain('const tabAccounts = accounts.filter');
        expect(composer).toContain('accounts: tabAccounts,');
    });
});
