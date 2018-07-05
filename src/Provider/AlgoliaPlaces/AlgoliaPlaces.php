<?php

declare(strict_types=1);

/*
 * This file is part of the Geocoder package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

namespace Geocoder\Provider\AlgoliaPlaces;

use Geocoder\Collection;
use Geocoder\Exception\InvalidArgument;
use Geocoder\Exception\UnsupportedOperation;
use Geocoder\Http\Provider\AbstractHttpProvider;
use Geocoder\Model\Address;
use Geocoder\Model\AddressBuilder;
use Geocoder\Model\AddressCollection;
use Geocoder\Provider\Provider;
use Geocoder\Query\GeocodeQuery;
use Geocoder\Query\ReverseQuery;
use Http\Client\HttpClient;
use Psr\Http\Message\RequestInterface;

class AlgoliaPlaces extends AbstractHttpProvider implements Provider
{
    const TYPE_CITY = 'city';

    const TYPE_COUNTRY = 'country';

    const TYPE_ADDRESS = 'address';

    const TYPE_BUS_STOP = 'busStop';

    const TYPE_TRAIN_STATION = 'trainStation';

    const TYPE_TOWN_HALL = 'townhall';

    const TYPE_AIRPORT = 'airport';

    /** @var string */
    const ENDPOINT_URL_SSL = 'https://places-dsn.algolia.net/1/places/query';

    /** @var string */
    private $apiKey;

    /** @var string */
    private $appId;

    /** @var GeocodeQuery */
    private $query;

    /** @var HttpClient */
    private $client;

    public function __construct(HttpClient $client, string $apiKey, string $appId)
    {
        parent::__construct($client);

        $this->apiKey = $apiKey;
        $this->client = $client;
        $this->appId = $appId;
    }

    public function getName(): string
    {
        return 'algolia_places';
    }

    public function geocodeQuery(GeocodeQuery $query): Collection
    {
        // This API doesn't handle IPs
        if (filter_var($query->getText(), FILTER_VALIDATE_IP)) {
            throw new UnsupportedOperation('The AlgoliaPlaces provider does not support IP addresses, only street addresses.');
        }

        $this->query = $query;

        $jsonResponse = json_decode($this->getUrlContents(self::ENDPOINT_URL_SSL), true);

        if (is_null($jsonResponse)) {
            return new AddressCollection([]);
        }

        if ($jsonResponse['nbHits'] == 0 || $jsonResponse['degradedQuery']) {
            return new AddressCollection([]);
        }

        return $this->buildResult($jsonResponse);
    }

    public function reverseQuery(ReverseQuery $query): Collection
    {
        throw new UnsupportedOperation('The AlgoliaPlaces provider does not support reverse geocoding.');
    }

    public function getTypes(): array
    {
        return [
            self::TYPE_CITY,
            self::TYPE_COUNTRY,
            self::TYPE_ADDRESS,
            self::TYPE_BUS_STOP,
            self::TYPE_TRAIN_STATION,
            self::TYPE_TOWN_HALL,
            self::TYPE_AIRPORT,
        ];
    }

    protected function getRequest(string $url): RequestInterface
    {
        return $this->getMessageFactory()->createRequest(
            'POST',
            $url,
            $this->buildHeaders(),
            $this->buildData()
        );
    }

    private function buildData(): string
    {
        $query = $this->query;
        $params = [
            'query' => $query->getText(),
            'aroundLatLngViaIP' => false,
            'language' => $query->getLocale(),
            'type' => $this->buildType($query),
            'countries' => $this->buildCountries($query),
        ];

        return json_encode(array_filter($params));
    }

    private function buildType(GeocodeQuery $query): string
    {
        $type = $query->getData('type', '');
        if (!empty($type) && !in_array($type, $this->getTypes())) {
            throw new InvalidArgument('The type provided to AlgoliaPlace provider must be one those "'.implode('", "', $this->getTypes()).'"".');
        }

        return $type;
    }

    private function buildCountries(GeocodeQuery $query)
    {
        return array_map(
            function ($country) {
                if (2 != strlen($country)) {
                    throw new InvalidArgument('The country provided to AlgoliaPlace provider must be an ISO 639-1 code.');
                }

                return $country;
            },
            $query->getData('countries') ?? []
        );
    }

    /**
     * @return array
     */
    private function buildHeaders(): array
    {
        if (empty($this->appId) || empty($this->apiKey)) {
            return [];
        }

        return [
            'X-Algolia-Application-Id' => $this->appId,
            'X-Algolia-API-Key' => $this->apiKey,
        ];
    }

    /**
     * @param $jsonResponse
     *
     * @return AddressCollection
     */
    private function buildResult($jsonResponse): AddressCollection
    {
        $results = [];

        //error_log(\json_encode($jsonResponse));
        // 1. degradedQuery: checkfor if(degradedQuery) and set results accordingly?
        // 2. setStreetNumber($result->locale_name) AlgoliaPlaces does not offer streetnumber
        // precision for the geocoding (with the exception to addresses situated in France)

        foreach ($jsonResponse['hits'] as $result) {
            $builder = new AddressBuilder($this->getName());
            $builder->setCoordinates($result['_geoloc']['lat'], $result['_geoloc']['lng']);
            $builder->setCountry($result['country']);
            $builder->setCountryCode($result['country_code']);
            if (isset($result['city'])) {
                $builder->setLocality($result['city'][0]);
            }
            if (isset($result['postcode'])) {
                $builder->setPostalCode($result['postcode'][0]);
            }
            if (isset($result['locale_name'])) {
                $builder->setStreetNumber($result['locale_name']);
            }
            if (isset($result['locale_names'])) {
                $builder->setStreetName($result['locale_names'][0]);
            }
            foreach ($result['administrative'] ?? [] as $i => $adminLevel) {
                $builder->addAdminLevel($i + 1, $adminLevel);
            }

            $results[] = $builder->build(Address::class);
        }

        return new AddressCollection($results);
    }
}
