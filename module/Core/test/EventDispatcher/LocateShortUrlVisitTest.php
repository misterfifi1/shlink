<?php
declare(strict_types=1);

namespace ShlinkioTest\Shlink\Core\EventDispatcher;

use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\Prophecy\ObjectProphecy;
use Psr\Log\LoggerInterface;
use Shlinkio\Shlink\CLI\Exception\GeolocationDbUpdateFailedException;
use Shlinkio\Shlink\CLI\Util\GeolocationDbUpdaterInterface;
use Shlinkio\Shlink\Common\Exception\WrongIpException;
use Shlinkio\Shlink\Common\IpGeolocation\IpLocationResolverInterface;
use Shlinkio\Shlink\Common\IpGeolocation\Model\Location;
use Shlinkio\Shlink\Common\Util\IpAddress;
use Shlinkio\Shlink\Core\Entity\ShortUrl;
use Shlinkio\Shlink\Core\Entity\Visit;
use Shlinkio\Shlink\Core\Entity\VisitLocation;
use Shlinkio\Shlink\Core\EventDispatcher\LocateShortUrlVisit;
use Shlinkio\Shlink\Core\EventDispatcher\ShortUrlVisited;
use Shlinkio\Shlink\Core\Model\Visitor;
use Shlinkio\Shlink\Core\Visit\Model\UnknownVisitLocation;

class LocateShortUrlVisitTest extends TestCase
{
    /** @var LocateShortUrlVisit */
    private $locateVisit;
    /** @var ObjectProphecy */
    private $ipLocationResolver;
    /** @var ObjectProphecy */
    private $em;
    /** @var ObjectProphecy */
    private $logger;
    /** @var ObjectProphecy */
    private $dbUpdater;

    public function setUp(): void
    {
        $this->ipLocationResolver = $this->prophesize(IpLocationResolverInterface::class);
        $this->em = $this->prophesize(EntityManagerInterface::class);
        $this->logger = $this->prophesize(LoggerInterface::class);
        $this->dbUpdater = $this->prophesize(GeolocationDbUpdaterInterface::class);

        $this->locateVisit = new LocateShortUrlVisit(
            $this->ipLocationResolver->reveal(),
            $this->em->reveal(),
            $this->logger->reveal(),
            $this->dbUpdater->reveal()
        );
    }

    /** @test */
    public function invalidVisitLogsWarning(): void
    {
        $event = new ShortUrlVisited('123');
        $findVisit = $this->em->find(Visit::class, '123')->willReturn(null);
        $logWarning = $this->logger->warning('Tried to locate visit with id "123", but it does not exist.');

        ($this->locateVisit)($event);

        $findVisit->shouldHaveBeenCalledOnce();
        $this->em->flush(Argument::cetera())->shouldNotHaveBeenCalled();
        $this->ipLocationResolver->resolveIpLocation(Argument::cetera())->shouldNotHaveBeenCalled();
        $logWarning->shouldHaveBeenCalled();
    }

    /** @test */
    public function invalidAddressLogsWarning(): void
    {
        $event = new ShortUrlVisited('123');
        $findVisit = $this->em->find(Visit::class, '123')->willReturn(
            new Visit(new ShortUrl(''), new Visitor('', '', '1.2.3.4'))
        );
        $resolveLocation = $this->ipLocationResolver->resolveIpLocation(Argument::cetera())->willThrow(
            WrongIpException::class
        );
        $logWarning = $this->logger->warning(
            Argument::containingString('Tried to locate visit with id "123", but its address seems to be wrong.'),
            Argument::type('array')
        );

        ($this->locateVisit)($event);

        $findVisit->shouldHaveBeenCalledOnce();
        $resolveLocation->shouldHaveBeenCalledOnce();
        $logWarning->shouldHaveBeenCalled();
        $this->em->flush(Argument::cetera())->shouldNotHaveBeenCalled();
    }

    /**
     * @test
     * @dataProvider provideNonLocatableVisits
     */
    public function nonLocatableVisitsResolveToEmptyLocations(Visit $visit): void
    {
        $event = new ShortUrlVisited('123');
        $findVisit = $this->em->find(Visit::class, '123')->willReturn($visit);
        $flush = $this->em->flush($visit)->will(function () {
        });
        $resolveIp = $this->ipLocationResolver->resolveIpLocation(Argument::any());

        ($this->locateVisit)($event);

        $this->assertEquals($visit->getVisitLocation(), new VisitLocation(Location::emptyInstance()));
        $findVisit->shouldHaveBeenCalledOnce();
        $flush->shouldHaveBeenCalledOnce();
        $resolveIp->shouldNotHaveBeenCalled();
        $this->logger->warning(Argument::cetera())->shouldNotHaveBeenCalled();
    }

