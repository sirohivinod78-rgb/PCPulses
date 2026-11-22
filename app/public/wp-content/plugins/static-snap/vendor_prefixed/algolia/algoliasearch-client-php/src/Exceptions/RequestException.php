<?php

namespace StaticSnapVendor\Algolia\AlgoliaSearch\Exceptions;

use StaticSnapVendor\Psr\Http\Message\RequestInterface;
class RequestException extends AlgoliaException
{
    private $request;
    public function setRequest(RequestInterface $request)
    {
        $this->request = $request;
        return $this;
    }
    public function getRequest()
    {
        return $this->request;
    }
}
