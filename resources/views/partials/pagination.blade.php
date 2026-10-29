@if ($paginator->hasPages() || $paginator->total() > 0)
<div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 p-4 border-t border-slate-800 bg-slate-900/60 text-xs text-slate-400">
    <!-- Left: Per page selector -->
    <div class="flex items-center gap-2">
        <span>Tampilkan</span>
        <select onchange="const u = new URL(window.location.href); u.searchParams.set('per_page', this.value); u.searchParams.set('page', 1); window.location.href = u.toString();"
            class="px-2.5 py-1 rounded-lg bg-slate-950 border border-slate-700 text-slate-200 text-xs focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500 font-medium cursor-pointer">
            <option value="10" {{ $paginator->perPage() == 10 ? 'selected' : '' }}>10</option>
            <option value="25" {{ $paginator->perPage() == 25 ? 'selected' : '' }}>25</option>
            <option value="50" {{ $paginator->perPage() == 50 ? 'selected' : '' }}>50</option>
            <option value="100" {{ $paginator->perPage() == 100 ? 'selected' : '' }}>100</option>
        </select>
        <span>baris per halaman</span>
    </div>

    <!-- Right: Summary & Pagination Links -->
    <div class="flex flex-col sm:flex-row sm:items-center gap-3">
        <div class="text-slate-400">
            @if($paginator->total() > 0)
                Menampilkan <span class="font-medium text-slate-200">{{ $paginator->firstItem() }}</span> sampai <span class="font-medium text-slate-200">{{ $paginator->lastItem() }}</span> dari <span class="font-medium text-slate-200">{{ $paginator->total() }}</span> hasil
            @else
                Menampilkan <span class="font-medium text-slate-200">0</span> hasil
            @endif
        </div>

        @if ($paginator->hasPages())
        <nav class="inline-flex rounded-lg border border-slate-700 overflow-hidden shadow-xs divide-x divide-slate-700 bg-slate-950 text-xs" aria-label="Pagination">
            {{-- Previous Page Link --}}
            @if ($paginator->onFirstPage())
                <span class="px-3 py-1.5 text-slate-600 bg-slate-900/50 cursor-not-allowed select-none">‹</span>
            @else
                <a href="{{ $paginator->previousPageUrl() }}" rel="prev" class="px-3 py-1.5 text-slate-300 hover:text-white hover:bg-slate-800 transition select-none">‹</a>
            @endif

            {{-- Pagination Elements --}}
            @foreach ($elements as $element)
                {{-- "Three Dots" Separator --}}
                @if (is_string($element))
                    <span class="px-3 py-1.5 text-slate-500 bg-slate-900/50 select-none">{{ $element }}</span>
                @endif

                {{-- Array Of Links --}}
                @if (is_array($element))
                    @foreach ($element as $page => $url)
                        @if ($page == $paginator->currentPage())
                            <span class="px-3 py-1.5 bg-blue-600 text-white font-bold select-none">{{ $page }}</span>
                        @else
                            <a href="{{ $url }}" class="px-3 py-1.5 text-slate-300 hover:text-white hover:bg-slate-800 transition select-none">{{ $page }}</a>
                        @endif
                    @endforeach
                @endif
            @endforeach

            {{-- Next Page Link --}}
            @if ($paginator->hasMorePages())
                <a href="{{ $paginator->nextPageUrl() }}" rel="next" class="px-3 py-1.5 text-slate-300 hover:text-white hover:bg-slate-800 transition select-none">›</a>
            @else
                <span class="px-3 py-1.5 text-slate-600 bg-slate-900/50 cursor-not-allowed select-none">›</span>
            @endif
        </nav>
        @else
        <nav class="inline-flex rounded-lg border border-slate-700 overflow-hidden shadow-xs divide-x divide-slate-700 bg-slate-950 text-xs" aria-label="Pagination">
            <span class="px-3 py-1.5 text-slate-600 bg-slate-900/50 cursor-not-allowed select-none">‹</span>
            <span class="px-3 py-1.5 bg-blue-600 text-white font-bold select-none">1</span>
            <span class="px-3 py-1.5 text-slate-600 bg-slate-900/50 cursor-not-allowed select-none">›</span>
        </nav>
        @endif
    </div>
</div>
@endif

