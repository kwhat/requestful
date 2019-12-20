<?php

declare(strict_types=1);

namespace Requestful\Http {

    use PHPUnit\Framework\TestCase;
    use ReflectionException;
    use ReflectionObject;
    use Requestful\Test\Unit\Http\ClientTest;

    function curl_multi_init()
    {
        TestCase::once();
        return 1;
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
        TestCase::assertEquals(1, $mh);
        TestCase::assertEquals(2, $ch);
        return CURLM_OK;
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
        TestCase::assertEquals(1, $mh);
        TestCase::assertEquals(2, $ch);

        return CURLM_OK;
    }

    function curl_reset($ch)
    {
        TestCase::assertEquals(2, $ch);
    }

    function curl_close($ch)
    {
        TestCase::assertEquals(2, $ch);
    }

    function curl_multi_select($mh, $timeout)
    {
        TestCase::assertEquals(1, $mh);
        TestCase::assertIsFloat($timeout);

        return 0;
    }

    /**
     * @param resource $mh
     * @param int $still_running
     *
     * @return int
     */
    function curl_multi_exec($mh, &$still_running)
    {
        TestCase::assertEquals(1, $mh);

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

        $still_running = 1;
        return CURLM_OK;
    }

    function curl_file_create($filename, $mimeType, $postname)
    {
        TestCase::assertEquals("/test/unit.json", $filename);
        TestCase::assertEquals("application/json", $mimeType);
        TestCase::assertEquals("test", $postname);
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
        TestCase::assertEquals(1, $mh);

        $msgs_in_queue = 0;
        return array(
            "msg" => CURLMSG_DONE,
            "result" => CURLE_OK,
            "handle" => 2
        );
    }
}

namespace Requestful\Test\Unit\Http {

    use PHPUnit\Framework\MockObject\MockObject;
    use Psr\Http\Client\ClientExceptionInterface;
    use Psr\Http\Message\RequestInterface;
    use Psr\Http\Message\ResponseFactoryInterface;
    use Psr\Http\Message\ResponseInterface;
    use Psr\Http\Message\ServerRequestInterface;
    use Psr\Http\Message\StreamInterface;
    use Psr\Http\Message\UploadedFileInterface;
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

        public function tearDown(): void
        {
            parent::tearDown();

            static::$subject->__destruct();
        }

        /**
         * @throws ClientExceptionInterface
         */
        public function testSendRequestSuccess()
        {
            /** @var MockObject|StreamInterface $body */
            $body = $this->createMock(StreamInterface::class);
            $body
                ->expects($this->once())
                ->method("rewind")
                ->with();
            $body
                ->expects($this->once())
                ->method("getContents")
                ->with()
                ->willReturn("");

            /** @var MockObject|RequestInterface $request */
            $request = $this->createMock(RequestInterface::class);
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

            $result = static::$subject->sendRequest($request);
            $this->assertNotInstanceOf(PromiseInterface::class, $result);
        }

        /**
         * @throws ClientExceptionInterface
         */
        public function testSendRequestWithCache()
        {
            /** @var MockObject|StreamInterface $body */
            $body = $this->createMock(StreamInterface::class);
            $body
                ->expects($this->exactly(2))
                ->method("rewind")
                ->with();
            $body
                ->expects($this->exactly(2))
                ->method("getContents")
                ->with()
                ->willReturn("");

            /** @var MockObject|RequestInterface $request */
            $request = $this->createMock(RequestInterface::class);
            $request
                ->expects($this->exactly(2))
                ->method("getBody")
                ->with()
                ->willReturn($body);
            $request
                ->expects($this->exactly(2))
                ->method("getHeaders")
                ->with()
                ->willReturn([]);

            $result = static::$subject->sendRequest($request);
            $this->assertNotInstanceOf(PromiseInterface::class, $result);

            $result = static::$subject->sendRequest($request);
            $this->assertNotInstanceOf(PromiseInterface::class, $result);
        }


