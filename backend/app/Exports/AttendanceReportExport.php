<?php

namespace App\Exports;

use Illuminate\Contracts\View\View;
use Maatwebsite\Excel\Concerns\FromView;

class AttendanceReportExport implements FromView
{
    protected $type;

    protected $params;

    public function __construct($type, $params)
    {
        $this->type = $type;
        $this->params = $params;
    }

    public function view(): View
    {
        switch ($this->type) {
            case 'daily':
                $view = 'excel.attendance_daily';
                break;
            case 'monthly':
                $view = 'excel.attendance_monthly';
                break;
            case 'student':
                $view = 'excel.attendance_student';
                break;
            default:
                abort(400, 'Invalid export type');
        }

        return view($view, ['params' => $this->params]);
    }
}
