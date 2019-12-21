<?php

declare(strict_types=1);

namespace Requestful\Http;

use BadMethodCallException;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UploadedFileInterface;
use Requestful\Exceptions\NetworkException;
use Requestful\Exceptions\PromiseException;
use Requestful\Futures\Promise;
use Requestful\Futures\PromiseInterface;
use Requestful\Exceptions\ClientException;

class Client implements AsyncClientInterface
{
    /** @var resource[] $cache */
    protected $cache = [];

    /** @var array $config */
    protected $config;

    /** @var ResponseFactoryInterface $factory */
    protected $factory;

    /** @var resource $mh */
    protected $mh;

    /** @var PromiseInterface[] $promises */
    protected $promises = [];

    /**
     * @param ResponseFactoryInterface $factory
     * @param array $config
     */
    public function __construct(ResponseFactoryInterface $factory, array $config = [])
    {
        $this->factory = $factory;
        $this->config = array_merge_recursive($this->getDefaultConfig(), $config);
    }

    public function __destruct()
    {
        if (isset($this->mh)) {
            curl_multi_close($this->mh);
            unset($this->mh);
        }
    }

    /**
     * @inheritDoc
     * @param RequestInterface $request
     * @return ResponseInterface
     * @throws ClientException
     */
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        try {
            $handle = $this->getCurlHandle($request);

            $id = (int)$handle;
            $this->promises[$id] = new Promise(function (PromiseInterface $promise) use ($handle) {
                if (curl_exec($handle) === false) {
                    // @codeCoverageIgnoreStart
                    throw new NetworkException(curl_error($handle), curl_errno($handle));
                    // @codeCoverageIgnoreEnd
                }

                $promise->resolve($this->promises[(int)$handle]["HTTP_RESPONSE"]);
            });
            $this->promises[$id]["HTTP_START_TIME"] = microtime(true);
            $this->promises[$id]["HTTP_REQUEST"] = $request;
            $this->promises[$id]["HTTP_RESPONSE"] = $this->factory->createResponse();

            $response = $this->promises[$id]->wait();

            $this->close($handle);
            // @codeCoverageIgnoreStart
        } catch (NetworkException $e) {
            throw $e;
        }
        // @codeCoverageIgnoreEnd

