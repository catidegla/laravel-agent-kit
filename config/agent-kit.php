<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Exposed models
    |--------------------------------------------------------------------------
    |
    | Listed explicitly rather than discovered by scanning. A scan means adding
    | an attribute somewhere in the codebase silently publishes a table, and
    | the whole design here is that exposure is a deliberate act you can see in
    | one file during review.
    |
    */

    'models' => [
        // App\Models\Ticket::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Ceilings
    |--------------------------------------------------------------------------
    |
    | An upper bound applied on top of whatever each resource declares, so no
    | single attribute can raise the limit for the whole application.
    |
    */

    'max_results' => 100,

];
