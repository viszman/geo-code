<?php

declare(strict_types=1);

namespace BCL\Service\AddressCleaner;


interface Cleaner
{
    public function clean(string $address);
}
