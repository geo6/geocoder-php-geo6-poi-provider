<?php

declare(strict_types=1);

/*
 * This file is part of the Geocoder package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

namespace Geocoder\Provider\Geo6\POI\Tests;

use Geocoder\IntegrationTest\BaseTestCase;
use Geocoder\Provider\Geo6\POI\Geo6POI;
use Geocoder\Query\GeocodeQuery;
use Geocoder\Query\ReverseQuery;

class Geo6POITest extends BaseTestCase
{
    protected function getCacheDir()
    {
        return __DIR__.'/.cached_responses';
    }

    public function testGeocodeWithLocalhostIPv4()
    {
        $this->expectException(\Geocoder\Exception\UnsupportedOperation::class);
        $this->expectExceptionMessage('The Geo-6 POI provider does not support IP addresses, only street addresses.');

        $provider = new Geo6POI($this->getMockedHttpClient(), '', '');
        $provider->geocodeQuery(GeocodeQuery::create('127.0.0.1'));
    }

    public function testGeocodeWithLocalhostIPv6()
    {
        $this->expectException(\Geocoder\Exception\UnsupportedOperation::class);
        $this->expectExceptionMessage('The Geo-6 POI provider does not support IP addresses, only street addresses.');

        $provider = new Geo6POI($this->getMockedHttpClient(), '', '');
        $provider->geocodeQuery(GeocodeQuery::create('::1'));
    }

    public function testGeocodeWithRealIPv6()
    {
        $this->expectException(\Geocoder\Exception\UnsupportedOperation::class);
        $this->expectExceptionMessage('The Geo-6 POI provider does not support IP addresses, only street addresses.');

        $provider = new Geo6POI($this->getMockedHttpClient(), '', '');
        $provider->geocodeQuery(GeocodeQuery::create('::ffff:88.188.221.14'));
    }

    public function testReverseQuery()
    {
        $this->expectException(\Geocoder\Exception\UnsupportedOperation::class);
        $this->expectExceptionMessage('The Geo-6 POI provider does not support reverse geocoding.');

        $provider = new Geo6POI($this->getMockedHttpClient(), '', '');
        $provider->reverseQuery(ReverseQuery::fromCoordinates(0, 0));
    }

    public function testGeocodeQuery()
    {
        if (!isset($_SERVER['GEO6_CUSTOMER_ID']) || !isset($_SERVER['GEO6_API_KEY'])) {
            $this->markTestSkipped('You need to configure the GEO6_CUSTOMER_ID and GEO6_API_KEY value in phpunit.xml.dist');
        }

        $provider = new Geo6POI($this->getHttpClient(), $_SERVER['GEO6_CUSTOMER_ID'], $_SERVER['GEO6_API_KEY']);

        $query = GeocodeQuery::create('Manneken Pis')
            ->withLocale('fr');
        $results = $provider->geocodeQuery($query);

        $this->assertInstanceOf('Geocoder\Model\AddressCollection', $results);
        $this->assertCount(2, $results);

        /** @var \Geocoder\Model\Address $result */
        $result = $results->first();
        $this->assertInstanceOf('\Geocoder\Model\Address', $result);
        $this->assertEquals(50.844984, $result->getCoordinates()->getLatitude(), '', 0.00001);
        $this->assertEquals(4.350012, $result->getCoordinates()->getLongitude(), '', 0.00001);
        $this->assertEquals('MANNEKEN-PIS', $result->getName());
        $this->assertThat(
            $result->getType(),
            $this->logicalOr(
                $this->equalTo('Fontaines'),
                $this->equalTo('Lieux réputés')
            )
        );
    }

    public function testGeocodeQueryWithSource()
    {
        if (!isset($_SERVER['GEO6_CUSTOMER_ID']) || !isset($_SERVER['GEO6_API_KEY'])) {
            $this->markTestSkipped('You need to configure the GEO6_CUSTOMER_ID and GEO6_API_KEY value in phpunit.xml.dist');
        }

        $provider = new Geo6POI($this->getHttpClient(), $_SERVER['GEO6_CUSTOMER_ID'], $_SERVER['GEO6_API_KEY']);

        $query = GeocodeQuery::create('Manneken Pis')
            ->withLocale('fr')
            ->withData('source', 'urbis');
        $results = $provider->geocodeQuery($query);

        $this->assertInstanceOf('Geocoder\Model\AddressCollection', $results);
        $this->assertCount(2, $results);

        /** @var \Geocoder\Model\Address $result */
        $result = $results->first();
        $this->assertInstanceOf('\Geocoder\Model\Address', $result);
        $this->assertEquals(50.844984, $result->getCoordinates()->getLatitude(), '', 0.00001);
        $this->assertEquals(4.350012, $result->getCoordinates()->getLongitude(), '', 0.00001);
        $this->assertEquals('MANNEKEN-PIS', $result->getName());
        $this->assertThat(
            $result->getType(),
            $this->logicalOr(
                $this->equalTo('Fontaines'),
                $this->equalTo('Lieux réputés')
            )
        );
    }
}
