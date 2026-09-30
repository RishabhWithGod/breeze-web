<?php

/*
|--------------------------------------------------------------------------
| Feature switches
|--------------------------------------------------------------------------
|
| Built, tested, and kept out of sight until it is switched on. Off, a feature
| has no menu entry and its addresses answer "not found".
*/

return [

    /*
     * The Estimate Builder and the Estimate Review and Approval screen it hands
     * estimates to: pricing an estimate row by row, then sending it for approval.
     */
    'estimate_builder' => (bool) env('FEATURE_ESTIMATE_BUILDER', false),
];
