<?php

namespace NoriaLabs\Payments\Exceptions;

class TimeoutException extends PaymentsException
{
    public function __construct(string $message, mixed $details = null)
    {
        parent::__construct($message, 'TIMEOUT_ERROR', $details);
    }
}
