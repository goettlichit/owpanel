<?php

namespace App\Http\Controllers;

use App\Models\ServerTask;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class ServerTaskController extends Controller
{
    public function show(ServerTask $task): View
    {
        Gate::authorize('view', $task);

        return view('tasks.show', ['task' => $task]);
    }
}
