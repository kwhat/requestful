[![Latest Stable Version](https://poser.pugx.org/kwhat/requestful/version)](https://packagist.org/packages/kwhat/requestful)
[![Latest Unstable Version](https://poser.pugx.org/kwhat/requestful/v/unstable)](//packagist.org/packages/kwhat/requestful)
[![Build Status](https://github.com/kwhat/requestful/workflows/continuous-integration/badge.svg)](https://github.com/kwhat/requestful/actions?query=workflow%3Acontinuous-integration)
[![Total Downloads](https://poser.pugx.org/kwhat/requestful/downloads)](https://packagist.org/packages/kwhat/requestful)
[![License](https://poser.pugx.org/kwhat/requestful/license)](https://packagist.org/packages/kwhat/requestful)
[![composer.lock available](https://poser.pugx.org/kwhat/requestful/composerlock)](https://packagist.org/packages/kwhat/requestful)

# Requestful: A simple PSR compatible HTTP client library

The goal of this library is to provide a short, simple and easy to understand alternative to 
[Guzzle](https://github.com/guzzle/guzzle).

- Uses the latest [PSR](https://www.php-fig.org/psr/) standards and recommendations
    - [PSR-7](https://www.php-fig.org/psr/psr-7/) HTTP Message Interfaces
    - [PSR-17](https://www.php-fig.org/psr/psr-17/) HTTP Factories
    - [PSR-18](https://www.php-fig.org/psr/psr-17/) HTTP Client
- Supports both synchronous and asynchronous HTTP requests 
- [A+ Promises](https://promisesaplus.com/) with a Guzzle compatible interface
- Modern PHP 7 support
- Hard ext-curl requirement

## Installation

```bash
composer require kwhat/requestful
```

## Usage

You will need a PSR-17 implantation to use this library.  Personally I enjoy working with 
[nyholm/psr7](https://github.com/Nyholm/psr7), but any library that provides psr/http-factory will be sufficient.

```php
$factory = new Nyholm\Psr7\Factory\Psr17Factory();
$client = new Requestful\Http\Client($factory);
$request = $factory->createRequest(
    "GET", 
    "https://samples.openweathermap.org/data/2.5/weather?q=Los%20Angeles"
);

// Send a synchronous request
$response = $client->sendRequest($request);

var_dump(
    $response->getStatusCode(), // int(200)
    $response->getHeaderLine("content-type"), // string(30) "application/json; charset=utf8"
    $response->getBody() // string(459) "{"coord": {...}, "weather": {...}, ...}"
);

// Send an asynchronous request
$promise = $client->sendRequestAsync($request)
    ->then(function (Psr\Http\Message\ResponseInterface $response): string {
        return "Success: {$response->getStatusCode()}";
    });

var_dump($promise->wait()); // string(12) "Success: 200"
```
