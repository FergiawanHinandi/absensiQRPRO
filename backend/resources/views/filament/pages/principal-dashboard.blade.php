{{-- Dashboard Kepala Sekolah --}}
<x-filament-panels::page>
    {{-- Filter Tahun Ajaran & Export --}}
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
                <div>
                    {{ $this->exportPdfAction }}
                </div>
            </div>
        </x-filament::section>
    </div>

    {{-- Header Widgets (Stat Cards) --}}
    <x-filament-widgets::widgets
        :widgets="$this->getHeaderWidgets()"
        :columns="['md' => 4]"
    />

    {{-- Grafik Tren --}}
    <div class="mb-4">
        <x-filament-widgets::widget
            :widget="\App\Filament\Widgets\PrincipalAttendanceChartWidget::class"
            :column-span="'full'"
        />
    </div>

    {{-- Main Widgets (Tabel) --}}
    <x-filament-widgets::widgets
        :widgets="$this->getWidgets()"
        :columns="['md' => 1]"
    />
</x-filament-panels::page>
