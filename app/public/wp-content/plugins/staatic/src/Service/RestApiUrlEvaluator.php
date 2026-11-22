<?php

declare(strict_types=1);

namespace Staatic\WordPress\Service;

use Staatic\Vendor\Psr\Http\Message\UriInterface;
use Staatic\Crawler\UrlEvaluator\UrlEvaluatorInterface;

/**
 * Keeps WordPress REST API URLs that the crawler finds inside crawled content out of the crawl.
 *
 * WordPress core advertises its REST API on every page (the `https://api.w.org/` discovery
 * link, a `rel="alternate"` JSON link per post, page and term, and oEmbed links). Following
 * them publishes JSON copies of the API: JSON is not URL-rewritten, so those copies expose the
 * WordPress origin hostname and the `/wp-json/` index lists every active plugin's routes.
 *
 * Only URLs discovered in content are skipped; URLs handed to the crawler directly (Additional
 * URLs, subset publications, crawl URL providers) carry no extractor context and are still
 * crawled, which is how a site that needs static REST API responses opts in, provided no Excluded
 * URLs rule (such as the default `/wp-json/*`) matches them. The REST prefix is
 * matched as a path segment at any depth, so language-prefixed copies such as `/nl/wp-json/`
 * (TranslatePress, Polylang, WPML) are covered too, as are `?rest_route=` URLs used with plain
 * permalinks.
 */
final class RestApiUrlEvaluator implements UrlEvaluatorInterface
{
    /**
     * @var string
     */
    private $pathPattern;

    public function __construct(string $restPrefix = 'wp-json')
    {
        $restPrefix = trim($restPrefix, '/');
        $this->pathPattern = sprintf('~(^|/)%s(/|$)~i', preg_quote($restPrefix ?: 'wp-json', '~'));
    }

    /**
     * @param UriInterface $resolvedUrl
     * @param mixed[] $context
     */
    public function shouldCrawl($resolvedUrl, $context = []): bool
    {
        if (!isset($context['extractor'])) {
            return \true;
        }

        return !$this->isRestApiUrl($resolvedUrl);
    }

    /**
     * @param UriInterface $url
     */
    public function isRestApiUrl($url): bool
    {
        if (preg_match($this->pathPattern, rawurldecode($url->getPath())) === 1) {
            return \true;
        }
        parse_str($url->getQuery(), $query);

        return array_key_exists('rest_route', $query);
    }
}
