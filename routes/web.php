<?php

use Illuminate\Support\Facades\Route;

Route::layout('layouts.dashboard')->middleware(['auth'])->group(function(){
    // COMMUNICATIONS
    Route::get('communication-templates', \Condoedge\Communications\Components\CommunicationsList::class)->name('communication-templates.table');

    Route::GET('communication-sendings', \Condoedge\Communications\Components\CommunicationSendingsList::class)->name('communication-sendings.table');
});