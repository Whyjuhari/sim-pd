<?php

namespace App\Support;

use Illuminate\Http\Request;

/** Keep list context in links, never accept an arbitrary return URL. */
class OfficerSptNavigation
{
    public static function context(Request $request): array
    {
        $origin = $request->query('from');
        if (! in_array($origin, ['dashboard', 'process'], true)) {
            return [];
        }

        $allowed = $origin === 'dashboard'
            ? ['q', 'status', 'destination', 'page']
            : ['q', 'status', 'page'];
        $input = $request->query('list', []);
        $filters = [];
        foreach ($allowed as $key) {
            $value = is_array($input) ? ($input[$key] ?? null) : null;
            if (is_string($value) && mb_strlen($value) <= 100) {
                $filters[$key] = $value;
            }
        }

        return ['from' => $origin, 'list' => $filters];
    }

    public static function backUrl(Request $request, bool $needsProcessing): string
    {
        $context = self::context($request);
        $origin = $needsProcessing ? 'process' : 'dashboard';
        $filters = ($context['from'] ?? $origin) === $origin ? ($context['list'] ?? []) : [];

        return route($origin === 'process' ? 'spt-srikandi.index' : 'dashboard.officer', $filters);
    }
}
