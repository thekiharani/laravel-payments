<?php

namespace NoriaLabs\Payments\Exceptions;

class AuthenticationException extends PaymentsException
{
    public function __construct(string $message, mixed $details = null)
    {
        parent::__construct($message, 'AUTHENTICATION_ERROR', $details);
    }
}
