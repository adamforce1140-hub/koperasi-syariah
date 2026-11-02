<?php

namespace App\Http\Middleware;

use App\Services\NavigationService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ShareNavigationMenu
{
    public function handle(Request $request, Closure $next): Response
    {
        if (auth()->check()) {
            $navMenu = NavigationService::getMenu(auth()->user()->role);
            view()->share('navMenu', $navMenu);
        }

        return $next($request);
    }
}
