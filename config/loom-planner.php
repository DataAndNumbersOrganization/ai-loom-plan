<?php

return [

    /*
    |--------------------------------------------------------------------------
    | AI Provider & Model
    |--------------------------------------------------------------------------
    */
    'provider' => env('LOOM_PLAN_AI_PROVIDER', 'anthropic'),
    'model' => env('LOOM_PLAN_AI_MODEL', 'claude-sonnet-4-6'),

    /*
    |--------------------------------------------------------------------------
    | Max Tokens
    |--------------------------------------------------------------------------
    */
    'max_tokens' => env('LOOM_PLAN_MAX_TOKENS', 8000),

    /*
    |--------------------------------------------------------------------------
    | Tech Stack Description
    |--------------------------------------------------------------------------
    |
    | Override the tech stack description injected into AI prompts.
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
