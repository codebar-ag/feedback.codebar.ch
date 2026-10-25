<?php

declare(strict_types=1);

return [

    /*
     * NightwatchIssuesCheck asks Nightwatch for open issues of this application.
     * The check is only registered once api_token and application_id are set.
     */
    'nightwatch' => [
        'url' => env('NIGHTWATCH_API_URL', 'https://nightwatch.laravel.com'),
        // OAuth token from `php artisan nightwatch:login` (valid one year).
        'api_token' => env('NIGHTWATCH_API_TOKEN'),
        'application_id' => env('NIGHTWATCH_APPLICATION_ID'),
        // Optional: only count issues of this environment (e.g. production).
        'environment_id' => env('NIGHTWATCH_ENVIRONMENT_ID'),
    ],

    /*
     * codebar analytics (analytics.codebar.ch) on the feedback page, production only.
     * The tracker sends the page URL; analytics drops the query string (the link signature).
     */
    'codebar_analytics' => [
        'url' => env('CODEBAR_ANALYTICS_URL'),
        'environments' => ['production'],
    ],

];
