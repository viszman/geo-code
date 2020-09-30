<?php

declare(strict_types=1);

namespace BCL\Service;


use BCL\Service\AddressCleaner\Cleaner;
use Geocoder\Collection;
use Geocoder\Exception\Exception;
use Geocoder\Model\Address;
use Geocoder\Model\AddressCollection;
use Geocoder\Model\Coordinates;
use Geocoder\Provider\Nominatim\Model\NominatimAddress;
use Geocoder\Provider\Provider;
use Geocoder\Query\GeocodeQuery;
use Geocoder\Query\ReverseQuery;

    class NominatimGeocode
    {
        /**
         * @var \Geocoder\Provider\Provider
         */
        private $nominatimProvider;
        private $checkPostCode = false;

        /**
         * Geo Nominatim constructor.
         *
         * @param \Geocoder\Provider\Provider $nominatimProvider
         */
        public function __construct(Provider $nominatimProvider)
        {
            $this->nominatimProvider = $nominatimProvider;
        }

    public function search(string $address, ?string $postCode, Cleaner $cleaner): ?NominatimAddress
    {
        $addressClean = $cleaner->clean($address);
        $geoResultNormal = $this->callNominatim($addressClean);
        $geoResultMiddle = $this->callNominatimMiddle($addressClean);
        $geoResult = $this->mergeAddresses($geoResultNormal, $geoResultMiddle);

        if ($addressClean !== $address) {
            $geoResultMiddleDirty = $this->callNominatimMiddle($address);
            $geoResult = $this->mergeAddresses($geoResult, $geoResultMiddleDirty);
        }

        if ($postCode) {
            $geoResultPostCode = $this->callNominatimWithPostCode($address, $postCode);
            $geoResult = $this->mergeAddresses($geoResult, $geoResultPostCode);
        }

        if (!$geoResult) {
            print "\n no result for {$addressClean}";

            return null;
        }

        /** @var \Geocoder\Provider\Nominatim\Model\NominatimAddress $item */

        $bestMatch = $this->getCandidate($geoResult, $address);
        if ($bestMatch) {
            return $bestMatch;
        }
        foreach ($geoResult as $item) {
            if ($item->getType() === 'suburb' && count($geoResult) !== 1) {
                continue;
            }
            try {
                $status = $this->checkAddress($item);
                if ($status) {
                    return $item;
                }
                continue;
            } catch (\Exception $e) {
            }
        }

        return null;
    }

    public function searchPermutation(string $address, Cleaner $cleaner): ?NominatimAddress
    {
        $addressClean = $cleaner->clean($address);
        $addresses = $this->permute($addressClean);
        $addresses = array_slice($addresses, 0, 200);
        $collection = new AddressCollection();
        $countNumber = count($addresses);
        echo "\n will make ".$countNumber;
        foreach ($addresses as $key => $addressPermutation) {
            $geoQuery = GeocodeQuery::create($addressPermutation);
            $geoQueryEN = $geoQuery->withLocale('en_EN');
            $geoResultNormal = $this->getGeo($geoQuery, $this->nominatimProvider);
            $geoResultNormalEN = $this->getGeo($geoQueryEN, $this->nominatimProvider);
            $collection = $this->mergeAddresses($geoResultNormal, $collection);
            $collection = $this->mergeAddresses($geoResultNormalEN, $collection);
            $callNumber = $key + 1;
            echo "\n {$callNumber} of calls {$countNumber}";
        }

        if (!$collection) {
            print "\n no result for {$addressClean}";

            return null;
        }

        /** @var \Geocoder\Provider\Nominatim\Model\NominatimAddress $item */

        $bestMatch = $this->getCandidate($collection, $address);
        if ($bestMatch) {
            return $bestMatch;
        }
        foreach ($collection as $item) {
            if ($item->getType() === 'suburb' && count($collection) !== 1) {
                continue;
            }
            try {
                $status = $this->checkAddress($item);
                if ($status) {
                    return $item;
                }
                continue;
            } catch (\Exception $e) {
            }
        }

        return null;
    }

    /**
     * @param string $address
     * @param string $locale
     *
     * @return \Geocoder\Collection|null
     */
    public function callWithLocale(string $address, string $locale): ?Collection
    {
        try {
            $query = GeocodeQuery::create(
                $address
            );
            $query = $query->withLocale($locale);

            return $this->nominatimProvider->reverseQuery($query);
        } catch (Exception $e) {
            print "\n exception: {$address} \n";
        }

        return null;
    }

    /**
     * @param \Geocoder\Model\Coordinates $coordinates
     *
     * @return \Geocoder\Collection|null
     */
    public function callReverse(Coordinates $coordinates): ?Collection
    {
        try {
            $query = ReverseQuery::create(
                $coordinates
            );
            $query = $query->withLocale('en_EN');

            return $this->nominatimProvider->reverseQuery($query);
        } catch (Exception $e) {
            $coordinates->getLatitude();
            $coordinates->getLongitude();
            print "\n exception: coordinates \n";
        }

        return null;
    }

    /**
     * @return mixed
     */
    public function getCheckPostCode()
    {
        return $this->checkPostCode;
    }

    /**
     * @param mixed $checkPostCode
     */
    public function setCheckPostCode($checkPostCode): void
    {
        $this->checkPostCode = $checkPostCode;
    }

    protected function assignPoints(string $address, string $partAddress, array &$candidate): void
    {
        $loweredStreet = strtolower($partAddress);
        $parts = explode(' ', $loweredStreet);

        foreach ($parts as $part) {
            if (strpos($address, $part) !== false) {
                $candidate['fieldsMatch']++;
            }
        }
    }

    /**
     * @param string $address
     *
     * @return \Geocoder\Collection|null
     */
    private function callNominatim(string $address): ?Collection
    {
        return $this->getGeoResult($address, $this->nominatimProvider);
    }

    /**
     * @param string $address
     *
     * @param string $postCode
     *
     * @return \Geocoder\Collection|null
     */
    private function callNominatimWithPostCode(string $address, string $postCode): ?Collection
    {
        return $this->getGeoResultPostCode($address, $postCode, $this->nominatimProvider);
    }

    /**
     * @param string $address
     *
     * @return \Geocoder\Collection|null
     */
    private function callNominatimMiddle(string $address): ?Collection
    {
        return $this->getGeoResultFromMiddle($address, $this->nominatimProvider);
    }

    private function getGeoResult(string $address, Provider $provider): ?Collection
    {
        $geoResults = $this->callProvider(GeocodeQuery::create($address), $provider);
        if ($geoResults === null) {
            return null;
        }
        $goodResult = $this->checkResult($geoResults);
        if ($goodResult) {
            return $geoResults;
        }
        $addressClean = $this->deletePart($address);

        return $this->getGeoResult($addressClean, $provider);
    }

    private function getGeo(GeocodeQuery $address, Provider $provider): ?Collection
    {
        $geoResults = $this->callProvider($address, $provider);
        if ($geoResults === null) {
            return null;
        }
        $goodResult = $this->checkResult($geoResults);
        if ($goodResult) {
            return $geoResults;
        }

        return null;
    }

    private function getGeoResultFromMiddle(string $address, Provider $provider): ?Collection
    {
        $geoResults = $this->callProvider(GeocodeQuery::create($address), $provider);
        if ($geoResults === null) {
            return null;
        }
        $goodResult = $this->checkResult($geoResults);
        if ($goodResult) {
            return $geoResults;
        }
        $addressClean = $this->deletePartMiddle($address);

        return $this->getGeoResult($addressClean, $provider);
    }

    private function getGeoResultPostCode(
        string $address,
        string $postCode,
        Provider $provider
    ):
    ?Collection {
        $noPostCode = trim(str_replace($postCode, '', $address), ',');
        $noPostCode = strtolower($noPostCode);
        $addressArray = explode(',', $noPostCode);
        foreach ($addressArray as $nominatimAddress) {
            $geoResults = $this->callProvider(
                GeocodeQuery::create($postCode.', '.$nominatimAddress),
                $provider
            );
            if (count($geoResults) === 0) {
                continue;
            }
            $goodResult = $this->checkResult($geoResults);
            $resultWithPostCode = $this->checkResultForPostCode($geoResults, $addressArray);
            if ($goodResult && $resultWithPostCode) {
                return new AddressCollection([$resultWithPostCode]);
            }
        }

        return null;
    }

    private function checkResult(Collection $collection): bool
    {
        foreach ($collection as $item) {
            if ($this->checkAddress($item)) {
                return true;
            }
        }

        return false;
    }

    private function checkResultForPostCode(Collection $collection, array $address):
    ?NominatimAddress {
        /** @var NominatimAddress $item */
        foreach ($collection as $item) {
            $havePostCode = $this->checkAddress($item);
            if (!$havePostCode) {
                continue;
            }
            foreach ($address as $addressChunk) {
                $lowered = strtolower($item->getDisplayName());
                $isPart = stripos($lowered, $addressChunk);
                if ($isPart !== false) {
                    return $item;
                }
            }
        }

        return null;
    }

    /**
     * @param \Geocoder\Model\Address $address
     *
     * @return bool
     */
    private function checkAddress(Address $address): bool
    {
        if ($this->checkPostCode && $address->getPostalCode() === null) {
            return false;
        }

        if (!$address->getCountry()) {
            return false;
        }

        return true;
    }

    /**
     * @param string $address
     * @param int    $deleteParts
     *
     * @return string
     */
    private function deletePart(string $address, int $deleteParts = 1): string
    {
        $exploded = explode(',', $address);
        foreach ($exploded as $i => $iValue) {
            unset($exploded[$i]);
            $deleteParts--;
            if ($deleteParts === 0) {
                break;
            }
        }

        return trim(implode(', ', $exploded));
    }

    /**
     * @param string $address
     *
     * @return string
     */
    private function deletePartMiddle(string $address): string
    {
        $exploded = explode(',', $address);
        $counter = count($exploded);
        $halfCeil = ceil($counter / 2) - 1;
        unset($exploded[$halfCeil]);

        return trim(implode(', ', $exploded));
    }

    private function callProvider(GeocodeQuery $query, Provider $provider): ?Collection
    {
        try {
            return $provider->geocodeQuery(
                $query
            );
        } catch (Exception $e) {
            print "\n exception: {$query->getText()} \n";
        }

        return null;
    }

    private function getCandidate(Collection $addressCollection, string $address)
    {
        $candidate = [
            'fieldsMatch' => 0,
            'address' => null,
        ];
        $loweredAddress = strtolower($address);

        $explodedAddress = explode(',', $address);
        $explodedAddress = array_map(
            static function ($elem) {
                return trim($elem);
            },
            $explodedAddress
        );

        /** @var NominatimAddress $addressNominatim */
        foreach ($addressCollection as $addressNominatim) {
            $tmpCandidate = [
                'fieldsMatch' => 0,
                'address' => null,
            ];
            $loweredStreet = $loweredSubLocality = $loweredLocality = $loweredCountry = null;
            $parts = [];
            if ($addressNominatim->getStreetName()) {
//                $loweredStreet = strtolower($addressNominatim->getStreetName());
//                $parts = explode(' ', $loweredStreet);
                $this->assignPoints(
                    $loweredAddress,
                    $addressNominatim->getStreetName(),
                    $tmpCandidate
                );
            }


//            if (strpos($loweredAddress, $loweredStreet) !== false) {
//                $tmpCandidate['fieldsMatch']++;
//            }


            if ($addressNominatim->getSubLocality()) {
//                $loweredSubLocality = strtolower($addressNominatim->getSubLocality());
                $this->assignPoints(
                    $loweredAddress,
                    $addressNominatim->getSubLocality(),
                    $tmpCandidate
                );
            }
//            if (strpos($loweredAddress, $loweredSubLocality) !== false) {
//                $tmpCandidate['fieldsMatch']++;
//            }


            if ($addressNominatim->getLocality()) {
//                $loweredLocality = strtolower($addressNominatim->getLocality());
                $this->assignPoints(
                    $loweredAddress,
                    $addressNominatim->getLocality(),
                    $tmpCandidate
                );
            }
//            if (stripos($loweredAddress, $loweredLocality) !== false) {
//                $tmpCandidate['fieldsMatch']++;
//            }


            if ($addressNominatim->getCountry()) {
                $this->assignPoints(
                    $loweredAddress,
                    $addressNominatim->getCountry()->getName(),
                    $tmpCandidate
                );
//                $loweredCountry = strtolower($addressNominatim->getCountry()->getName());
            }
//            if (strpos($loweredAddress, $loweredCountry) !== false) {
//                $tmpCandidate['fieldsMatch']++;
//            }


            if (in_array(
                    $addressNominatim->getCountry()->getCode(),
                    $explodedAddress,
                    true
                ) === true) {
                $tmpCandidate['fieldsMatch'] += 2;
            }

            if ($addressNominatim->getPostalCode()) {
                $this->assignPoints(
                    $loweredAddress,
                    $addressNominatim->getPostalCode(),
                    $tmpCandidate
                );
            }
//            if (strpos($address, $addressNominatim->getPostalCode()) !== false) {
//                $tmpCandidate['fieldsMatch']++;
//            }
            foreach ($addressNominatim->getAdminLevels() as $adminLevel) {
                $loweredAdminLevel = strtolower($adminLevel->getName());
                $this->assignPoints($loweredAddress, $loweredAdminLevel, $tmpCandidate);
//                if (strpos($loweredAddress, $loweredAdminLevel) !== false) {
//                    $tmpCandidate['fieldsMatch']++;
//                }
            }

            foreach (explode(',', $addressNominatim->getDisplayName()) as $displayParts) {
                $partLowered = strtolower(trim($displayParts));
                if (strpos($loweredAddress, $partLowered) !== false) {
                    $tmpCandidate['fieldsMatch']++;
                }
            }
            $tmpCandidate['address'] = $addressNominatim;
            if ($tmpCandidate['fieldsMatch'] > $candidate['fieldsMatch']) {
                $candidate = $tmpCandidate;
            }
            $higherOSMTypes = ['house', 'office', 'building'];
            if ($tmpCandidate['fieldsMatch'] > 1 && in_array(
                    $addressNominatim->getType(),
                    $higherOSMTypes,
                    true
                )) {
                $tmpCandidate['fieldsMatch'] += 3;
            }
        }

        return $candidate['address'];
    }

    /**
     * @param \Geocoder\Model\AddressCollection|null $collection1
     * @param \Geocoder\Model\AddressCollection|null $collection2
     *
     * @return \Geocoder\Model\AddressCollection
     */
    private function mergeAddresses(
        ?AddressCollection $collection1,
        ?AddressCollection $collection2
    ): AddressCollection {
        $items = [];
        if ($collection1) {
            foreach ($collection1 as $item) {
                $items[] = $item;
            }
        }
        if ($collection2) {
            foreach ($collection2 as $item) {
                $items[] = $item;
            }
        }

        return new AddressCollection($items);
    }

    private function createUniqueKeys(array $address, int $arrayLength = 5)
    {
        $maxCount = count($address);
        if (1 === $maxCount) {
            return $address;
        }
        $result = [];
        foreach ($address as $key => $item) {
            $nextStep = array_diff_key($address, [$key => $item]);
            foreach ($this->createUniqueKeys($nextStep) as $p) {
                $result[] = $item.','.$p;
            }
            $resultLength = count($result);
            if ($resultLength >= $arrayLength) {
                return $result;
            }
        }

        return $result;
    }

    private function permute(string $address)
    {
        $addressParts = explode(',', $address);
        $maxCount = count($addressParts);
        if (1 === $maxCount) {
            return $addressParts;
        }

        $arrayKeys = array_keys($addressParts);
        $keys = $this->createUniqueKeys($arrayKeys);
        foreach ($addressParts as $key => $addressPart) {
            foreach ($keys as $arrayKey) {
                $trimmed = trim(str_replace($key, '', $arrayKey), ',');
                $trimmed = str_replace(',,', ',', $trimmed);
                $keys[] = $trimmed;
            }
        }
        $addresses = [];
        $allPermutations = array_filter(array_unique($keys));
        foreach ($allPermutations as $uniqueCombination) {
            $keysToCombine = explode(',', $uniqueCombination);
            $partial = [];
            foreach ($keysToCombine as $item) {
                $partial[] = $addressParts[$item];
            }
            $addresses[] = implode(', ', $partial);
        }

        return $addresses;
    }
}
