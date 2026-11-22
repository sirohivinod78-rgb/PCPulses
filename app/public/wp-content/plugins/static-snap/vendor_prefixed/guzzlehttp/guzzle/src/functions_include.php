<?php

namespace StaticSnapVendor;

// Don't redefine the functions if included multiple times.
if (!\function_exists('StaticSnapVendor\\GuzzleHttp\\describe_type')) {
    require __DIR__ . '/functions.php';
}
