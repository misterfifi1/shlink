<?php
declare(strict_types=1);

namespace ShlinkioApiTest\Shlink\Rest\Fixtures;

use Cake\Chronos\Chronos;
use Doctrine\Common\DataFixtures\AbstractFixture;
use Doctrine\Common\Persistence\ObjectManager;
use ReflectionObject;
use Shlinkio\Shlink\Core\Entity\ShortUrl;
use Shlinkio\Shlink\Core\Model\ShortUrlMeta;

class ShortUrlsFixture extends AbstractFixture
{
    /**
     * Load data fixtures with the passed EntityManager
     *
     * @param ObjectManager $manager
     */
    public function load(ObjectManager $manager): void
    {
        $abcShortUrl = $this->setShortUrlDate(new ShortUrl('https://shlink.io'))->setShortCode('abc123');
        $manager->persist($abcShortUrl);

        $defShortUrl = $this->setShortUrlDate(new ShortUrl(
            'https://blog.alejandrocelaya.com/2017/12/09/acmailer-7-0-the-most-important-release-in-a-long-time/',
            ShortUrlMeta::createFromParams(Chronos::parse('2020-05-01'))
        ))->setShortCode('def456');
        $manager->persist($defShortUrl);

        $customShortUrl = $this->setShortUrlDate(new ShortUrl(
            'https://shlink.io',
            ShortUrlMeta::createFromParams(null, null, 'custom', 2)
        ));
        $manager->persist($customShortUrl);

        $manager->flush();

        $this->addReference('abc123_short_url', $abcShortUrl);
        $this->addReference('def456_short_url', $defShortUrl);
    }

    private function setShortUrlDate(ShortUrl $shortUrl): ShortUrl
    {
        $ref = new ReflectionObject($shortUrl);
        $dateProp = $ref->getProperty('dateCreated');
        $dateProp->setAccessible(true);
        $dateProp->setValue($shortUrl, Chronos::create(2019, 1, 1, 0, 0, 0));

        return $shortUrl;
    }
}
