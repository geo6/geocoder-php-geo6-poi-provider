<?php

declare(strict_types=1);

/*
 * This file is part of the Geocoder package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

namespace Geocoder\Provider\Geo6\POI;

use Geocoder\Collection;
use Geocoder\Exception\InvalidArgument;
use Geocoder\Exception\InvalidServerResponse;
use Geocoder\Exception\UnsupportedOperation;
use Geocoder\Http\Provider\AbstractHttpProvider;
use Geocoder\Model\Address;
use Geocoder\Model\AddressBuilder;
use Geocoder\Model\AddressCollection;
use Geocoder\Model\Country;
use Geocoder\Provider\Geo6\POI\Model\POI;
use Geocoder\Provider\Provider;
use Geocoder\Query\GeocodeQuery;
use Geocoder\Query\ReverseQuery;
use Http\Client\HttpClient;
use Jose\Component\Core\AlgorithmManager;
use Jose\Component\Core\Converter\StandardConverter;
use Jose\Component\Core\JWK;
use Jose\Component\Signature\Algorithm\HS512;
use Jose\Component\Signature\JWSBuilder;
use Jose\Component\Signature\Serializer\CompactSerializer;

/**
 * @author Jonathan Beliën <jbe@geo6.be>
 */
final class Geo6POI extends AbstractHttpProvider implements Provider
{
    const GEOCODE_ENDPOINT_URL = 'https://api-v2.geo6.be/';

    /**
     * @var string
     */
    private $clientId;

    /**
     * @var string
     */
    private $privateKey;

    /**
     * @var bool
     */
    private $useGeo6Token = false;

    /**
     * @param HttpClient $client an HTTP adapter
     */
    public function __construct(HttpClient $client, string $clientId, string $privateKey, bool $useGeo6Token = false)
    {
        $this->clientId = $clientId;
        $this->privateKey = $privateKey;
        $this->useGeo6Token = $useGeo6Token;

        parent::__construct($client);
    }

    /**
     * {@inheritdoc}
     */
    public function geocodeQuery(GeocodeQuery $query): Collection
    {
        $search = $query->getText();

        $source = $query->getData('source');
        $locality = $query->getData('locality') ?? null;

        // This API does not support IP
        if (filter_var($search, FILTER_VALIDATE_IP)) {
            throw new UnsupportedOperation('The Geo-6 POI provider does not support IP addresses, only street addresses.');
        }

        // Save a request if no valid query entered
        if (empty($search)) {
            throw new InvalidArgument('Query cannot be empty.');
        }

        $language = '';
        if (!is_null($query->getLocale()) && preg_match('/^(fr|nl).*$/', $query->getLocale(), $matches) === 1) {
            $language = $matches[1];
        }

        $url = rtrim(self::GEOCODE_ENDPOINT_URL, '/');
        if (!is_null($source) && !is_null($locality)) {
            $url = sprintf($url.'/geocode/getPOIList/%s/%s/%s', urlencode($source), urlencode($locality), urlencode($search));
        } elseif (!is_null($source)) {
            $url = sprintf($url.'/geocode/getPOIList/%s/%s', urlencode($source), urlencode($search));
        } else {
            $url = sprintf($url.'/geocode/getPOIList/%s', urlencode($search));
        }
        $json = $this->executeQuery($url);

        // no result
        if (empty($json->features)) {
            return new AddressCollection([]);
        }

        $results = [];
        foreach ($json->features as $feature) {
            $poi_fr = self::extractComponents($this->getName(), $feature, 'fr');
            $poi_nl = self::extractComponents($this->getName(), $feature, 'nl');

            switch ($language) {
                case 'fr':
                    $results[] = $poi_fr ?? $poi_nl;
                    break;

                case 'nl':
                    $results[] = $poi_nl ?? $poi_fr;
                    break;

                default:
                    if (!is_null($poi_fr)) {
                        $results[] = $poi_fr;
                    }
                    if (
                        !is_null($poi_nl) &&
                        (
                            $poi_nl->getCoordinates() != $poi_fr->getCoordinates() ||
                            $poi_nl->getType() !== $poi_fr->getType() ||
                            $poi_nl->getName() !== $poi_fr->getName()
                        )
                    ) {
                        $results[] = $poi_nl;
                    }
                    break;
            }
        }

        return new AddressCollection($results);
    }

    /**
     * {@inheritdoc}
     */
    public function reverseQuery(ReverseQuery $query): Collection
    {
        // This API does not support reverse geocoding
        throw new UnsupportedOperation('The Geo-6 POI provider does not support reverse geocoding.');
    }

    /**
     * {@inheritdoc}
     */
    public function getName(): string
    {
        return 'geo6-poi';
    }

