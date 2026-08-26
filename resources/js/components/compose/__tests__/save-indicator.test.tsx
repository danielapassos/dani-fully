import { describe, expect, it } from 'vitest';

import { formatSaveLabel } from '@/components/compose/save-indicator';

describe('formatSaveLabel', () => {
    it('does not claim offline drafts are stored locally', () => {
        expect(formatSaveLabel('offline', null)).toBe(
            'Offline — keep this page open',
        );
    });
});
