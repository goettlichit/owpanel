<?php

namespace App\Support;

use Illuminate\Http\RedirectResponse;

/**
 * Administration or customer area (E-143, E-144). Pages shared by both use
 * the same controllers and views; route names of the customer area start
 * with "my.". Views build their links with Area::prefix(), so a customer
 * never gets a link into the administration.
 */
class Area
{
    /**
     * "my." in the customer area, "" in the administration.
     */
    public static function prefix(): string
    {
        return request()->route()?->getName() !== null && str_starts_with((string) request()->route()->getName(), 'my.') ? 'my.' : '';
    }

    public static function customer(): bool
    {
        return self::prefix() === 'my.';
    }

    /**
     * After starting a server task both areas stay on the page (E-152): the
     * button shows a spinner, and the task watcher reloads the page when the
     * task is done. $route is the name in the customer area ("my.…").
     */
    public static function afterTask(string $route, mixed $parameters): RedirectResponse
    {
        return redirect()->route(self::prefix().preg_replace('/^my\./', '', $route), $parameters);
    }
}
