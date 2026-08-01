{{-- Dashboard Guru --}}
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

    {{-- Info Kelas Wali (untuk homeroom teacher) --}}
    @php $homeroom = $this->getHomeroomInfo(); @endphp
    @if($homeroom)
        <div class="mb-4">
            <x-filament::section compact>
                <div class="flex items-center gap-4">
                    <div class="p-2 rounded-lg bg-indigo-50 dark:bg-indigo-900/30">
                        <x-heroicon-m-building-library class="w-5 h-5 text-indigo-600 dark:text-indigo-400" />
                    </div>
                    <div>
                        <p class="text-sm text-gray-500 dark:text-gray-400">Kelas Wali</p>
                        <p class="text-lg font-bold">{{ $homeroom['class_name'] }} (Tingkat {{ $homeroom['grade_level'] }})</p>
                    </div>
                    <div class="text-sm text-gray-500 dark:text-gray-400">
                        {{ $homeroom['student_count'] }} Siswa
                    </div>
                </div>
            </x-filament::section>
        </div>
    @endif

    {{-- Quick Actions --}}
    <div class="mb-4">
        <x-filament::section compact>
            <div class="flex items-center gap-4">
                <div class="flex-1">
                    <h3 class="text-sm font-medium text-gray-500 dark:text-gray-400">Aksi Cepat</h3>
                </div>
                <div class="flex flex-wrap gap-2">
                    {{ $this->scanQrAction }}
                    {{ $this->myAttendanceAction }}
                    {{ $this->pendingActionsAction }}
                </div>
            </div>
        </x-filament::section>
    </div>

    {{-- Header Widgets (Personal Status) --}}
    <x-filament-widgets::widgets
        :widgets="$this->getHeaderWidgets()"
        :columns="['md' => 3]"
    />

    {{-- Absensi Kelas Wali --}}
    <div class="mt-4 mb-4">
        <x-filament-widgets::widget
            :widget="\App\Filament\Widgets\TeacherMyClassAttendanceWidget::class"
            :column-span="'full'"
        />
    </div>

    {{-- Izin Pending --}}
    <div class="mb-4">
        <x-filament-widgets::widget
            :widget="\App\Filament\Widgets\TeacherPendingPermitsWidget::class"
            :column-span="'full'"
        />
    </div>

    {{-- Riwayat Absensi --}}
    <x-filament-widgets::widget
        :widget="\App\Filament\Widgets\TeacherRecentAttendanceWidget::class"
        :column-span="'full'"
    />
</x-filament-panels::page>
