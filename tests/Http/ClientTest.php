<?php

declare(strict_types=1);

namespace Requestful\Http {

    use PHPUnit\Framework\TestCase;
    use ReflectionException;
    use ReflectionObject;
    use Requestful\Test\Http\ClientTest;

    function curl_multi_init()
    {
        TestCase::once();
        return ClientTest::$mh;
    }

    function curl_init()
    {
        return 2;
    }

    function curl_setopt_array($ch, array $options)
    {
        TestCase::assertEquals(2, $ch);
        TestCase::assertNotEmpty($options);
        return true;
    }

    function curl_multi_add_handle($mh, $ch)
    {
        TestCase::assertEquals(ClientTest::$mh, $mh);
        TestCase::assertEquals(2, $ch);
        return 0;
    }

    function curl_getinfo($ch, $opt = null)
    {
        TestCase::assertEquals(2, $ch);

        switch ($opt) {
            case CURLINFO_TOTAL_TIME:
                return 5.125;

            default:
                TestCase::fail("Unexpected curl opt");
        }

        return array();
    }

    function curl_multi_remove_handle($mh, $ch)
    {
        TestCase::assertEquals(ClientTest::$mh, $mh);
        TestCase::assertEquals(2, $ch);

        return 0;
    }

    function curl_reset($ch)
    {
        TestCase::assertEquals(2, $ch);
    }

    function curl_close($ch)
    {
        TestCase::assertEquals(2, $ch);
    }

    /**
     * @param resource $mh
     * @param int $still_running
     *
     * @return int
     */
    function curl_multi_exec($mh, &$still_running)
    {
        TestCase::assertEquals(ClientTest::$mh, $mh);

        try {
            $ref = new ReflectionObject(ClientTest::$subject);
            $method = $ref->getMethod("writeHeader");
            $method->setAccessible(true);
            $method->invoke(ClientTest::$subject, 2, "HTTP/1.1 200 OK\r\n");
            $method->invoke(ClientTest::$subject, 2, "Content-Type: text/plain\r\n");

            $method = $ref->getMethod("writeBody");
            $method->setAccessible(true);
            $method->invoke(ClientTest::$subject, 2, "stuff and things");
        } catch (ReflectionException $e) {
            TestCase::fail($e->getMessage());
        }

        $still_running = 0;
        return CURLM_OK;
    }

    function curl_multi_close($hm)
    {
        TestCase::assertTrue(isset($hm));
    }

    /**
     * @param resource $mh
     * @param int|null $msgs_in_queue
     *
     * @return array
     */
    function curl_multi_info_read($mh, &$msgs_in_queue = null)
    {
        $msgs_in_queue = 0;
        return array(
            "msg" => CURLMSG_DONE,
            "result" => CURLE_OK,
            "handle" => 2
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
    use Requestful\Futures\PromiseInterface;
    use Requestful\Http\Client;
    use PHPUnit\Framework\TestCase;

    class ClientTest extends TestCase
    {
        /** @var Client $subject */
        public static $subject;

        /** @var resource|null $mh */
        public static $mh = 1;

        public function setUp(): void
        {
            parent::setUp();

            /** @var MockObject|ResponseFactoryInterface $factory */
            $factory = $this->createMock(ResponseFactoryInterface::class);

            /** @var MockObject|StreamInterface $body */
            $body = $this->createMock(StreamInterface::class);
            $body
                ->expects($this->any())
                ->method("rewind")
                ->with();
            $body
                ->expects($this->any())
                ->method("getContents")
                ->with()
                ->willReturn("");
            $body
                ->expects($this->any())
                ->method("write")
                ->with("stuff and things")
                ->willReturn(strlen("stuff and things"));

            $response = $this->createMock(ResponseInterface::class);

            $response
                ->expects($this->any())
                ->method("withAddedHeader")
                ->withAnyParameters()
                ->willReturnSelf();

            $response
                ->expects($this->any())
                ->method("withProtocolVersion")
                ->with("1.1")
                ->willReturnSelf();
            $response
                ->expects($this->any())
                ->method("withStatus")
                ->with(200, "OK")
                ->willReturnSelf();

            $response
                ->expects($this->any())
                ->method("getBody")
                ->with()
                ->willReturn($body);

            $factory
                ->expects($this->any())
                ->method("createResponse")
                ->with()
                ->willReturn($response);

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
            static::$subject->__destruct();
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
            static::$subject->__destruct();
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
