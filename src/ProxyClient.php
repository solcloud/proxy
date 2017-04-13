<?php

declare(strict_types=1);

namespace Solcloud\Proxy;

use Solcloud\Http\Request;
use Solcloud\Http\Response;
use Solcloud\Http\Exception\HttpException;
use Solcloud\Http\Contract\IRequestDownloader;

class ProxyClient extends AbstractProxy implements IRequestDownloader
{

    /**
     * @var string
     */
    protected $urlDispatcher;

    public function fetchResponse(Request $request): Response
    {
        $this->process($request);

        return $this->getResponse();
    }

    public function process(Request $request = NULL): void
    {
        $this->setRequest($request);
        try {
            $this->forward($this->getUrlDispatcher());
        } catch (HttpException $ex) {
            throw $this->repackHttpException($ex);
        }
    }

    public function getUrlDispatcher(): string
    {
        return $this->urlDispatcher;
    }

    public function setUrlDispatcher(string $urlDispatcher): void
    {
        $this->urlDispatcher = $urlDispatcher;
    }


}
