<?php

namespace Requestful\Http;

use BadMethodCallException;
use Exception;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Requestful\Futures\PromiseException;
use Requestful\Futures\Promise;
use Requestful\Futures\PromiseInterface;
use Requestful\Http\ClientException;
use Requestful\Http\NetworkException;

class Client implements AsyncClientInterface
{
    /** @var resource[] $cache */
    protected $cache = [];

    /** @var array $config */
    protected $config;

    /** @var ResponseFactoryInterface $factory */
    protected $factory;

    /** @var resource|null $mh */
    protected $mh;

    /** @var PromiseInterface[]|array<int,array<int|string,mixed>> $promises */
    protected $promises = [];

    /**
     * @param ResponseFactoryInterface $factory
     * @param array<string, mixed> $config
     */
    public function __construct(ResponseFactoryInterface $factory, array $config = [])
    {
        $this->factory = $factory;
        $this->config = array_merge_recursive($this->getDefaultConfig(), $config);
    }

    public function __destruct()
    {
        if ($this->mh != null) {
            curl_multi_close($this->mh);
            $this->mh = null;
        }
    }

    /**
     * @inheritDoc
     *
     * @param RequestInterface $request
     *
     * @return ResponseInterface
     * @throws ClientException
     */
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        try {
            $handle = $this->getCurlHandle($request);

            $id = (int)$handle;
            $this->promises[$id] = new Promise(function (PromiseInterface $promise) use ($handle, $id) {
                if (curl_exec($handle) === false) {
                    throw new NetworkException(curl_error($handle), curl_errno($handle));
                }

                $promise->resolve($this->promises[$id]["PSR7_RESPONSE"]);
            });

            $this->promises[$id]["PSR7_REQUEST"] = $request;
            $this->promises[$id]["PSR7_RESPONSE"] = $this->factory->createResponse();
            $response = $this->promises[$id]->wait();

            $this->close($handle);
        } catch (Exception $e) {
            throw new ClientException($e->getMessage(), $e->getCode(), $e);
        }

        return $response;
    }

    /**
     * @inheritDoc
     *
     * @param RequestInterface $request
     *
     * @return PromiseInterface
     * @throws ClientException
     * @throws NetworkException
     */
    public function sendRequestAsync(RequestInterface $request): PromiseInterface
    {
        if (!function_exists("curl_multi_init")) {
            throw new BadMethodCallException("Asynchronous support is not available (curl_multi_init not found)");
        }

        if ($this->mh === null) {
            $this->mh = curl_multi_init();
        }

        $handle = $this->getCurlHandle($request);
        if (curl_multi_add_handle($this->mh, $handle) != CURLM_OK) {
            throw new NetworkException(curl_error($this->mh), curl_errno($this->mh));
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
        $this->promises[$id]["PSR7_REQUEST"] = $request;
        $this->promises[$id]["PSR7_RESPONSE"] = $this->factory->createResponse();

        return $this->promises[$id];
    }

    /**
     * @param RequestInterface $request
     *
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
                throw new NetworkException("Curl init failure");
            }
        }

        $headers = array();
        foreach ($request->getHeaders() as $key => $value) {
            $line = implode(", ", $value);
            $headers[] = "{$key}: {$line}";
        }

        curl_setopt_array(
            $handle,
            array(
                CURLOPT_URL => $request->getUri(),
                CURLOPT_CUSTOMREQUEST => $request->getMethod(),
                CURLOPT_POSTFIELDS => $this->getCurlPostFields($request),
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
     * @phan-var UploadedFileInterface $file
     *
     * @param RequestInterface $request
     *
     * @return array|string
     */
    private function getCurlPostFields(RequestInterface $request)
    {
        if ($request instanceof ServerRequestInterface && !empty($request->getUploadedFiles())) {
            $fields = $request->getAttributes();
            foreach ($request->getUploadedFiles() as $name => $file) {
                /** @var UploadedFileInterface $file */
                $fields[$name] = curl_file_create(
                    $file->getStream()->getMetadata("uri"),
                    $file->getClientMediaType(),
                    $file->getClientFilename()
                );
            }
        } elseif ($request instanceof ServerRequestInterface && !empty($request->getAttributes())) {
            $fields = $request->getAttributes();
        } else {
            $body = $request->getBody();
            $body->rewind();
            $fields = $body->getContents();
        }

        return $fields;
    }

    /**
     * @param resource $handle
     * @param string $header
     *
     * @return int
     */
    protected function writeHeader($handle, $header): int
    {
        $id = (int)$handle;

        /** @var ResponseInterface $response */
        if (preg_match('/^([a-zA-Z0-9\-]+):\s*(.*)\r?\n?$/', $header, $matches)) {
            $response = $this->promises[$id]["PSR7_RESPONSE"];
            $this->promises[$id]["PSR7_RESPONSE"] = $response
                ->withAddedHeader($matches[1], $matches[2]);
        } elseif (preg_match('/^HTTP\/([0-9.]+)\s+([0-9]{3})\s*(\S*)/i', $header, $matches)) {
            $response = $this->promises[$id]["PSR7_RESPONSE"];
            $this->promises[$id]["PSR7_RESPONSE"] = $response
                ->withProtocolVersion($matches[1])
                ->withStatus((int)$matches[2], $matches[3]);
        }

        return strlen($header);
    }

    /**
     * @param resource $handle
     * @param string $body
     *
     * @return int
     */
    protected function writeBody($handle, $body): int
    {
        $id = (int)$handle;

        /** @var ResponseInterface $response */
        $response = $this->promises[$id]["PSR7_RESPONSE"];

        return $response->getBody()->write($body);
    }

    /**
     * Ticks the curl multi event loop.
     *
     * @throws NetworkException
     */
    protected function tick()
    {
        $status = curl_multi_exec($this->mh, $active);
        if ($status == CURLM_OK) {
            $info = curl_multi_info_read($this->mh);
            if ($info !== false) {
                if (curl_multi_remove_handle($this->mh, $info["handle"]) != CURLM_OK) {
                    throw new NetworkException(curl_error($this->mh), curl_errno($this->mh));
                }

                if ($info["result"] != CURLE_OK) {
                    throw new NetworkException(curl_error($info["handle"]), $info["result"]);
                }

                $handle = $info["handle"];
                $id = (int)$handle;
                foreach (curl_getinfo($handle) as $key => $value) {
                    $name = strtoupper($key);
                    $this->promises[$id]["CURL_{$name}"] = $value;
                }

                /** @var ResponseInterface $response */
                $response = $this->promises[$id]["PSR7_RESPONSE"];

                $response->getBody()->rewind();
                $this->promises[$id]->resolve($response);

                $this->close($info["handle"]);
            }

            if ($active) {
                // Wait a short time for more activity
                curl_multi_select($this->mh, $this->getConfig("tick_interval"));
            }
        }
    }

    /**
     * Close an active curl handle.
     *
     * @param $handle
     *
     * @return bool
     */
    protected function close($handle): bool
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
     *
     * @return mixed|null
     */
    public function getConfig(?string $option = null, $default = null)
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
     * @return array<string, mixed>
     */
    protected function getDefaultConfig(): array
    {
        return array(
            "cache_size" => 15,
            "tick_interval" => 0.125,
            "curl_opts" => [
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_ENCODING => "",
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => 30
            ]
        );
    }
}
