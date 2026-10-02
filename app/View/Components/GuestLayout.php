<?php

namespace App\View\Components;

use App\Support\SharePreview;
use Illuminate\View\Component;
use Illuminate\View\View;

class GuestLayout extends Component
{
    public function __construct(
        public ?string $ogTitle = null,
        public ?string $ogDescription = null,
        public ?SharePreview $preview = null,
    ) {}

    /**
     * Get the view / contents that represents the component.
     */
    public function render(): View
    {
        return view('layouts.guest');
    }
}
