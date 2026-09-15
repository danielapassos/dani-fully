<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\User;
use App\Models\WorkspaceInvitation;
use App\Services\Workspace\WorkspaceProvisioningService;
use App\Support\InstanceSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    public function __construct(
        private WorkspaceProvisioningService $provisioning,
        private InstanceSettings $settings,
    ) {}

    /**
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        $invitationToken = request()->input('invitation');
        $invitationToken = is_string($invitationToken) ? $invitationToken : null;

        if (! $this->settings->registrationsAllowed($invitationToken)) {
            throw ValidationException::withMessages([
                'email' => 'Registration is disabled for this instance.',
            ]);
        }

        if ($invitationToken !== null) {
            $invitation = WorkspaceInvitation::findByToken($invitationToken);

            if ($invitation?->isValid()) {
                $input['email'] = $invitation->email;
            }
        }

        Validator::make($input, [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
        ])->validate();

        $invitationRequired = ! $this->settings->registrationsAllowed();

        return DB::transaction(function () use ($input, $invitationToken, $invitationRequired): User {
            $user = User::create([
                'name' => $input['name'],
                'email' => $input['email'],
                'password' => $input['password'],
            ]);

            $this->settings->claimOwnerIfMissing($user);
            $invitationAccepted = $this->provisioning->provisionForNewUser($user, $invitationToken);

            if ($invitationRequired && ! $invitationAccepted) {
                throw ValidationException::withMessages([
                    'email' => 'A valid invitation is required to register on this instance.',
                ]);
            }

            return $user->refresh();
        });
    }
}
