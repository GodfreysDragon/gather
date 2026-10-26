<?php

use Illuminate\Support\Facades\Schedule;

// Expired tokens no longer authenticate; this just keeps the table small.
Schedule::command('sanctum:prune-expired --hours=24')->daily();
