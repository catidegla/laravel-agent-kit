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

    /*
    |--------------------------------------------------------------------------
    | Audit trail
    |--------------------------------------------------------------------------
    |
    | "What did the agent read" is the first question anyone asks after an
    | incident, and it cannot be answered later if nothing was written down at
    | the time. Every call is recorded: who it acted as, which tool, what it
    | asked for, and the identifiers it got back.
    |
    | Identifiers, not values. Storing the rows would turn this log into a
    | second copy of every record an agent ever read, in a table nobody wrote a
    | policy for.
    |
    | strict decides what happens when the sink refuses. True withholds the
    | result rather than serving something that could not be recorded, which is
    | consistent with the rest of this package and is occasionally inconvenient.
    | False serves anyway and reports the failure through the default logger.
    | Turning it off is a legitimate trade between availability and a complete
    | record, and it is yours to make.
    |
    | The default sink writes to a log channel, which needs no migration. Bind
    | your own implementation of Audit\AuditSink when a log file stops being
    | enough to query.
    |
    */

    'audit' => [
        'enabled' => true,
        'strict' => true,
        'channel' => null,
        'level' => 'info',
    ],

];