    /**
     * @param string $url
     *
     * @return \stdClass
     */
    private function executeQuery(string $url): \stdClass
    {
        $request = $this->getRequest($url);

        $request = $request->withHeader('Referer', 'http'.(!empty($_SERVER['HTTPS']) ? 's' : '').'://'.($_SERVER['SERVER_NAME'] ?? 'localhost').'/');

        if ($this->useGeo6Token !== true) {
            $token = $this->getJWT();

            $request = $request->withHeader('Authorization', sprintf('Bearer %s', $token));
        } else {
            $token = $this->getToken();

            $request = $request->withHeader('X-Geo6-Consumer', $this->clientId);
            $request = $request->withHeader('X-Geo6-Timestamp', (string) $token->time);
            $request = $request->withHeader('X-Geo6-Token', $token->token);
        }

        $body = $this->getParsedResponse($request);

        $json = json_decode($body);
        // API error
        if (!isset($json)) {
            throw InvalidServerResponse::create($url);
        }

        return $json;
    }

    /**
     * Generate (old) GEO-6 token needed to query API.
     *
     * @deprecated
     *
     * @param string $path
     *
     * @return array
     */
    private function getGeo6Token(string $path) : array
    {
        $time = time();

        $t = $this->clientId.'__';
        $t .= $time.'__';
        $t .= parse_url(self::GEOCODE_ENDPOINT_URL, PHP_URL_HOST).'__';
        $t .= 'GET'.'__';
        $t .= $path;

        $token = crypt($t, '$6$'.$this->privateKey.'$');

        return [
            'time'  => $time,
            'token' => $token,
        ];
    }

    /**
     * Generate JSON Web Token needed to query API.
     *
     * @see https://jwt.io/
     *
     * @return string
     */
    private function getJWT() : string
    {
        $algorithmManager = AlgorithmManager::create([
            new HS512(),
        ]);
        $jwk = JWK::create([
            'kty' => 'oct',
            'k'   => $this->privateKey,
            'use' => 'sig',
        ]);
        $jsonConverter = new StandardConverter();
        $payload = $jsonConverter->encode([
            'aud' => 'GEO-6 API',
            'iat' => time(),
            'iss' => sprintf('geocoder-php-%s', $this->getName()),
            'sub' => $this->clientId,
        ]);
        $jwsBuilder = new JWSBuilder(
            $jsonConverter,
            $algorithmManager
        );
        $jws = $jwsBuilder
            ->create()
            ->withPayload($payload)
            ->addSignature($jwk, ['alg' => 'HS512', 'typ' => 'JWT'])
            ->build();

        return (new CompactSerializer($jsonConverter))->serialize($jws);
    }

    /**
     * Extract address components in French or Dutch.
     *
     * @param string $providedBy
     * @param object $feature
     * @param string $language
     */
    private static function extractComponents(string $providedBy, object $feature, string $language)
    {
        $language = strtolower($language);
        if (!in_array($language, ['fr', 'nl'])) {
            throw new InvalidArgument('The Geo-6 POI provider only supports FR (French) and NL (Dutch).');
        }

        $coordinates = $feature->geometry->coordinates;

        $source = $feature->properties->source;
        $id = (string) $feature->properties->id;
        $name = $feature->properties->{'name_'.$language};

        foreach ($feature->properties->components as $component) {
            switch ($component->type) {
                case 'country':
                    $country = $component->{'name_'.$language};
                    // $countryCode = $component->id;
                    break;
                case 'locality':
                    $locality = $component->{'name_'.$language};
                    break;
                case 'municipality':
                    $municipality = $component->{'name_'.$language};
                    break;
                case 'postal_code':
                    $postalCode = (string) $component->id;
                    break;
                case 'province':
                    $province = $component->{'name_'.$language};
                    break;
                case 'region':
                    $region = $component->{'name_'.$language};
                    break;
                case 'street':
                    $streetName = $component->{'name_'.$language};
                    break;
                case 'street_number':
                    $streetNumber = (string) $component->{'name_'.$language};
                    break;
                case 'location_type':
                    $type = $component->{'name_'.$language};
                    break;
            }
        }

        if (isset($name) && !is_null($name)) {
            $builder = new AddressBuilder($providedBy);
            $builder
                ->setCoordinates($coordinates[1], $coordinates[0])
                ->setStreetNumber($streetNumber ?? null)
                ->setStreetName($streetName ?? null)
                ->setLocality($municipality ?? null)
                ->setPostalCode($postalCode ?? null)
                ->setSubLocality($locality ?? null)
                ->setCountry($country ?? null)
                ->setCountryCode($countryCode ?? null);

            if (isset($municipality) && !is_null($municipality)) {
                $builder->addAdminLevel(3, $municipality);
            }
            if (isset($region) && !is_null($region)) {
                $builder->addAdminLevel(1, $region);
            }
            if (isset($province) && !is_null($province)) {
                $builder->addAdminLevel(2, $province);
            }

            $poi = $builder->build(POI::class);
            $poi = $poi
                ->withSource($source)
                ->withType($type)
                ->withId($id)
                ->withName($name);

            return $poi;
        }
    }
}
