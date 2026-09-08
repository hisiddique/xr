<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Dispatch chunk size
    |--------------------------------------------------------------------------
    |
    | How many recipients a single ProcessStatementDispatchRunJob invocation
    | processes before re-dispatching itself. Kept small so each invocation
    | stays well under the job timeout and dompdf memory stays bounded on
    | shared hosting.
    |
    */

    'dispatch_chunk' => (int) env('STATEMENTS_DISPATCH_CHUNK', 20),

    /*
    |--------------------------------------------------------------------------
    | Mail throttle (microseconds)
    |--------------------------------------------------------------------------
    |
    | Optional pause between individual statement sends, to stay under
    | shared-hosting SMTP rate limits. 0 disables it.
    |
    */

    'mail_throttle_us' => (int) env('STATEMENTS_MAIL_THROTTLE_US', 0),

];
