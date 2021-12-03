<?php

declare(strict_types=1);

namespace Solcloud\Proxy;

use InvalidArgumentException;
use Solcloud\Curl\CurlRequest;
use Solcloud\Http\Contract\IRequestDownloader;
use Solcloud\Http\Exception\HttpException;
use Solcloud\Http\Request;
use Solcloud\Http\Response;
use Solcloud\Proxy\Exception\InternalProxyException;
use Solcloud\Proxy\Exception\ProxyException;
use Throwable;

abstract class AbstractProxy
{

    /**
     * @var Request
     */
    private $request;

    /**
     * @var Response
     */
    private $response;

    /**
     * @var IRequestDownloader
     */
    private $curlRequestFactory;

    /**
     * @var string
     */
    private $requestPostKey = 'request';

    /**
     * @var int
     */
    private $subsequentProxyProcessingOverheadMilliseconds = 1000;

    /**
     * @var int
     */
    private $numberOfHops = 1;

    /**
     * @var string
     */
    private $internalCommunicationUrl = '';

    /**
     * @param int $numberOfInternalProxyAfterThisProxy Specify how many internal proxy is after this proxy
     * Use for timeout multiplication between internal proxies to carry timeout from target, for Client set to > 0
     * with request connect timeout 10 sec and request timeout 20 sec, whole request will have *maximum* timeout of
     * connectTimeoutMs + (($numberOfInternalProxyAfterThisProxy + 1) * (connectTimeoutMs + requestTimeoutMs + $subsequentProxyProcessingOverheadMilliseconds)) ms,
     * so eg. ProxyClient connecting to one Dispatcher that connect to one Endpoint that making final request to target will have *maximum*
     * of 103 sec [10000 + ((2 + 1) * (10000 + 20000 + 1000))] / 1000, so it is good idea to have internal proxies on network
     * with low latency and many php threats so only Endpoint is waiting maximum timeout of (connectTimeoutMs + requestTimeoutMs) for target website
     *
     * If on Client it is set to 0 although there are some Proxy after Client, than Client timeout on internal channel (unless you have really fast internal intercom and/or high connectTimeoutMs)
     * after maximum of (connectTimeoutMs + requestTimeoutMs) no matter what other proxies in chain are doing
     *   -->  this is kinda BC compatible (v1.0) solution for consistent timeouts from Request but unless you have really fast internal intercom and/or high connectTimeoutMs
     *        you loose information about target url being timeout (you get dispatcher url timeout) and also exception is instanceof InternalProxyException
     */
    public function __construct(int $numberOfInternalProxyAfterThisProxy, ?IRequestDownloader $requestDownloader = null)
    {
        if ($numberOfInternalProxyAfterThisProxy < 0) {
            throw new InvalidArgumentException('Value of $numberOfInternalProxyAfterThisProxy should be bigger or equal 0');
        }

        $this->setResponse(new Response);
        $this->curlRequestFactory = $requestDownloader ?? new CurlRequest;
        $this->numberOfHops = $numberOfInternalProxyAfterThisProxy + 1;
        if ($numberOfInternalProxyAfterThisProxy === 0) {
            $this->subsequentProxyProcessingOverheadMilliseconds = 0;
        }
    }

    /**
     * Process Request and print Response, please dont print anything after or we will add exit() :)
     * @param Request $request if NULL Request will be parsed from POST data using requestPostKey
     */
    public function process(Request $request = null): void
    {
        try {
            $this->setRequest($request === null ? $this->parseRequest() : $request);

            $this->run();
        } catch (Throwable $ex) {
            if ($ex instanceof HttpException) {
                $this->response->setException($this->repackHttpException($ex));
            } else {
                $this->response->setException(new ProxyException($ex->getMessage(), $ex->getCode(), $ex));
            }
        }

        $this->printResponse();
    }

    /**
     * Repack some interesting exception
     * @return HttpException
     */
    protected function repackHttpException(HttpException $ex): HttpException
    {
        if (!($ex instanceof InternalProxyException) && $ex->getLastUrl() !== '' && $ex->getLastUrl() === $this->internalCommunicationUrl) { // if last exception was on internal urls
            $ex = new InternalProxyException($ex->getMessage(), $ex->getCode(), $ex, $ex->getLastUrl(), $ex->getLastIP());
        }

        return $ex;
    }

    /**
     * Hook function called from process(), mostly for setting Response object either directly or by forwarding
     */
    protected function run(): void
    {
        // empty hook
    }

