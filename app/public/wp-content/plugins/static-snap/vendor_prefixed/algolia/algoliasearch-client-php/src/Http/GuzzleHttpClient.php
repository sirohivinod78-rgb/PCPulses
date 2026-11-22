<?php

namespace StaticSnapVendor\Algolia\AlgoliaSearch\Http;

use StaticSnapVendor\Algolia\AlgoliaSearch\Http\Psr7\Response;
use StaticSnapVendor\GuzzleHttp\Client as GuzzleClient;
use StaticSnapVendor\GuzzleHttp\Exception\ConnectException;
use StaticSnapVendor\GuzzleHttp\Exception\RequestException;
use StaticSnapVendor\GuzzleHttp\HandlerStack;
use StaticSnapVendor\GuzzleHttp\Middleware;
use StaticSnapVendor\Psr\Http\Message\RequestInterface;
final class GuzzleHttpClient implements HttpClientInterface
{
    private $client;
    public function __construct(GuzzleClient $client = null)
    {
        $this->client = $client ?: static::buildClient();
    }
    public function sendRequest(RequestInterface $request, $timeout, $connectTimeout)
    {
        try {
            $response = $this->client->send($request, ['timeout' => $timeout, 'connect_timeout' => $connectTimeout]);
        } catch (RequestException $e) {
            if ($e->hasResponse()) {
                return $e->getResponse();
            } else {
                return new Response(0, [], null, '1.1', $e->getMessage());
            }
        } catch (ConnectException $e) {
            return new Response(0, [], null, '1.1', $e->getMessage());
        }
        return $response;
    }
    private static function buildClient(array $config = [])
    {
        $handlerStack = new HandlerStack(\StaticSnapVendor\GuzzleHttp\Utils::chooseHandler());
        $handlerStack->push(Middleware::prepareBody(), 'prepare_body');
        $config = \array_merge(['handler' => $handlerStack], $config);
        return new GuzzleClient($config);
    }
}
