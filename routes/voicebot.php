<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Monoverse\VoicebotSync\Http\SyncTriggerController;

Route::post(SyncTriggerController::CANONICAL_PATH, SyncTriggerController::class);
