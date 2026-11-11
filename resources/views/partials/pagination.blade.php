<div class="p-3 sm:p-4 border-t border-slate-800 bg-slate-900/60 text-xs text-slate-400">
    @php
        $rawPerPage = request('per_page');
        $isAll = in_array(strtolower((string)$rawPerPage), ['all', 'semua'], true);
        $selectedPerPage = $isAll ? 'all' : (in_array((int)$rawPerPage, [10, 25, 50, 100], true) ? (int)$rawPerPage : $paginator->perPage());
    @endphp

    {{-- MOBILE LAYOUT (< sm / < 640px) --}}
    <div class="flex flex-col gap-2.5 sm:hidden">
        {{-- Row 1: Per Page selector & Results Count --}}
        <div class="flex items-center justify-between gap-2">
            <div class="flex items-center gap-1.5">
                <span class="text-slate-400">Show</span>
                <select id="vesselsPerPageSelectMobile" onchange="const u = new URL(window.location.href); u.searchParams.set('per_page', this.value); u.searchParams.set('page', 1); window.location.href = u.toString();"
                    class="px-2 py-1 rounded-lg bg-slate-950 border border-slate-700 text-slate-200 text-xs focus:outline-none focus:border-teal-400 font-medium cursor-pointer">
                    <option value="10" {{ $selectedPerPage === 10 ? 'selected' : '' }}>10</option>
                    <option value="25" {{ $selectedPerPage === 25 ? 'selected' : '' }}>25</option>
                    <option value="50" {{ $selectedPerPage === 50 ? 'selected' : '' }}>50</option>
                    <option value="100" {{ $selectedPerPage === 100 ? 'selected' : '' }}>100</option>
                    <option value="all" {{ $selectedPerPage === 'all' ? 'selected' : '' }}>All</option>
                </select>
                <span class="text-slate-400">rows per page</span>
            </div>

            <div class="text-[11px] text-slate-400 font-sans text-right truncate">
                @if($paginator->total() > 0)
                    Showing <span class="font-semibold text-slate-200">{{ number_format($paginator->firstItem()) }}–{{ number_format($paginator->lastItem()) }}</span> of <span class="font-semibold text-slate-200">{{ number_format($paginator->total()) }}</span> vessels
                @else
                    Showing <span class="font-semibold text-slate-200">0</span> vessels
                @endif
            </div>
        </div>

        {{-- Row 2: Touch-Friendly Mobile Pagination Controls --}}
        @if ($paginator->hasPages())
        <nav class="flex items-center justify-between gap-1 w-full pt-1" aria-label="Mobile Pagination Navigation">
            {{-- First & Prev --}}
            <div class="flex items-center gap-1">
                @if ($paginator->onFirstPage())
                    <span class="px-2.5 py-1.5 rounded-lg border border-slate-800 bg-slate-950/60 text-slate-600 text-xs cursor-not-allowed select-none" title="First Page">&laquo;</span>
                    <span class="px-2.5 py-1.5 rounded-lg border border-slate-800 bg-slate-950/60 text-slate-600 text-xs cursor-not-allowed select-none font-medium flex items-center gap-0.5">
                        <span>&lsaquo;</span> <span>Prev</span>
                    </span>
                @else
                    <a href="{{ $paginator->url(1) }}" class="px-2.5 py-1.5 rounded-lg border border-slate-700 bg-slate-900 text-slate-300 hover:text-white hover:bg-slate-800 transition text-xs select-none" title="First Page">&laquo;</a>
                    <a href="{{ $paginator->previousPageUrl() }}" rel="prev" class="px-2.5 py-1.5 rounded-lg border border-slate-700 bg-slate-900 text-slate-200 hover:text-white hover:bg-slate-800 transition text-xs font-medium flex items-center gap-0.5 select-none">
                        <span>&lsaquo;</span> <span>Prev</span>
                    </a>
                @endif
            </div>

            {{-- Center Page Badge --}}
            <div class="text-[11px] font-mono text-slate-300 px-2 py-1 rounded-md bg-slate-950 border border-slate-800 shrink-0">
                <span class="font-bold text-teal-400">{{ $paginator->currentPage() }}</span>
                <span class="text-slate-500">/</span>
                <span class="text-slate-400">{{ $paginator->lastPage() }}</span>
            </div>

            {{-- Next & Last --}}
            <div class="flex items-center gap-1">
                @if ($paginator->hasMorePages())
                    <a href="{{ $paginator->nextPageUrl() }}" rel="next" class="px-2.5 py-1.5 rounded-lg border border-slate-700 bg-slate-900 text-slate-200 hover:text-white hover:bg-slate-800 transition text-xs font-medium flex items-center gap-0.5 select-none">
                        <span>Next</span> <span>&rsaquo;</span>
                    </a>
                    <a href="{{ $paginator->url($paginator->lastPage()) }}" class="px-2.5 py-1.5 rounded-lg border border-slate-700 bg-slate-900 text-slate-300 hover:text-white hover:bg-slate-800 transition text-xs select-none" title="Last Page">&raquo;</a>
                @else
                    <span class="px-2.5 py-1.5 rounded-lg border border-slate-800 bg-slate-950/60 text-slate-600 text-xs cursor-not-allowed select-none font-medium flex items-center gap-0.5">
                        <span>Next</span> <span>&rsaquo;</span>
                    </span>
                    <span class="px-2.5 py-1.5 rounded-lg border border-slate-800 bg-slate-950/60 text-slate-600 text-xs cursor-not-allowed select-none" title="Last Page">&raquo;</span>
                @endif
            </div>
        </nav>
        @endif
    </div>

    {{-- TABLET & DESKTOP LAYOUT (>= sm / >= 640px) --}}
    <div class="hidden sm:flex sm:items-center sm:justify-between w-full gap-4">
        <!-- Left: Per page selector -->
        <div class="flex items-center gap-2 shrink-0">
            <span class="text-slate-400">Show</span>
            <select id="vesselsPerPageSelect" onchange="const u = new URL(window.location.href); u.searchParams.set('per_page', this.value); u.searchParams.set('page', 1); window.location.href = u.toString();"
                class="px-2.5 py-1 rounded-lg bg-slate-950 border border-slate-700 text-slate-200 text-xs focus:outline-none focus:border-teal-400 focus:ring-1 focus:ring-teal-400 font-medium cursor-pointer">
                <option value="10" {{ $selectedPerPage === 10 ? 'selected' : '' }}>10</option>
                <option value="25" {{ $selectedPerPage === 25 ? 'selected' : '' }}>25</option>
                <option value="50" {{ $selectedPerPage === 50 ? 'selected' : '' }}>50</option>
                <option value="100" {{ $selectedPerPage === 100 ? 'selected' : '' }}>100</option>
                <option value="all" {{ $selectedPerPage === 'all' ? 'selected' : '' }}>All</option>
            </select>
            <span class="text-slate-400">rows per page</span>
        </div>

        <!-- Right: Summary & Numbered Pagination Links -->
        <div class="flex items-center gap-3 shrink-0">
            <div class="text-slate-400 font-sans whitespace-nowrap text-xs">
                @if($paginator->total() > 0)
                    Showing <span class="font-medium text-slate-200">{{ number_format($paginator->firstItem()) }}–{{ number_format($paginator->lastItem()) }}</span> of <span class="font-medium text-slate-200">{{ number_format($paginator->total()) }}</span> vessels
                @else
                    Showing <span class="font-medium text-slate-200">0</span> vessels
                @endif
            </div>

            @if ($paginator->hasPages())
            <nav class="inline-flex rounded-lg border border-slate-700 overflow-hidden shadow-xs divide-x divide-slate-700 bg-slate-950 text-xs" aria-label="Pagination Navigation">
                {{-- First Page Link --}}
                @if ($paginator->onFirstPage())
                    <span class="px-2.5 py-1.5 text-slate-600 bg-slate-900/50 cursor-not-allowed select-none" title="First Page">&laquo;</span>
                @else
                    <a href="{{ $paginator->url(1) }}" class="px-2.5 py-1.5 text-slate-300 hover:text-white hover:bg-slate-800 transition select-none" title="First Page">&laquo;</a>
                @endif

                {{-- Previous Page Link --}}
                @if ($paginator->onFirstPage())
                    <span class="px-3 py-1.5 text-slate-600 bg-slate-900/50 cursor-not-allowed select-none" title="Previous">&lsaquo;</span>
                @else
                    <a href="{{ $paginator->previousPageUrl() }}" rel="prev" class="px-3 py-1.5 text-slate-300 hover:text-white hover:bg-slate-800 transition select-none" title="Previous">&lsaquo;</a>
                @endif

                {{-- Pagination Elements --}}
                @foreach ($elements as $element)
                    {{-- "Three Dots" Separator --}}
                    @if (is_string($element))
                        <span class="px-2.5 sm:px-3 py-1.5 text-slate-500 bg-slate-900/50 select-none">{{ $element }}</span>
                    @endif

                    {{-- Array Of Links --}}
                    @if (is_array($element))
                        @foreach ($element as $page => $url)
                            @if ($page == $paginator->currentPage())
                                <span class="px-2.5 sm:px-3 py-1.5 bg-teal-600 text-white font-bold select-none">{{ $page }}</span>
                            @elseif (abs($page - $paginator->currentPage()) > 1 && $page != 1 && $page != $paginator->lastPage())
                                {{-- Hide distant pages on tablet (sm:hidden md:inline-block) so it doesn't wrap --}}
                                <a href="{{ $url }}" class="hidden md:inline-block px-2.5 sm:px-3 py-1.5 text-slate-300 hover:text-white hover:bg-slate-800 transition select-none">{{ $page }}</a>
                            @else
                                <a href="{{ $url }}" class="px-2.5 sm:px-3 py-1.5 text-slate-300 hover:text-white hover:bg-slate-800 transition select-none">{{ $page }}</a>
                            @endif
                        @endforeach
                    @endif
                @endforeach

                {{-- Next Page Link --}}
                @if ($paginator->hasMorePages())
                    <a href="{{ $paginator->nextPageUrl() }}" rel="next" class="px-3 py-1.5 text-slate-300 hover:text-white hover:bg-slate-800 transition select-none" title="Next">&rsaquo;</a>
                @else
                    <span class="px-3 py-1.5 text-slate-600 bg-slate-900/50 cursor-not-allowed select-none" title="Next">&rsaquo;</span>
                @endif

                {{-- Last Page Link --}}
                @if ($paginator->currentPage() >= $paginator->lastPage())
                    <span class="px-2.5 py-1.5 text-slate-600 bg-slate-900/50 cursor-not-allowed select-none" title="Last Page">&raquo;</span>
                @else
                    <a href="{{ $paginator->url($paginator->lastPage()) }}" class="px-2.5 py-1.5 text-slate-300 hover:text-white hover:bg-slate-800 transition select-none" title="Last Page">&raquo;</a>
                @endif
            </nav>
            @else
            <nav class="inline-flex rounded-lg border border-slate-700 overflow-hidden shadow-xs divide-x divide-slate-700 bg-slate-950 text-xs" aria-label="Pagination Navigation">
                <span class="px-2.5 py-1.5 text-slate-600 bg-slate-900/50 cursor-not-allowed select-none" title="First Page">&laquo;</span>
                <span class="px-3 py-1.5 text-slate-600 bg-slate-900/50 cursor-not-allowed select-none" title="Previous">&lsaquo;</span>
                <span class="px-3 py-1.5 bg-teal-600 text-white font-bold select-none">1</span>
                <span class="px-3 py-1.5 text-slate-600 bg-slate-900/50 cursor-not-allowed select-none" title="Next">&rsaquo;</span>
                <span class="px-2.5 py-1.5 text-slate-600 bg-slate-900/50 cursor-not-allowed select-none" title="Last Page">&raquo;</span>
            </nav>
            @endif
        </div>
    </div>
</div>
