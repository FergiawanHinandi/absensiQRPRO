<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('school.{schoolId}', function ($user, $schoolId) {
    return (int) $user->school_id === (int) $schoolId;
});

Broadcast::channel('health.{schoolId}', function ($user, $schoolId) {
    return (int) $user->school_id === (int) $schoolId;
});
