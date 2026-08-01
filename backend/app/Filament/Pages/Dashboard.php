<?php

namespace App\Filament\Pages;

use Filament\Pages\Dashboard as BaseDashboard;

class Dashboard extends BaseDashboard
{
    /**
     * Sembunyikan dashboard default dari sidebar navigasi.
     * Setiap role akan melihat dashboard spesifik mereka
     * (PrincipalDashboard, OperatorDashboard, TeacherDashboard).
     */
    protected static bool $shouldRegisterNavigation = false;

    /**
     * Dashboard tetap bisa diakses via URL langsung
     * (sebagai landing page setelah login).
     */
    public static function canAccess(): bool
    {
        return auth()->user() !== null;
    }
}
