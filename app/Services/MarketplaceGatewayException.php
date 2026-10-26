<?php

namespace App\Services;

class MarketplaceGatewayException extends \RuntimeException
{
    public function __construct(string $message, public bool $uncertain = false)
    {
        parent::__construct($message);
    }
}
