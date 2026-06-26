<?php

use Illuminate\Support\Facades\Route;

Route::layout('layouts.dashboard')->middleware(['auth'])->group(function () {
    // Communications admin is mounted by the host app (e.g. SISC route `communications.management`).
    // The legacy standalone CommunicationsList / CommunicationSendingsList routes were removed when
    // those components were superseded by the tabbed admin page.
});
