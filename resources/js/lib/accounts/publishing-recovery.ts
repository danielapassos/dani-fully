import type { Account } from '@/components/accounts/types';

type PublishingRecoveryAccount = Pick<
    Account,
    'disabled' | 'status' | 'publishing_ready' | 'publishing_recovery_kind'
>;

export function shouldEmphasizeReconnect(
    account: PublishingRecoveryAccount,
): boolean {
    if (account.status !== 'active') {
        return true;
    }

    return (
        !account.disabled &&
        !account.publishing_ready &&
        account.publishing_recovery_kind === 'reconnect'
    );
}
