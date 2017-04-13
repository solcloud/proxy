<?php

declare(strict_types=1);

namespace Solcloud\Proxy;

class ProxyEndpoint extends AbstractProxy
{

    protected function run(): void
    {
        $this->setResponse($this->getCurlRequestFactory()->fetchResponse($this->getRequest()));
    }

}
