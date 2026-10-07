<?php

declare(strict_types=1);

namespace App\Services\Manager;

use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * Encapsulates account-management business rules for the Manager RBAC screen:
 * role syncing, activation toggling and deletion — with guards that prevent the
 * system from locking itself out (last active super_admin, self-lock/self-delete).
 */
class UserAccountService
{
    /** Sync a user's roles, guarding against removing the final super_admin. */
    public function updateRoles(User $user, array $roles): void
    {
        $this->guardLastSuperAdmin($user, willRemainSuperAdmin: in_array('super_admin', $roles, true));
        $user->syncRoles($roles);
    }

    /** Activate / deactivate an account. Cannot deactivate self or the last super_admin. */
    public function setActive(User $user, bool $active): void
    {
        if (!$active) {
            $this->guardNotSelf($user, 'Không thể tự khóa tài khoản của chính mình.');
            $this->guardLastSuperAdmin($user, willRemainSuperAdmin: false);
        }

        $user->update(['is_active' => $active]);
    }

    /** Soft-delete an account. Cannot delete self or the last super_admin. */
    public function delete(User $user): void
    {
        $this->guardNotSelf($user, 'Không thể tự xóa tài khoản của chính mình.');
        $this->guardLastSuperAdmin($user, willRemainSuperAdmin: false);

        $user->delete();
    }

    private function guardNotSelf(User $user, string $message): void
    {
        if ($user->id === Auth::id()) {
            throw new \DomainException($message);
        }
    }

    /**
     * If $user is currently a super_admin and the pending change would strip that
     * status (role change / deactivation / deletion), ensure another active
     * super_admin remains.
     */
    private function guardLastSuperAdmin(User $user, bool $willRemainSuperAdmin): void
    {
        if (!$user->hasRole('super_admin') || $willRemainSuperAdmin) {
            return;
        }

        $otherActiveSuperAdmins = User::role('super_admin')
            ->where('is_active', true)
            ->where('id', '!=', $user->id)
            ->count();

        if ($otherActiveSuperAdmins === 0) {
            throw new \DomainException('Phải còn ít nhất một Super Admin đang hoạt động.');
        }
    }
}
