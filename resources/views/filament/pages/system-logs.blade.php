<x-filament-panels::page>
    <x-filament::section heading="Application Log" description="storage/logs/laravel.log — read-only, {{ $lines }} baris terakhir (setelah filter)">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-end">
            <div class="flex-1">
                <x-filament::input.wrapper>
                    <x-filament::input
                        type="text"
                        wire:model.live.debounce.400ms="search"
                        placeholder="Cari di log (mis. 'ERROR', 'webhook', email tenant)..."
                    />
                </x-filament::input.wrapper>
            </div>
            <div class="w-32">
                <x-filament::input.wrapper>
                    <x-filament::input
                        type="number"
                        min="10"
                        max="1000"
                        wire:model.live="lines"
                    />
                </x-filament::input.wrapper>
            </div>
        </div>

        <div class="mt-4 max-h-[32rem] overflow-y-auto rounded-lg bg-gray-950 p-4 font-mono text-xs leading-relaxed text-gray-200">
            @forelse ($this->logLines() as $line)
                <div class="whitespace-pre-wrap border-b border-white/5 py-1 last:border-0">{{ $line }}</div>
            @empty
                <p class="text-gray-500">Tidak ada baris log yang cocok.</p>
            @endforelse
        </div>
    </x-filament::section>

    <x-filament::section heading="Config (Non-Secret)" description="Whitelist eksplisit — tidak pernah menampilkan credential/secret apapun">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <tbody>
                    @foreach ($this->getConfigSnapshot() as $key => $value)
                        <tr class="border-b border-gray-100 dark:border-white/5">
                            <td class="py-2 pr-4 font-mono text-xs text-gray-500">{{ $key }}</td>
                            <td class="py-2 font-mono text-xs">{{ $value }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>
