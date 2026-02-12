<?php

namespace App\Models;

/**
 * Classroom — Alias for ClassModel
 *
 * Many controllers and tests reference App\Models\Classroom, but the actual
 * model is ClassModel (because 'class' is a PHP reserved word).
 * This alias ensures backward compatibility without mass-renaming.
 *
 * @see \App\Models\ClassModel
 */
class Classroom extends ClassModel
{
    // Pure alias — all logic lives in ClassModel
}
