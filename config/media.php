<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Media Disk
    |--------------------------------------------------------------------------
    |
    | The filesystem disk used to store and serve restaurant media (logos,
    | menu-item images, hero/about images, gallery photos). When left null
    | it follows the application's default filesystem disk, so production
    | automatically uses whatever bucket Laravel Cloud injects as default.
    | Set MEDIA_DISK locally (e.g. "public") to override without touching
    | the global default.
    |
    */

    'disk' => env('MEDIA_DISK') ?: env('FILESYSTEM_DISK', 'local'),

];
