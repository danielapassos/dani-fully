import { describe, expect, it } from 'vitest';

import { shouldEmphasizeReconnect } from '@/lib/accounts/publishing-recovery';

describe('shouldEmphasizeReconnect', () => {
    it('emphasizes reconnect when the account or its upload scope needs recovery', () => {
        expect(
            shouldEmphasizeReconnect({
                disabled: false,
                status: 'needs_attention',
                publishing_ready: false,
                publishing_recovery_kind: 'reconnect',
            }),
        ).toBe(true);

        expect(
            shouldEmphasizeReconnect({
                disabled: false,
                status: 'active',
                publishing_ready: false,
                publishing_recovery_kind: 'reconnect',
            }),
        ).toBe(true);
    });

    it('does not imply reconnect can fix an installation-level provider gate', () => {
        expect(
            shouldEmphasizeReconnect({
                disabled: false,
                status: 'active',
                publishing_ready: false,
                publishing_recovery_kind: 'operator_configuration',
            }),
        ).toBe(false);
    });
});
