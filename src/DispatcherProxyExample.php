<?php

declare(strict_types=1);

namespace Solcloud\Proxy;

use Solcloud\Proxy\Exception\ProxyException;

/**
 * Just example how dispatcher could be implemented
 * for real implementation design your own
 */
class DispatcherProxyExample extends AbstractProxy
{

    protected $proxies = [];

    protected function run(): void
    {
        $this->anyProxyForward();
    }

    protected function anyProxyForward(): void
    {
        if (empty($this->proxies)) {
            throw new ProxyException('No proxies defined');
        }

        $proxy = $this->proxies[mt_rand(0, count($this->proxies) - 1)];
        if (!empty($proxy['ips'])) {
            $outIp = $proxy['ips'][mt_rand(0, count($proxy['ips']) - 1)];
            $this->getRequest()->setOutgoingIp($outIp);
        }

        $this->forward($proxy['url']);
    }

    public function addProxy(string $url, array $ipAddresses = []): void
    {
        $this->proxies[] = [
            'url' => $url,
            'ips' => $ipAddresses,
        ];
    }

    public function getProxies(): array
    {
        return $this->proxies;
    }

}
