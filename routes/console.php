<?php

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Planlagte jobber
|--------------------------------------------------------------------------
|
| Kjøres av cron på vps1 hvert minutt (`php artisan schedule:run` som
| app-brukeren). Lokalt: `php artisan schedule:work`.
|
*/

// IndexNow: varsler Bing/DuckDuckGo m.fl. om nye og endrede sider i sitemapet.
// Gjør ingenting uten INDEXNOW_KEY.
Schedule::command('indexnow:send')
    ->hourly()
    ->name('indexnow')
    ->withoutOverlapping(30);
