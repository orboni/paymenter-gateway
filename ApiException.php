<?php

namespace Paymenter\Extensions\Gateways\Orboni;

use Exception;

/** Orboni said no, or could not be reached: its error type, the HTTP status and the parameter it named. */
class ApiException extends Exception
{
    public function __construct(string $message, public readonly string $type, public readonly int $status, public readonly ?string $param = null)
    {
        parent::__construct($message);
    }
}