    public function provideNonLocatableVisits(): iterable
    {
        $shortUrl = new ShortUrl('');

        yield 'null IP' => [new Visit($shortUrl, new Visitor('', '', null))];
        yield 'empty IP' => [new Visit($shortUrl, new Visitor('', '', ''))];
        yield 'localhost' => [new Visit($shortUrl, new Visitor('', '', IpAddress::LOCALHOST))];
    }

    /** @test */
    public function locatableVisitsResolveToLocation(): void
    {
        $ipAddr = '1.2.3.0';
        $visit = new Visit(new ShortUrl(''), new Visitor('', '', $ipAddr));
        $location = new Location('', '', '', '', 0.0, 0.0, '');
        $event = new ShortUrlVisited('123');

        $findVisit = $this->em->find(Visit::class, '123')->willReturn($visit);
        $flush = $this->em->flush($visit)->will(function () {
        });
        $resolveIp = $this->ipLocationResolver->resolveIpLocation($ipAddr)->willReturn($location);

        ($this->locateVisit)($event);

        $this->assertEquals($visit->getVisitLocation(), new VisitLocation($location));
        $findVisit->shouldHaveBeenCalledOnce();
        $flush->shouldHaveBeenCalledOnce();
        $resolveIp->shouldHaveBeenCalledOnce();
        $this->logger->warning(Argument::cetera())->shouldNotHaveBeenCalled();
    }

    /** @test */
    public function errorWhenUpdatingGeoliteWithExistingCopyLogsWarning(): void
    {
        $e = GeolocationDbUpdateFailedException::create(true);
        $ipAddr = '1.2.3.0';
        $visit = new Visit(new ShortUrl(''), new Visitor('', '', $ipAddr));
        $location = new Location('', '', '', '', 0.0, 0.0, '');
        $event = new ShortUrlVisited('123');

        $findVisit = $this->em->find(Visit::class, '123')->willReturn($visit);
        $flush = $this->em->flush($visit)->will(function () {
        });
        $resolveIp = $this->ipLocationResolver->resolveIpLocation($ipAddr)->willReturn($location);
        $checkUpdateDb = $this->dbUpdater->checkDbUpdate(Argument::cetera())->willThrow($e);

        ($this->locateVisit)($event);

        $this->assertEquals($visit->getVisitLocation(), new VisitLocation($location));
        $findVisit->shouldHaveBeenCalledOnce();
        $flush->shouldHaveBeenCalledOnce();
        $resolveIp->shouldHaveBeenCalledOnce();
        $checkUpdateDb->shouldHaveBeenCalledOnce();
        $this->logger->warning(
            'GeoLite2 database update failed. Proceeding with old version. {e}',
            ['e' => $e]
        )->shouldHaveBeenCalledOnce();
    }

    /** @test */
    public function errorWhenDownloadingGeoliteCancelsLocation(): void
    {
        $e = GeolocationDbUpdateFailedException::create(false);
        $ipAddr = '1.2.3.0';
        $visit = new Visit(new ShortUrl(''), new Visitor('', '', $ipAddr));
        $location = new Location('', '', '', '', 0.0, 0.0, '');
        $event = new ShortUrlVisited('123');

        $findVisit = $this->em->find(Visit::class, '123')->willReturn($visit);
        $flush = $this->em->flush($visit)->will(function () {
        });
        $resolveIp = $this->ipLocationResolver->resolveIpLocation($ipAddr)->willReturn($location);
        $checkUpdateDb = $this->dbUpdater->checkDbUpdate(Argument::cetera())->willThrow($e);
        $logError = $this->logger->error(
            'GeoLite2 database download failed. It is not possible to locate visit with id 123. {e}',
            ['e' => $e]
        );

        ($this->locateVisit)($event);

        $this->assertEquals($visit->getVisitLocation(), new UnknownVisitLocation());
        $findVisit->shouldHaveBeenCalledOnce();
        $flush->shouldNotHaveBeenCalled();
        $resolveIp->shouldNotHaveBeenCalled();
        $checkUpdateDb->shouldHaveBeenCalledOnce();
        $logError->shouldHaveBeenCalledOnce();
    }
}
