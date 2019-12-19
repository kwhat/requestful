<?php

declare(strict_types=1);

namespace Requestful\Http {

    use Requestful\Test\Http\ClientTest;
    use ReflectionException;
    use ReflectionObject;

    function curl_setopt_array($handle, array $options)
    {
        return true;
    }

    /**
     * @param resource $mh
     * @param int $still_running
     *
     * @return int
     * @throws ReflectionException
     */
    function curl_multi_exec($mh, &$still_running)
    {
        $still_running = 1;

        $ref = new ReflectionObject(ClientTest::$client);
        $method = $ref->getMethod("writeHeader");
        $method->setAccessible(true);
        $method->invoke(ClientTest::$client, ClientTest::$handle, "HTTP/1.1 200 OK\r\n");
        $method->invoke(ClientTest::$client, ClientTest::$handle, "Content-Type: application/json\r\n");

        $method = $ref->getMethod("writeBody");
        $method->setAccessible(true);
        $method->invoke(ClientTest::$client, ClientTest::$handle, "{}");

        return CURLM_OK;
    }

    /**
     * @param resource $mh
     * @param int|null $msgs_in_queue
     *
     * @return array
     */
    function curl_multi_info_read($mh, &$msgs_in_queue = null)
    {
        return array(
            "msg" => CURLMSG_DONE,
            "result" => ClientTest::$result,
            "handle" => ClientTest::$handle
        );
    }
}

namespace Requestful\Test\Http {

    use PHPUnit\Framework\MockObject\MockObject;
    use Psr\Http\Client\ClientExceptionInterface;
    use Psr\Http\Message\RequestInterface;
    use Psr\Http\Message\ResponseFactoryInterface;
    use Psr\Http\Message\ResponseInterface;
    use Psr\Http\Message\StreamInterface;
    use Requestful\Exceptions\PromiseException;
    use Requestful\Futures\PromiseInterface;
    use Requestful\Http\Client;
    use Exception;
    use PHPUnit\Framework\TestCase;
    use ReflectionException;
    use ReflectionObject;

    class ClientTest extends TestCase
    {
        /** @var Client $client */
        public static $client;

        /** @var int $result */
        public static $result = CURLE_OK;

        /** @var resource|null $handle */
        public static $handle = null;

        /**
         * @throws ClientExceptionInterface
         */
        public function testSendRequestSuccess()
        {
            /** @var MockObject|StreamInterface $body */
            $body = $this->createMock(StreamInterface::class);

            /** @var MockObject|RequestInterface $request */
            $request = $this->createMock(RequestInterface::class);

            /** @var MockObject|ResponseFactoryInterface $factory */
            $factory = $this->createMock(ResponseFactoryInterface::class);

            $body
                ->expects($this->once())
                ->method("rewind")
                ->with();

            $body
                ->expects($this->once())
                ->method("getContents")
                ->with()
                ->willReturn("");

            $request
                ->expects($this->once())
                ->method("getBody")
                ->with()
                ->willReturn($body);

            $request
                ->expects($this->once())
                ->method("getHeaders")
                ->with()
                ->willReturn($body);

            static::$client = new Client($factory);
            $this->assertNotInstanceOf(PromiseInterface::class, static::$client->sendRequest($request));
        }

        public function testSendRequestAsyncSuccess()
        {
            /** @var MockObject|StreamInterface $body */
            $body = $this->createMock(StreamInterface::class);

            /** @var MockObject|RequestInterface $request */
            $request = $this->createMock(RequestInterface::class);

            /** @var MockObject|ResponseFactoryInterface $factory */
            $factory = $this->createMock(ResponseFactoryInterface::class);

            $body
                ->expects($this->once())
                ->method("rewind")
                ->with();

            $body
                ->expects($this->once())
                ->method("getContents")
                ->with()
                ->willReturn("");

            $request
                ->expects($this->once())
                ->method("getBody")
                ->with()
                ->willReturn($body);

            $request
                ->expects($this->once())
                ->method("getHeaders")
                ->with()
                ->willReturn($body);

            static::$client = new Client($factory);
            $promise = static::$client->sendRequestAsync($request);
            $this->assertInstanceOf(PromiseInterface::class, $promise);

            $promise->wait();
        }

        public function testGetConfig()
        {
            $factory = $this->createMock(ResponseFactoryInterface::class);

            $client = new Client($factory);

            $this->assertIsArray($client->getConfig());
            $this->assertIsInt($client->getConfig("cache_size"));
            $this->assertNull($client->getConfig("invalid"));
        }

        public function testSetConfig()
        {
            $factory = $this->createMock(ResponseFactoryInterface::class);

            $client = new Client($factory);

            $size = $client->getConfig("cache_size");

            $client->setConfig("cache_size", (int)$size / 2);
            $this->assertEquals((int)$size / 2, $client->getConfig("cache_size"));

            $client->setConfig(["cache_size" => (int)$size / 3]);
            $this->assertEquals((int)$size / 3, $client->getConfig("cache_size"));
        }
    }
}
