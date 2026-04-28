<?php

namespace NoriaLabs\Payments\Contracts;

interface AccessTokenProvider
{
    public function getAccessToken(bool $forceRefresh = false): string;
}
