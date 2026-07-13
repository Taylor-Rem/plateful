<?php

return [

    /*
    |--------------------------------------------------------------------------
    | AI menu import (photos / PDFs → structured menu)
    |--------------------------------------------------------------------------
    |
    | Model and guardrails for the onboarding menu-import pipeline. The caps
    | bound both what owners can upload and what an extraction is allowed to
    | write, so a bad parse can never flood a menu.
    |
    */

    'model' => env('MENU_IMPORT_MODEL', 'claude-opus-4-8'),

    'max_output_tokens' => 16000,

    // Uploads.
    'max_files' => 10,
    'max_file_kb' => 20480,
    'photo_max_dimension' => 2000,

    // Extraction sanity bounds.
    'max_categories' => 40,
    'max_items' => 400,
    'max_price_cents' => 100000, // $1,000 per item — above this it's a misread.

];
