<?php

namespace App\Http\Controllers;

use App\Enums\Theme;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Language and colour scheme. Signed-in users keep them on their account,
 * on the login page they last for the session.
 */
class PreferencesController extends Controller
{
    public function locale(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'locale' => ['required', 'string', Rule::in(array_keys(config('owpanel.locales')))],
        ]);

        return $this->store($request, 'locale', $validated['locale']);
    }

    public function theme(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'theme' => ['required', Rule::enum(Theme::class)],
        ]);

        return $this->store($request, 'theme', $validated['theme']);
    }

    private function store(Request $request, string $key, string $value): RedirectResponse
    {
        $request->session()->put($key, $value);
        $request->user()?->forceFill([$key => $value])->save();

        return back();
    }
}
