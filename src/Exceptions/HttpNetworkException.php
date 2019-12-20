<?php

declare(strict_types=1);

namespace Requestful\Exceptions;

use Exception;
use Psr\Http\Client\ClientExceptionInterface;

class HttpNetworkException extends HttpClientException
{
}
