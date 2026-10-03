<?php

namespace App\Http\Controllers;

use App\Support\Errors\ErrorCatalog;

class HelpController extends Controller
{
    /** Every error with what happened, why and what to do. Public: a guest who can't join has no login. */
    public function __invoke()
    {
        $grouped = collect(ErrorCatalog::all())->groupBy('group');

        return view('help', ['groups' => ErrorCatalog::GROUPS, 'grouped' => $grouped]);
    }
}
