<?php

declare(strict_types=1);

namespace Requestful\Http;

use Exception;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UploadedFileInterface;
use ReflectionException;
use Requestful\Futures\Promise;
use Requestful\Futures\PromiseInterface;
use Requestful\Exceptions\HttpClientException;
use Throwable;

class Client implements AsyncClientInterface
{
    /** @var array $config */
    private $config;

    /** @var ResponseFactoryInterface $factory */
    protected $factory;

    /** @var resource $mh */
    protected $mh;

    /** @var resource[] $handles */
    protected $handles = [];

    /** @var resource[] $cache */
    protected $cache = [];

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
     * @param RequestInterface $request
     * @return ResponseInterface
     * @throws ClientExceptionInterface
     */
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        try {
            // Because we are in control of the promise from start to finish,
            // we can assume that we will always return ResponseInterface.
            $response = $this->sendRequestAsync($request)->wait();
            if ($response instanceof Throwable) {
                throw $response;
            }
        } catch (Throwable $e) {
            throw new HttpClientException($e->getMessage(), $e->getCode(), $e);
        }

        return $response;
    }

    /**
     * @param RequestInterface $request
     * @return PromiseInterface
     */
    public function sendRequestAsync(RequestInterface $request): PromiseInterface
    {
        if (!isset($this->mh)) {
            $this->mh = curl_multi_init();
        }

        if (count($this->cache) > 0) {
            $resource = array_shift($this->cache);
        } else {
            $resource = curl_init();
        }

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

        $headers = $request->getHeaders();
        foreach ($headers as $key => $value) {
            $headers[$key] = implode(", ", $value);
        }

        curl_setopt_array($resource, array(
            CURLOPT_URL => $request->getUri(),
            CURLOPT_CUSTOMREQUEST => $request->getMethod(),
            CURLOPT_POSTFIELDS => $fields,
            CURLOPT_VERBOSE => false,
            CURLOPT_HEADER => false,
            CURLOPT_SAFE_UPLOAD => true,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_NOBODY => false,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_HEADERFUNCTION => [$this, "writeHeader"],
            CURLOPT_WRITEFUNCTION => [$this, "writeBody"]
        ) + $this->getConfig("curl_opts"));

        $id = (int)$resource;
        curl_multi_add_handle($this->mh, $resource);

        $promise = new Promise(
            function () use ($id) {
                while ($this->promises[$id]->getState() == PromiseInterface::PENDING) {
                    $this->tick();
                }

                return $this->promises[$id]->wait();
            },
            function () use ($id) {
                $this->close($id);
            }
        );

        $response = $this->factory->createResponse();

        $promise["HTTP_START_TIME"] = microtime(true);
        $promise["HTTP_REQUEST"] = $request;
        $promise["HTTP_RESPONSE"] = $response;

        $this->handles[$id] = $resource;
        $this->promises[$id] = $promise;

        return $promise;
    }

    /**
     * @param $resource
     * @param $header
     * @return int
     */
    protected function writeHeader($resource, $header)
    {
        $id = (int)$resource;

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

    protected function writeBody($resource, $body)
    {
        $id = (int)$resource;

        /** @var ResponseInterface $response */
        $response = $this->promises[$id]["HTTP_RESPONSE"];

        return $response->getBody()->write($body);
    }

    /**
     * Ticks the curl event loop.
     * @throws ReflectionException
     */
    protected function tick()
    {
        $status = curl_multi_exec($this->mh, $active);
        if ($status == CURLM_OK) {
            $info = curl_multi_info_read($this->mh);
            if ($info !== false) {
                $id = (int)$info["handle"];

                $this->promises[$id]["HTTP_TOTAL_TIME"] = curl_getinfo($info["handle"], CURLINFO_TOTAL_TIME);
                if ($info["result"] == CURLE_OK) {
                    /** @var ResponseInterface $response */
                    $response = $this->promises[$id]["HTTP_RESPONSE"];

                    $response->getBody()->rewind();
                    $this->promises[$id]->resolve($response);
                } else {
                    // TODO NetworkException
                    $this->promises[$id]->reject(new Exception(curl_error($info["handle"]), $info["result"]));
                }

                $this->close($id);
            }

            if ($active) {
                // Wait a short time for more activity
                curl_multi_select($this->mh, $this->getConfig("tick_interval"));
            }
        }
    }


    protected function close($id)
    {
        $success = false;
        if (isset($this->handles[$id])) {
            curl_multi_remove_handle($this->mh, $this->handles[$id]);
            curl_setopt_array($this->handles[$id], array(
                CURLOPT_HEADERFUNCTION => null,
                CURLOPT_WRITEFUNCTION => null
            ));
            curl_reset($this->handles[$id]);

            if (count($this->cache) <= $this->getConfig("cache_size")) {
                $this->cache[] = $this->handles[$id];
            } else {
                curl_close($this->handles[$id]);
            }

            unset($this->handles[$id]);
            unset($this->promises[$id]);

            $success = true;
        }

        return $success;
    }

    /**
     * @param string|null $option
     * @return mixed|null
     */
    public function getConfig($option = null)
    {
        $value = null;
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
            "curl_opts" =>[
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => 30
            ]
        );
    }
}
