<?php

namespace App\Livewire\Dashboard\Concerns;

/**
 * Backs the in-app confirm modal that replaces the browser's native
 * confirm() dialog: requestConfirm() stages an action and its params,
 * confirmYes() invokes it by name once the user actually confirms.
 */
trait ManagesConfirmModal
{
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

        if ($action && method_exists($this, $action)) {
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
