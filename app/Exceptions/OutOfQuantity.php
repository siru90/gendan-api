<?php

namespace App\Exceptions;

use Throwable;

class OutOfQuantity extends OkException
{

    public function __construct(string $message = "数量不足", int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