        return $response;
    }

    /**
     * @inheritDoc
     * @param RequestInterface $request
     * @return PromiseInterface
     * @throws NetworkException
     */
    public function sendRequestAsync(RequestInterface $request): PromiseInterface
    {
        if (!function_exists("curl_multi_init")) {
            // @codeCoverageIgnoreStart
            throw new BadMethodCallException("Asynchronous support is not available (curl_multi_init not found)");
            // @codeCoverageIgnoreEnd
        }

        if (!isset($this->mh)) {
            $this->mh = curl_multi_init();
        }

        $handle = $this->getCurlHandle($request);
        if (curl_multi_add_handle($this->mh, $handle) != CURLM_OK) {
            // @codeCoverageIgnoreStart
            throw new NetworkException(curl_error($this->mh), curl_errno($this->mh));
            // @codeCoverageIgnoreEnd
        }

        $id = (int)$handle;
        $this->promises[$id] = new Promise(
            function (PromiseInterface $promise) {
                while ($promise->getState() == PromiseInterface::PENDING) {
                    $this->tick();
                }

                return $promise->wait();
            },
            function (PromiseInterface $promise) use ($handle) {
                $promise->resolve(new PromiseException("Promise was canceled"));
                $this->close($handle);
            }
        );
        $this->promises[$id]["HTTP_START_TIME"] = microtime(true);
        $this->promises[$id]["HTTP_REQUEST"] = $request;
        $this->promises[$id]["HTTP_RESPONSE"] = $this->factory->createResponse();

        return $this->promises[$id];
    }

    /**
     * @param RequestInterface $request
     * @return resource|false
     * @throws NetworkException
     */
    private function getCurlHandle(RequestInterface $request)
    {
        if (count($this->cache) > 0) {
            $handle = array_shift($this->cache);
        } else {
            $handle = curl_init();
            if ($handle === false) {
                // @codeCoverageIgnoreStart
                throw new NetworkException("Curl init failure");
                // @codeCoverageIgnoreEnd
            }
        }

        $headers = $request->getHeaders();
        foreach ($headers as $key => $value) {
            $headers[$key] = implode(", ", $value);
        }

        curl_setopt_array(
            $handle,
            array(
                CURLOPT_URL => $request->getUri(),
                CURLOPT_CUSTOMREQUEST => $request->getMethod(),
                CURLOPT_POSTFIELDS => $this->getCurlPostFields($request),
                CURLOPT_VERBOSE => true,
                CURLOPT_HEADER => false,
                CURLOPT_SAFE_UPLOAD => true,
                CURLOPT_RETURNTRANSFER => false,
                CURLOPT_NOBODY => false,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_HEADERFUNCTION => [$this, "writeHeader"],
                CURLOPT_WRITEFUNCTION => [$this, "writeBody"]
            ) + $this->getConfig("curl_opts", array())
        );

        return $handle;
    }

    /**
     * Return the curl body from the
     * @param RequestInterface $request
     * @return array|string
     */
    private function getCurlPostFields(RequestInterface $request)
    {
        if (method_exists($request, "getUploadedFiles") && !empty($request->getUploadedFiles())) {
            $fields = $request->getAttributes();
            foreach ($request->getUploadedFiles() as $name => $file) {
                /** @var UploadedFileInterface $file */
                $fields[$name] = curl_file_create(
                    $file->getStream()->getMetadata("uri"),
                    $file->getClientMediaType(),
                    $file->getClientFilename()
                );
            }
        } elseif (method_exists($request, "getAttributes") && !empty($request->getAttributes())) {
            $fields = $request->getAttributes();
        } else {
            $body = $request->getBody();
            $body->rewind();
            $fields = $body->getContents();
        }

        return $fields;
    }

    /**
     * @param $handle
     * @param $header
     * @return int
     */
    protected function writeHeader($handle, $header)
    {
        $id = (int)$handle;

        /** @var ResponseInterface $response */
        if (($pos = strpos($header, ":")) !== false) {
            $response = $this->promises[$id]["HTTP_RESPONSE"];
            $this->promises[$id]["HTTP_RESPONSE"] = $response
                ->withAddedHeader(substr($header, 0, $pos), substr($header, $pos + 1));
        } elseif (preg_match('/^HTTP\/([0-9.]+)\s+([0-9]{3})\s*(\S*)/i', $header, $matches)) {
            $response = $this->promises[$id]["HTTP_RESPONSE"];
            $this->promises[$id]["HTTP_RESPONSE"] = $response
                ->withProtocolVersion($matches[1])
                ->withStatus((int)$matches[2], $matches[3]);
        }

        return strlen($header);
    }

    protected function writeBody($handle, $body)
    {
        $id = (int)$handle;

        /** @var ResponseInterface $response */
        $response = $this->promises[$id]["HTTP_RESPONSE"];

        return $response->getBody()->write($body);
    }

    /**
     * Ticks the curl multi event loop.
     * @throws NetworkException
     */
    protected function tick()
    {
        $status = curl_multi_exec($this->mh, $active);
        if ($status == CURLM_OK) {
            $info = curl_multi_info_read($this->mh);
            if ($info !== false) {
                $handle = $info["handle"];
                $id = (int)$handle;

                $this->promises[$id]["CURL_TOTAL_TIME"] = curl_getinfo($handle, CURLINFO_TOTAL_TIME);
                $this->promises[$id]["CURL_NAMELOOKUP_TIME"] = curl_getinfo($handle, CURLINFO_NAMELOOKUP_TIME);
                $this->promises[$id]["CURL_CONNECT_TIME"] = curl_getinfo($handle, CURLINFO_CONNECT_TIME);
                $this->promises[$id]["CURL_PRETRANSFER_TIME"] = curl_getinfo($handle, CURLINFO_PRETRANSFER_TIME);
                $this->promises[$id]["CURL_STARTTRANSFER_TIME"] = curl_getinfo($handle, CURLINFO_PRETRANSFER_TIME);
                $this->promises[$id]["CURL_REDIRECT_COUNT"] = curl_getinfo($handle, CURLINFO_REDIRECT_COUNT);
                $this->promises[$id]["CURL_REDIRECT_TIME"] = curl_getinfo($handle, CURLINFO_REDIRECT_TIME);
                $this->promises[$id]["CURL_NUM_CONNECTS"] = curl_getinfo($handle, CURLINFO_NUM_CONNECTS);
                $this->promises[$id]["CURL_SSL_ENGINES"] = curl_getinfo($handle, CURLINFO_SSL_ENGINES);
                $this->promises[$id]["CURL_APPCONNECT_TIME"] = curl_getinfo($handle, CURLINFO_APPCONNECT_TIME);
                $this->promises[$id]["CURL_CERTINFO"] = curl_getinfo($handle, CURLINFO_CERTINFO);

                if (curl_multi_remove_handle($this->mh, $info["handle"]) != CURLM_OK) {
                    // @codeCoverageIgnoreStart
                    throw new NetworkException(curl_error($this->mh), curl_errno($this->mh));
                    // @codeCoverageIgnoreEnd
                }

                if ($info["result"] == CURLE_OK) {
                    /** @var ResponseInterface $response */
                    $response = $this->promises[$id]["HTTP_RESPONSE"];

                    $response->getBody()->rewind();
                    $this->promises[$id]->resolve($response);
                } else {
                    // @codeCoverageIgnoreStart
                    throw new NetworkException(curl_error($info["handle"]), $info["result"]);
                    // @codeCoverageIgnoreEnd
                }

                $this->close($info["handle"]);
            }

            if ($active) {
                // Wait a short time for more activity
                curl_multi_select($this->mh, $this->getConfig("tick_interval"));
            }
        }
    }


    protected function close($handle)
    {
        $success = curl_setopt_array(
            $handle,
            array(
                CURLOPT_HEADERFUNCTION => null,
                CURLOPT_WRITEFUNCTION => null
            )
        );

        if ($success) {
            curl_reset($handle);

            if (count($this->cache) < $this->getConfig("cache_size")) {
                $this->cache[] = $handle;
            } else {
                curl_close($handle);
            }

            unset($this->promises[(int)$handle]);
        }

        return $success;
    }

    /**
     * @param string|null $option
     * @param mixed|null $default
     * @return mixed|null
     */
    public function getConfig($option = null, $default = null)
    {
        $value = $default;
        if ($option === null) {
            $value = $this->config;
        } elseif (isset($this->config[$option])) {
            $value = $this->config[$option];
        }

        return $value;
    }

    /**
     * @param string|array $option
     * @param mixed|null $value
     */
    public function setConfig($option, $value = null)
    {
        if (is_array($option)) {
            $this->config = array_replace_recursive($this->getDefaultConfig(), $this->config, $option);
        } elseif (!is_object($option)) {
            $this->config[$option] = $value;
        }
    }

    /**
     * @return array
     */
    protected function getDefaultConfig()
    {
        return array(
            "cache_size" => 15,
            "tick_interval" => 0.125,
            "curl_opts" => [
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => 30
            ]
        );
    }
}
