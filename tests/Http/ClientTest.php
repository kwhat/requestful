<?php

declare(strict_types=1);

namespace Requestful\Http {

    use Requestful\Test\Http\ClientTest;
    use ReflectionException;
    use ReflectionObject;

    /*
    function curl_setopt_array($handle, array $options)
    {
        return true;
    }


    function curl_getinfo($ch, $opt = null)
    {
        return array(
            "handle"

        );
    }
    */

    /**
     * @param resource $mh
     * @param int $still_running
     *
     * @return int
     */
    function curl_multi_exec($mh, &$still_running)
    {
        $still_running = 1;

        /*
        $ref = new ReflectionObject(ClientTest::$client);
        $method = $ref->getMethod("writeHeader");
        $method->setAccessible(true);
        $method->invoke(ClientTest::$client, ClientTest::$handle, "HTTP/1.1 200 OK\r\n");
        $method->invoke(ClientTest::$client, ClientTest::$handle, "Content-Type: application/json\r\n");

        $method = $ref->getMethod("writeBody");
        $method->setAccessible(true);
        $method->invoke(ClientTest::$client, ClientTest::$handle, "{}");
        //*/

        return CURLM_OK;
    }

    /**
     * @param resource $mh
     * @param int|null $msgs_in_queue
     *
     * @return array
    function curl_multi_info_read($mh, &$msgs_in_queue = null)
    {
        return array(
            "msg" => CURLMSG_DONE,
            "result" => CURLE_OK,
            "handle" => curl_init()
        );
    }
    */
}

namespace Requestful\Test\Http {

    use PHPUnit\Framework\MockObject\MockObject;
    use Psr\Http\Client\ClientExceptionInterface;
    use Psr\Http\Message\RequestInterface;
    use Psr\Http\Message\ResponseFactoryInterface;
    use Psr\Http\Message\StreamInterface;
    use Requestful\Futures\PromiseInterface;
    use Requestful\Http\Client;
    use PHPUnit\Framework\TestCase;

    class ClientTest extends TestCase
    {
        /** @var Client $subject */
        public static $subject;

        public function setUp(): void
        {
            parent::setUp();

            /** @var MockObject|ResponseFactoryInterface $factory */
            $factory = $this->createMock(ResponseFactoryInterface::class);

            static::$subject = new Client($factory);
        }

        /**
         * @throws ClientExceptionInterface
         */
        public function testSendRequestSuccess()
        {
            /** @var MockObject|StreamInterface $body */
            $body = $this->createMock(StreamInterface::class);

            /** @var MockObject|RequestInterface $request */
            $request = $this->createMock(RequestInterface::class);

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
                ->willReturn([]);

            //static::$client = new Client($factory);
            //$this->assertNotInstanceOf(PromiseInterface::class, static::$client->sendRequest($request));

            static::$subject->sendRequest($request);
            //$this->assertInstanceOf(ResponseInterface::class, $promise);
        }

        public function testSendRequestAsyncSuccess()
        {
            /** @var MockObject|StreamInterface $body */
            $body = $this->createMock(StreamInterface::class);

            /** @var MockObject|RequestInterface $request */
            $request = $this->createMock(RequestInterface::class);

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
                ->willReturn([]);

            $promise = static::$subject->sendRequestAsync($request);
            $this->assertInstanceOf(PromiseInterface::class, $promise);

            $promise->wait();
        }

        public function testGetConfig()
        {
            $this->assertIsArray(static::$subject->getConfig());
            $this->assertIsInt(static::$subject->getConfig("cache_size"));
            $this->assertNull(static::$subject->getConfig("invalid"));
        }

        public function testSetConfig()
        {
            $size = static::$subject->getConfig("cache_size");

            static::$subject->setConfig("cache_size", (int)$size / 2);
            $this->assertEquals((int)$size / 2, static::$subject->getConfig("cache_size"));

            static::$subject->setConfig(["cache_size" => (int)$size / 3]);
            $this->assertEquals((int)$size / 3, static::$subject->getConfig("cache_size"));
        }
    }
}
