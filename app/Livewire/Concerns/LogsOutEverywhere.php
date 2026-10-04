<?php

namespace App\Livewire\Concerns;

use App\Livewire\Actions\Logout;
use App\Services\Auth\UserSessions;

/**
 * The "Log out on all devices" confirmation, shared by the account menu and the
 * profile page. The count is read just before the popup opens (see the buttons),
 * not on every render; confirming ends every login, closes the room and sends the
 * person to the home page, which shows what happened.
 */
trait LogsOutEverywhere
{
    /** Filled just before the confirmation opens. */
    public int $deviceCount = 1;

    public function prepareLogoutEverywhere(UserSessions $sessions): void
    {
        $this->deviceCount = max(1, $sessions->live(auth()->user())->count());
    }

    public function logoutEverywhere(Logout $logout): void
    {
        $logout->everywhere();

        $this->redirect('/', navigate: true);
    }
}
