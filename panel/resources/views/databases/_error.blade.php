{{--
    A failed query of the database tool (E-079). It belongs to the content of
    the page, so it is a card with a red line, not a toast (E-083).
    Expects $response.
--}}
@if ($response?->error || $response?->sqlError)
    <x-card :title="__('databases.tool.error_title')" accent="red" class="mb-3">
        <div class="d-flex gap-2">
            <x-icon name="alert-circle" class="text-danger mt-1" />
            <div class="text-break">
                @if ($response->error)
                    {{ \App\Http\Controllers\DatabaseToolController::errorMessage($response) }}
                @else
                    {{ __('databases.tool.sql.error', ['number' => $response->sqlError['statement'], 'code' => $response->sqlError['code'], 'message' => $response->sqlError['message']]) }}
                @endif
            </div>
        </div>
    </x-card>
@endif
