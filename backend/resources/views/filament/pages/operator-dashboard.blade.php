{{-- Dashboard Operator Sekolah --}}
<x-filament-panels::page>
    {{-- Filter Tahun Ajaran --}}
    <div class="mb-4">
        <x-filament::section compact>
            <div class="flex items-center gap-4">
                <div class="w-72">
                    <x-filament::form wire:submit="mount">
                        {{ $this->form }}
                    </x-filament::form>
                </div>
                <div class="text-sm text-gray-500 dark:text-gray-400 flex-1">
                    @if($academicYearId)
                        @php
                            $year = \App\Models\AcademicYear::find($academicYearId);
                        @endphp
                        @if($year)
                            <span class="font-medium">Periode:</span>
                            {{ $year->start_date?->isoFormat('D MMM YYYY') }} —
                            {{ $year->end_date?->isoFormat('D MMM YYYY') }}
                        @endif
                    @endif
                </div>
                <div class="flex-1"></div>
            </div>
        </x-filament::section>
    </div>

    {{-- Quick Actions --}}
    <div class="mb-4">
        <x-filament::section compact>
            <div class="flex items-center gap-4">
                <div class="flex-1">
                    <h3 class="text-sm font-medium text-gray-500 dark:text-gray-400">Akses Cepat</h3>
                </div>
                <div class="flex flex-wrap gap-2">
                    {{ $this->manageStudentsAction }}
                    {{ $this->manageTeachersAction }}
                    {{ $this->manageClassesAction }}
                    {{ $this->manageDevicesAction }}
                </div>
            </div>
        </x-filament::section>
    </div>

    {{-- Header Widgets (Stat Cards) --}}
    <x-filament-widgets::widgets
        :widgets="$this->getHeaderWidgets()"
        :columns="['md' => 4]"
    />

    {{-- Informasi Ringkasan --}}
    <div class="mt-2 grid grid-cols-1 md:grid-cols-4 gap-4 mb-4">
        @php $stats = $this->getQuickStats(); @endphp
        @if($stats)
            <div class="rounded-lg bg-white dark:bg-gray-800 shadow-sm border border-gray-200 dark:border-gray-700 p-4">
                <div class="flex items-center gap-3">
                    <div class="p-2 rounded-lg bg-blue-50 dark:bg-blue-900/30">
                        <x-heroicon-m-user class="w-5 h-5 text-blue-600 dark:text-blue-400" />
                    </div>
                    <div>
                        <p class="text-xs text-gray-500 dark:text-gray-400">Total Siswa</p>
                        <p class="text-lg font-bold">{{ number_format($stats['totalStudents']) }}</p>
                    </div>
                </div>
            </div>
            <div class="rounded-lg bg-white dark:bg-gray-800 shadow-sm border border-gray-200 dark:border-gray-700 p-4">
                <div class="flex items-center gap-3">
                    <div class="p-2 rounded-lg bg-green-50 dark:bg-green-900/30">
                        <x-heroicon-m-user-group class="w-5 h-5 text-green-600 dark:text-green-400" />
                    </div>
                    <div>
                        <p class="text-xs text-gray-500 dark:text-gray-400">Total Guru</p>
                        <p class="text-lg font-bold">{{ number_format($stats['totalTeachers']) }}</p>
                    </div>
                </div>
            </div>
            <div class="rounded-lg bg-white dark:bg-gray-800 shadow-sm border border-gray-200 dark:border-gray-700 p-4">
                <div class="flex items-center gap-3">
                    <div class="p-2 rounded-lg bg-amber-50 dark:bg-amber-900/30">
                        <x-heroicon-m-building-library class="w-5 h-5 text-amber-600 dark:text-amber-400" />
                    </div>
                    <div>
                        <p class="text-xs text-gray-500 dark:text-gray-400">Jumlah Kelas</p>
                        <p class="text-lg font-bold">{{ number_format($stats['totalClasses']) }}</p>
                    </div>
                </div>
            </div>
            <div class="rounded-lg bg-white dark:bg-gray-800 shadow-sm border border-gray-200 dark:border-gray-700 p-4">
                <div class="flex items-center gap-3">
                    <div class="p-2 rounded-lg bg-purple-50 dark:bg-purple-900/30">
                        <x-heroicon-m-clipboard-document-list class="w-5 h-5 text-purple-600 dark:text-purple-400" />
                    </div>
                    <div>
                        <p class="text-xs text-gray-500 dark:text-gray-400">Izin Pending</p>
                        <p class="text-lg font-bold">{{ $stats['pendingPermits'] }}</p>
                    </div>
                </div>
            </div>
        @endif
    </div>

    {{-- Tabel Absensi Hari Ini --}}
    <div class="mb-4">
        <x-filament-widgets::widget
            :widget="\App\Filament\Widgets\OperatorTodayAttendanceWidget::class"
            :column-span="'full'"
        />
    </div>

    {{-- Widgets Lainnya --}}
    <x-filament-widgets::widgets
        :widgets="[
            \App\Filament\Widgets\OperatorRecentActivityWidget::class,
            \App\Filament\Widgets\OperatorDeviceStatusWidget::class,
        ]"
        :columns="['md' => 1]"
    />

</x-filament-panels::page>
