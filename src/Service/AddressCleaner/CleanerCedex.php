<?php
declare(strict_types=1);

namespace Viszman\Service\AddressCleaner;


class CleanerCedex extends BasicCleaner
{
    /**
     * @param string $address
     */
    public function clean(string $address): string
    {
        $replaced = preg_replace('/cedex.*,/i', ',', $address);
        $replaced = preg_replace('/PO BOX.*?,/i', ',', $replaced);
        $replaced = str_replace(['England', ', ,'], ['United Kingdom', ','], $replaced);
        $address = parent::clean($replaced);

        return $address;
    }
}
