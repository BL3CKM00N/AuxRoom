<?php

namespace App\Livewire\Dashboard\Concerns;

/**
 * Backs the in-app confirm modal that replaces the browser's native
 * confirm() dialog: requestConfirm() stages an action and its params,
 * confirmYes() invokes it by name once the user actually confirms.
 */
trait ManagesConfirmModal
{
    /**
     * confirmYes() dispatches by name, and both the action and its params
     * are client-settable, so without an allowlist a visitor could stage any
     * method on the component, including private helpers that skip the
     * permission checks their public wrappers do (e.g. playTrackAt).
     */
    private const CONFIRMABLE_ACTIONS = [
        'closeRoom',
        'denyMember',
        'kickMember',
        'revokeAllAccess',
        'toggleLocationEnforced',
        'togglePrivate',
    ];

    public ?string $confirmAction = null;

    /** @var array<int, mixed> */
    public array $confirmParams = [];

    public string $confirmMessage = '';

    public string $confirmLabel = 'Confirm';

    public bool $confirmDanger = true;

    /**
     * Ask the user to confirm a destructive action via the in-app modal,
     * instead of the browser's native confirm() dialog.
     */
    public function requestConfirm(string $action, array $params = [], string $message = '', string $label = 'Confirm', bool $danger = true): void
    {
        $this->confirmAction = $action;
        $this->confirmParams = $params;
        $this->confirmMessage = $message;
        $this->confirmLabel = $label;
        $this->confirmDanger = $danger;
    }

    public function confirmYes(): mixed
    {
        $action = $this->confirmAction;
        $params = $this->confirmParams;

        $this->confirmAction = null;
        $this->confirmParams = [];
        $this->confirmMessage = '';

        if ($action && in_array($action, self::CONFIRMABLE_ACTIONS, true)) {
            return $this->{$action}(...$params);
        }

        return null;
    }

    public function confirmNo(): void
    {
        $this->confirmAction = null;
        $this->confirmParams = [];
        $this->confirmMessage = '';
    }
}
