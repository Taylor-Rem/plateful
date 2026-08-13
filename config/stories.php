<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Stories Content Directory
    |--------------------------------------------------------------------------
    |
    | Stories are markdown files with YAML front matter, committed to git.
    | Publishing a post is a commit + deploy — there is no database table
    | and no admin UI. Tests point this at a fixture directory.
    |
    */

    'path' => base_path('content/stories'),

];
