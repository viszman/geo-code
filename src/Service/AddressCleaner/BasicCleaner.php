<?php
declare(strict_types=1);

namespace Viszman\Service\AddressCleaner;


class BasicCleaner implements Cleaner
{
    public function clean(string $address)
    {
        $address = preg_replace('/.*Solicitor.*?\,/', '', $address);
        $withoutCode = str_replace(
            ['Solicitors,', 'Solicitor,', 'Solicitors', 'Solicitor', 'ENG', '!'],
            ['', '', '', '', '', ', '],
            $address
        );
        $goodParts = [];
        foreach (explode(',', $withoutCode) as $item) {
            $item = trim($item);
            if (is_numeric($item) || (!ctype_upper($item) || strlen($item) > 2)) {
                $goodParts[] = trim($item);
            }
        }
        $glued = implode(', ', $goodParts);
        $withoutCode = trim($glued, ',. ');

        return $withoutCode;
    }
}
