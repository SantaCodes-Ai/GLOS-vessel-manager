<div class="vessel-pagination"><p>{{ $records->total() }} records · Page {{ $records->currentPage() }} of {{ $records->lastPage() }}</p>
    <nav aria-label="Pagination">
        @if($records->previousPageUrl())<a class="logout-button" href="{{ $records->previousPageUrl() }}" rel="prev">Previous</a>@endif
        @if($records->nextPageUrl())<a class="logout-button" href="{{ $records->nextPageUrl() }}" rel="next">Next</a>@endif
    </nav>
</div>
