<?php

namespace App\Models;

/**
 * Student — Alias for User with student semantics
 *
 * Students are stored in the `users` table with `role_type = 'student'`.
 * This model provides a dedicated type for student-specific logic.
 *
 * @see \App\Models\User
 */
class Student extends User
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'users';

    // Pure alias — all logic lives in User
}