    /**
     * Try parse Request from POST data
     * @throws ProxyException
     */
    protected function parseRequest(): Request
    {
        if (empty($_POST[$this->requestPostKey])) {
            throw new ProxyException('Request not found in POST');
        }

        $request = @unserialize($_POST[$this->requestPostKey]);
        if ($request === false) {
            throw new ProxyException('Unserialization of Request failed');
        }

        return $request;
    }

    /**
     * Send POST request with Request object to given url, parsing Response object from (hopefully serialized) response
     * @param string $url intercom url
     * @param array  $additionalData additional POST data for intercom
     */
    protected function forward(string $url, array $additionalData = [])
    {
        $this->internalCommunicationUrl = $url;

        $postFields = array_merge(
            $additionalData
            , [
                $this->requestPostKey => serialize($this->request),
            ]
        );

        $response = $this->curlRequestFactory->fetchResponse($this->createCommunicationRequest($url, $postFields));

        $this->setResponseFromSerialized($response);
    }

    /**
     * Create POST Request object used for intercom
     */
    private function createCommunicationRequest(string $url, array $postFields): Request
    {
        $request = new Request;
        $request
            ->setMethod('POST')
            ->setUrl($url)
            ->setPostFields($postFields)
            ->setFollowLocation(true)
            ->setVerifyHost($this->getRequest()->getVerifyHost())
            ->setVerifyPeer($this->getRequest()->getVerifyPeer())
            ->setConnectionTimeoutSec($this->getRequest()->getConnectionTimeoutSec())
            ->setRequestTimeoutMs(
                (int) floor(
                    $this->numberOfHops * (
                        $this->getRequest()->getConnectionTimeoutMs()
                        + $this->getRequest()->getRequestTimeoutMs()
                        + $this->getSubsequentProxyProcessingOverheadMilliseconds()
                    )
                )
            )
        ;

        return $request;
    }

    /**
     * Print serialized response object
     */
    protected function printResponse()
    {
        $body = $this->getResponse()->getBody();
        $this->getResponse()->setBody('');
        header('x-solcloud-proxy: ' . base64_encode(serialize($this->getResponse())));
        echo $body;
        $this->getResponse()->setBody($body);
    }

    /**
     * Try to set "target" Response by inspecting $internalResponse
     * @throws ProxyException or subclass - if unserializition failed or Response has exception
     */
    protected function setResponseFromSerialized(Response $internalResponse): void
    {
        $data = $internalResponse->getLastHeadersFormatted()['x-solcloud-proxy'] ?? false;
        if ($data) {
            $data = base64_decode($data, true);
        }
        if ($data === false) {
            throw new ProxyException('Decoding of Response failed');
        }

        $response = @unserialize($data);
        if ($response === false) {
            throw new ProxyException('Unserialization of Response failed');
        }

        /** @var Response $response */
        $response->setBody($internalResponse->getBody());
        $this->setResponse($response);
    }

    protected function setRequest(Request $request): void
    {
        $this->request = $request;
    }

    protected function getRequest(): Request
    {
        return $this->request;
    }

    /**
     * @throws HttpException if $response getException() has one
     */
    protected function setResponse(Response $response): void
    {
        $responseException = $response->getException();
        if ($responseException !== null && $responseException instanceof HttpException) {
            throw $responseException;
        }

        $this->response = $response;
    }

    /**
     * Set value based on connection latency and php time overhead from script start to performing internal request,
     * if script is performing time intensive task before proxy forward (db,api,..) increase this value on proxy before this proxy and before that and before ...
     * also if script is doing something after response arrived include this time too
     * Default value (1s) should be good, so unless you know what you are doing stay away from setting manually
     * If you decide to set it manually make sure you are setting good value for all proxies in whole proxy chain
     * @param int $subsequentProxyProcessingOverheadMilliseconds
     */
    public function setSubsequentProxyProcessingOverheadMilliseconds(int $subsequentProxyProcessingOverheadMilliseconds): void
    {
        if ($subsequentProxyProcessingOverheadMilliseconds <= 0) {
            throw new InvalidArgumentException('Error $subsequentProxyProcessingOverheadMilliseconds should be bigger than 0');
        }

        $this->subsequentProxyProcessingOverheadMilliseconds = $subsequentProxyProcessingOverheadMilliseconds;
    }

    public function getResponse(): Response
    {
        return $this->response;
    }

    protected function getCurlRequestFactory(): IRequestDownloader
    {
        return $this->curlRequestFactory;
    }

    public function getSubsequentProxyProcessingOverheadMilliseconds(): int
    {
        return $this->subsequentProxyProcessingOverheadMilliseconds;
    }

}
