<?php

namespace App\Exceptions;

use Throwable;

class InvalidImagePath extends OkException
{

    public function __construct(string $message = "图片不存在", int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
