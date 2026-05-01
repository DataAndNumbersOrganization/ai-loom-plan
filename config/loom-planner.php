<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Tech Stack Description
    |--------------------------------------------------------------------------
    |
    | Override the tech stack description injected into the context prompt.
    | Set to null for the built-in default.
    |
    */
    'tech_stack' => env('LOOM_PLAN_TECH_STACK', null),

    /*
    |--------------------------------------------------------------------------
    | Application Name
    |--------------------------------------------------------------------------
    */
    'app_name' => env('LOOM_PLAN_APP_NAME', null),

    /*
    |--------------------------------------------------------------------------
    | Output Directory
    |--------------------------------------------------------------------------
    */
    'output_dir' => env('LOOM_PLAN_OUTPUT_DIR', 'docs-and-plans/loom'),

    /*
    |--------------------------------------------------------------------------
    | Templates Directory
    |--------------------------------------------------------------------------
    |
    | Path to a directory containing prompt Blade templates.
    | Leave as null to use the package's bundled templates.
    | If you publish templates with `vendor:publish --tag=loom-planner-templates`,
    | set this to `resource_path('views/vendor/loom-planner')` to load them.
    |
    */
    'templates_dir' => null,

];
