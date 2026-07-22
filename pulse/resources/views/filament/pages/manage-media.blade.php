<x-filament-panels::page>
    @php $r = $this->report(); @endphp

    {{-- Summary cards --}}
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
        <x-filament::section>
            <div class="text-sm text-gray-500">Total media</div>
            <div class="text-2xl font-bold">{{ $r['total']['size'] }}</div>
            <div class="text-sm text-gray-500">{{ $r['total']['count'] }} files</div>
        </x-filament::section>
        <x-filament::section>
            <div class="text-sm text-gray-500">In use</div>
            <div class="text-2xl font-bold text-success-600">{{ $r['used']['size'] }}</div>
            <div class="text-sm text-gray-500">{{ $r['used']['count'] }} files</div>
        </x-filament::section>
        <x-filament::section>
            <div class="text-sm text-gray-500">Unused (safe to delete)</div>
            <div class="text-2xl font-bold text-danger-600">{{ $r['unused']['size'] }}</div>
            <div class="text-sm text-gray-500">{{ $r['unused']['count'] }} files</div>
        </x-filament::section>
    </div>

    {{-- Filter --}}
    <div class="flex items-center gap-2">
        @foreach (['unused' => 'Unused', 'used' => 'In use', 'all' => 'All'] as $key => $label)
            <x-filament::button
                wire:click="$set('filter', '{{ $key }}')"
                :color="$filter === $key ? 'primary' : 'gray'"
                size="sm">
                {{ $label }}
            </x-filament::button>
        @endforeach
        <span class="text-sm text-gray-500">
            Showing {{ min($r['shownCount'], $r['listLimit']) }} of {{ $r['shownCount'] }}
            @if($r['shownCount'] > $r['listLimit']) (use “Delete all unused” to clear the rest) @endif
        </span>
    </div>

    {{-- File grid --}}
    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
        @forelse ($r['files'] as $f)
            <div class="rounded-lg border border-gray-200 dark:border-white/10 overflow-hidden bg-white dark:bg-white/5">
                <div class="aspect-square bg-gray-100 dark:bg-white/5 flex items-center justify-center overflow-hidden">
                    <img src="{{ $f['url'] }}" alt="" loading="lazy" class="w-full h-full object-cover" />
                </div>
                <div class="p-2 space-y-1">
                    <div class="text-xs font-medium truncate" title="{{ $f['path'] }}">{{ basename($f['path']) }}</div>
                    <div class="flex items-center justify-between">
                        <span class="text-xs text-gray-500">{{ $f['human'] }}</span>
                        @if ($f['used'])
                            <span class="text-xs font-semibold text-success-600">In use</span>
                        @else
                            <span class="text-xs font-semibold text-danger-600">Unused</span>
                        @endif
                    </div>
                    @unless ($f['used'])
                        <x-filament::button
                            wire:click="deleteFile('{{ $f['path'] }}')"
                            wire:confirm="Delete this file permanently?"
                            color="danger" size="xs" class="w-full">
                            Delete
                        </x-filament::button>
                    @endunless
                </div>
            </div>
        @empty
            <div class="col-span-full text-center text-gray-500 py-10">No files in this view.</div>
        @endforelse
    </div>
</x-filament-panels::page>
