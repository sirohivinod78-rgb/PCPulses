<?php

namespace StaticSnapVendor\Algolia\AlgoliaSearch\Support;

use StaticSnapVendor\Algolia\AlgoliaSearch\Algolia;
final class UserAgent
{
    private static $value;
    private static $customSegments = [];
    public static function get()
    {
        if (null === self::$value) {
            self::$value = self::getComputedValue();
        }
        return self::$value;
    }
    public static function addCustomUserAgent($segment, $version)
    {
        self::$value = null;
        self::$customSegments[\trim($segment, ' ')] = \trim($version, ' ');
    }
    private static function getComputedValue()
    {
        $ua = [];
        $segments = \array_merge(self::getDefaultSegments(), self::$customSegments);
        foreach ($segments as $segment => $version) {
            $ua[] = $segment . ' (' . $version . ')';
        }
        return \implode('; ', $ua);
    }
    private static function getDefaultSegments()
    {
        $segments = [];
        $segments['Algolia for PHP'] = Algolia::VERSION;
        $segments['PHP'] = \rtrim(\str_replace(\PHP_EXTRA_VERSION, '', \PHP_VERSION), '-');
        if (\defined('HHVM_VERSION')) {
            $segments['HHVM'] = HHVM_VERSION;
        }
        if (\interface_exists('StaticSnapVendor\\GuzzleHttp\\ClientInterface')) {
            if (\defined('\\GuzzleHttp\\ClientInterface::VERSION')) {
                $segments['Guzzle'] = \StaticSnapVendor\GuzzleHttp\ClientInterface::VERSION;
            } else {
                $segments['Guzzle'] = \StaticSnapVendor\GuzzleHttp\ClientInterface::MAJOR_VERSION;
            }
        }
        return $segments;
    }
}
