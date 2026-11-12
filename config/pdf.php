<?php

return [
    // Use an absolute executable path if the PHP worker has a restricted PATH.
    'qpdf_binary' => env('QPDF_BINARY', 'qpdf'),
];
