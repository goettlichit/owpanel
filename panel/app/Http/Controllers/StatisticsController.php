<?php

namespace App\Http\Controllers;

use App\Models\Site;
use App\Models\SiteVisit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * System -> Statistics (E-148): visitors and page views of the websites,
 * counted hourly on the servers from the access logs. Administrators only
 * for now. "Visitors" over several days is the sum of the days: whoever
 * comes on two days counts twice, as the panel stores no addresses.
 */
class StatisticsController extends Controller
{
    /** Days shown per choice; "months" shows the 14 kept months. */
    public const RANGES = ['7' => 7, '30' => 30, '90' => 90, 'months' => null];

    public function index(Request $request): View
    {
        Gate::authorize('viewAny', Site::class);

        $range = array_key_exists((string) $request->query('range'), self::RANGES) ? (string) $request->query('range') : '30';
        $sites = Site::orderBy('domain')->get();
        $site = $sites->firstWhere('id', (string) $request->query('site'));
        $today = Carbon::now((string) config('owpanel.timezone'))->startOfDay();

        return view('statistics.index', [
            'range' => $range,
            'site' => $site,
            'table' => $this->table($sites, $today),
            'chart' => $range === 'months' ? $this->months($site, $today) : $this->days($site, $today, self::RANGES[$range]),
            'pages' => $site === null ? null : $this->pages($site, $today, $range),
            'counted' => SiteVisit::max('updated_at'),
        ]);
    }

    /**
     * Per website: visitors today, in 7 and in 30 days, page views in 30
     * days; most visited first.
     *
     * @param  Collection<int, Site>  $sites
     * @return Collection<int, array{site: Site, today: int, week: int, month: int, pageviews: int}>
     */
    private function table(Collection $sites, Carbon $today): Collection
    {
        $sums = SiteVisit::query()
            ->where('date', '>=', $today->copy()->subDays(29)->toDateString())
            ->groupBy('site_id')
            ->select('site_id')
            ->selectRaw('SUM(CASE WHEN date = ? THEN visitors ELSE 0 END) AS today', [$today->toDateString()])
            ->selectRaw('SUM(CASE WHEN date >= ? THEN visitors ELSE 0 END) AS week', [$today->copy()->subDays(6)->toDateString()])
            ->selectRaw('SUM(visitors) AS month, SUM(pageviews) AS pageviews')
            ->get()->keyBy('site_id');

        return $sites->map(fn (Site $site) => [
            'site' => $site,
            'today' => (int) ($sums[$site->id]->today ?? 0),
            'week' => (int) ($sums[$site->id]->week ?? 0),
            'month' => (int) ($sums[$site->id]->month ?? 0),
            'pageviews' => (int) ($sums[$site->id]->pageviews ?? 0),
        ])->sortByDesc('month')->values();
    }

    /**
     * @return array{labels: list<string>, visitors: list<int>, pageviews: list<int>}
     */
    private function days(?Site $site, Carbon $today, int $count): array
    {
        $first = $today->copy()->subDays($count - 1);
        $rows = $this->scope($site)
            ->where('date', '>=', $first->toDateString())
            ->groupBy('date')->select('date')
            ->selectRaw('SUM(visitors) AS visitors, SUM(pageviews) AS pageviews')
            ->get()->keyBy(fn ($row) => substr((string) $row->date, 0, 10));

        $chart = ['labels' => [], 'visitors' => [], 'pageviews' => []];
        for ($day = $first->copy(); $day <= $today; $day->addDay()) {
            $row = $rows[$day->toDateString()] ?? null;
            $chart['labels'][] = $day->locale(app()->getLocale())->isoFormat($count > 7 ? 'DD.MM.' : 'dd, DD.MM.');
            $chart['visitors'][] = (int) ($row->visitors ?? 0);
            $chart['pageviews'][] = (int) ($row->pageviews ?? 0);
        }

        return $chart;
    }

    /**
     * @return array{labels: list<string>, visitors: list<int>, pageviews: list<int>}
     */
    private function months(?Site $site, Carbon $today): array
    {
        $first = $today->copy()->startOfMonth()->subMonths(SiteVisit::KEEP_MONTHS - 1);
        $rows = $this->scope($site)->where('date', '>=', $first->toDateString())->get(['date', 'visitors', 'pageviews'])
            ->groupBy(fn (SiteVisit $visit) => substr((string) $visit->date, 0, 7));

        $chart = ['labels' => [], 'visitors' => [], 'pageviews' => []];
        for ($month = $first->copy(); $month <= $today; $month->addMonth()) {
            $group = $rows[$month->format('Y-m')] ?? collect();
            $chart['labels'][] = $month->locale(app()->getLocale())->isoFormat('MMM YY');
            $chart['visitors'][] = (int) $group->sum('visitors');
            $chart['pageviews'][] = (int) $group->sum('pageviews');
        }

        return $chart;
    }

    /**
     * The most visited paths of one website in the chosen time, from the
     * top ten of each day.
     *
     * @return list<array{0: string, 1: int}>
     */
    private function pages(Site $site, Carbon $today, string $range): array
    {
        $first = $range === 'months'
            ? $today->copy()->startOfMonth()->subMonths(SiteVisit::KEEP_MONTHS - 1)
            : $today->copy()->subDays(self::RANGES[$range] - 1);
        $totals = [];
        foreach ($site->visits()->where('date', '>=', $first->toDateString())->pluck('pages') as $pages) {
            foreach ((array) $pages as [$path, $views]) {
                $totals[$path] = ($totals[$path] ?? 0) + $views;
            }
        }
        arsort($totals);

        return array_map(fn ($path, $views) => [(string) $path, $views], array_keys(array_slice($totals, 0, 10, true)), array_slice($totals, 0, 10, true));
    }

    /**
     * @return Builder<SiteVisit>
     */
    private function scope(?Site $site)
    {
        return $site === null ? SiteVisit::query() : SiteVisit::where('site_id', $site->id);
    }
}
