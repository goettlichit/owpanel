{{-- Rows of one result as a table. Expects $result (App\Databases\QueryResult). --}}
<div class="table-responsive">
    <table class="table table-sm table-striped table-hover card-table ow-db-table">
        <thead>
            <tr>
                @foreach ($result->columns as $column)
                    <th scope="col" @class(['text-end' => $column['type'] === 'number'])>{{ $column['name'] }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach ($result->rows as $row)
                <tr>
                    @foreach ($row as $i => $cell)
                        <td @class(['text-end' => $result->columns[$i]['type'] === 'number'])><x-db-cell :value="$cell" :number="$result->columns[$i]['type'] === 'number'" /></td>
                    @endforeach
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
