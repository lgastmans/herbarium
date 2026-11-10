@if ($paginator->hasPages())
    <nav class="pagination" aria-label="Pagination">
        @if ($paginator->onFirstPage())
            <span class="pagination__step" aria-disabled="true">Previous</span>
        @else
            <button type="button" class="pagination__step" wire:click="previousPage('{{ $paginator->getPageName() }}')" rel="prev">Previous</button>
        @endif

        @foreach ($elements as $element)
            @if (is_string($element))
                <span class="pagination__gap" aria-hidden="true">{{ $element }}</span>
            @else
                @foreach ($element as $page => $url)
                    @if ($page === $paginator->currentPage())
                        <span class="pagination__page" aria-current="page">{{ $page }}</span>
                    @else
                        <button type="button" class="pagination__page" wire:click="gotoPage({{ $page }}, '{{ $paginator->getPageName() }}')" aria-label="Go to page {{ $page }}">{{ $page }}</button>
                    @endif
                @endforeach
            @endif
        @endforeach

        @if ($paginator->hasMorePages())
            <button type="button" class="pagination__step" wire:click="nextPage('{{ $paginator->getPageName() }}')" rel="next">Next</button>
        @else
            <span class="pagination__step" aria-disabled="true">Next</span>
        @endif
    </nav>
@endif
