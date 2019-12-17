<?php

namespace Requestful\Http {

    use Requestful\Test\Http\ClientTest;
    use ReflectionException;
    use ReflectionObject;

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
         * @throws MethodNotAllowedException
         * @throws ReflectionException
         * @throws Exception
         */
        public function testSendSuccess()
        {
            $request = new Request(
                "GET",
                Uri::createFromString("/"),
                new Headers(),
                array(),
                array("SERVER_PROTOCOL" => "1.1"),
                new RequestBody()
            );

            static::$client = new Client();
            $promise = static::$client->send($request);
            $this->assertInstanceOf(PromiseInterface::class, $promise);

            $ref = new ReflectionObject(static::$client);
            $property = $ref->getProperty("promises");
            $property->setAccessible(true);
            $promises = $property->getValue(static::$client);

            $key = array_search($promise, $promises, true);
            $this->assertNotFalse($key);

            $property = $ref->getProperty("handles");
            $property->setAccessible(true);
            $handles = $property->getValue(static::$client);

            static::$handle = $handles[$key];

            $promise->wait();
        }

        public function testGetConfigSuccess()
        {
            $client = new Client();
            $this->assertIsArray($client->getConfig());
            $this->assertIsInt($client->getConfig("cache_size"));
            $this->assertNull($client->getConfig("invalid"));
        }
    }
}
