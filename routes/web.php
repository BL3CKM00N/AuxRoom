<?php

use App\Http\Controllers\JoinController;
use App\Http\Controllers\RoomController;
use App\Http\Controllers\SpotifyConnectionController;
use App\Livewire\Dashboard\PartyScreen;
use App\Livewire\Dashboard\ShowRoom;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome');

Route::get('/join', [JoinController::class, 'create'])->name('join');
Route::post('/join', [JoinController::class, 'store'])->name('join.store');

// Your room, if you're hosting one — otherwise mount() bounces to rooms.create.
Route::get('/dashboard', ShowRoom::class)
    ->middleware(['auth', 'verified'])
    ->name('dashboard');

Route::view('profile', 'profile')
    ->middleware(['auth'])
    ->name('profile');

Route::middleware(['auth'])->group(function () {
    Route::post('/spotify/connect', [SpotifyConnectionController::class, 'connect'])->name('spotify.connect');
    Route::get('/spotify/callback', [SpotifyConnectionController::class, 'callback'])->name('spotify.callback');
    Route::delete('/spotify', [SpotifyConnectionController::class, 'disconnect'])->name('spotify.disconnect');

    Route::get('/dashboard/create', [RoomController::class, 'create'])->name('rooms.create');
    Route::post('/dashboard', [RoomController::class, 'store'])->name('rooms.store');
    Route::delete('/rooms/{room}', [RoomController::class, 'destroy'])->name('rooms.destroy');
});

// Shareable guest-facing URLs — reachable by anyone with the invite code, no account needed.
Route::get('/rooms/{room}', ShowRoom::class)->name('rooms.show');
Route::get('/rooms/{room}/party', PartyScreen::class)->name('rooms.party');

require __DIR__.'/auth.php';