        /**
         * @throws ClientExceptionInterface
         */
        public function testSendRequestWithoutCache()
        {
            /** @var MockObject|StreamInterface $body */
            $body = $this->createMock(StreamInterface::class);
            $body
                ->expects($this->once())
                ->method("rewind")
                ->with();
            $body
                ->expects($this->once())
                ->method("getContents")
                ->with()
                ->willReturn("");

            /** @var MockObject|RequestInterface $request */
            $request = $this->createMock(RequestInterface::class);
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

            static::$subject->setConfig("cache_size", 0);

            $result = static::$subject->sendRequest($request);
            $this->assertNotInstanceOf(PromiseInterface::class, $result);
        }

        public function testSendRequestAsyncSuccess()
        {
            /** @var MockObject|StreamInterface $body */
            $body = $this->createMock(StreamInterface::class);
            $body
                ->expects($this->once())
                ->method("rewind")
                ->with();
            $body
                ->expects($this->once())
                ->method("getContents")
                ->with()
                ->willReturn("");

            /** @var MockObject|RequestInterface $request */
            $request = $this->createMock(RequestInterface::class);
            $request
                ->expects($this->once())
                ->method("getBody")
                ->with()
                ->willReturn($body);
            $request
                ->expects($this->once())
                ->method("getHeaders")
                ->with()
                ->willReturn(array("Content-Type" => ["text/plain"]));

            $promise = static::$subject->sendRequestAsync($request);
            $this->assertInstanceOf(PromiseInterface::class, $promise);

            $promise->wait();
        }

        public function testSendRequestAsyncCancel()
        {
            /** @var MockObject|StreamInterface $body */
            $body = $this->createMock(StreamInterface::class);
            $body
                ->expects($this->once())
                ->method("rewind")
                ->with();
            $body
                ->expects($this->once())
                ->method("getContents")
                ->with()
                ->willReturn("");

            /** @var MockObject|RequestInterface $request */
            $request = $this->createMock(RequestInterface::class);
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

            $promise->cancel();
        }

        public function testSendServerRequestAsync()
        {
            /** @var MockObject|RequestInterface $request */
            $request = $this->createMock(ServerRequestInterface::class);
            $request
                ->expects($this->once())
                ->method("getHeaders")
                ->with()
                ->willReturn([]);
            $request
                ->expects($this->atLeastOnce())
                ->method("getAttributes")
                ->with()
                ->willReturn(["test" => "testing"]);
            $request
                ->expects($this->atLeastOnce())
                ->method("getUploadedFiles")
                ->with()
                ->willReturn([]);

            $promise = static::$subject->sendRequestAsync($request);
            $this->assertInstanceOf(PromiseInterface::class, $promise);

            $promise->cancel();
        }

        public function testSendServerRequestAsyncWithFileUploads()
        {
            $stream = $this->createMock(StreamInterface::class);
            $stream
                ->expects($this->once())
                ->method("getMetadata")
                ->with("uri")
                ->willReturn("/test/unit.json");

            $upload = $this->createMock(UploadedFileInterface::class);
            $upload
                ->expects($this->once())
                ->method("getClientMediaType")
                ->with()
                ->willReturn("application/json");
            $upload
                ->expects($this->once())
                ->method("getClientFilename")
                ->with()
                ->willReturn("test");
            $upload
                ->expects($this->once())
                ->method("getStream")
                ->with()
                ->willReturn($stream);

            /** @var MockObject|RequestInterface $request */
            $request = $this->createMock(ServerRequestInterface::class);
            $request
                ->expects($this->once())
                ->method("getHeaders")
                ->with()
                ->willReturn([]);
            $request
                ->expects($this->atLeastOnce())
                ->method("getAttributes")
                ->with()
                ->willReturn(["test" => "testing"]);
            $request
                ->expects($this->atLeastOnce())
                ->method("getUploadedFiles")
                ->with()
                ->willReturn([$upload]);

            $promise = static::$subject->sendRequestAsync($request);
            $this->assertInstanceOf(PromiseInterface::class, $promise);

            $promise->cancel();
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
