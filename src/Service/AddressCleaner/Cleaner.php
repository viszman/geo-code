<?php

declare(strict_types=1);

namespace Viszman\Service\AddressCleaner;


interface Cleaner
{
    public function clean(string $address);
}
