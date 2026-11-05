<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\TechDemoPing;
use App\Tests\Support\ResetsDatabase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class TechDemoTest extends WebTestCase
{
    use ResetsDatabase;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        self::resetDatabase();
    }

    public function testRootRedirectsToDemoPage(): void
    {
        $this->client->request('GET', '/');

        self::assertResponseRedirects('/tech/demo');
    }

    public function testDemoPageShowsDataFromSqlite(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->persist(new TechDemoPing());
        $em->persist(new TechDemoPing());
        $em->flush();

        $crawler = $this->client->request('GET', '/tech/demo');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('[data-testid="ping-count"]', '2');
        self::assertSelectorTextSame('[data-testid="user-count"]', '0');
        self::assertMatchesRegularExpression('/^3\.\d+\.\d+$/', $crawler->filter('[data-testid="sqlite-version"]')->text());
        self::assertCount(1, $crawler->filter('canvas[data-chart="bar"]'));
        self::assertCount(1, $crawler->filter('[x-data]'));
        self::assertCount(1, $crawler->filter('form[hx-post="/tech/demo/ping"]'));
    }

    public function testHtmxPingStoresRowAndReturnsOnlyTheFragment(): void
    {
        $crawler = $this->client->request('GET', '/tech/demo');
        $form = $crawler->filter('[data-testid="ping-button"]')->form();

        $this->client->submit($form, [], ['HTTP_HX-Request' => 'true']);

        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringStartsWith('<section id="ping-panel"', trim(preg_replace('/^\{#.*?#\}/s', '', $html) ?? ''));
        self::assertStringNotContainsString('<html', $html);
        self::assertSelectorTextSame('[data-testid="ping-count"]', '1');
        self::assertSame(1, self::getContainer()->get(EntityManagerInterface::class)->getRepository(TechDemoPing::class)->count());
    }

    public function testNonHtmxPingRedirectsBackToPage(): void
    {
        $crawler = $this->client->request('GET', '/tech/demo');
        $this->client->submit($crawler->filter('[data-testid="ping-button"]')->form());

        self::assertResponseStatusCodeSame(Response::HTTP_SEE_OTHER);
        self::assertResponseRedirects('/tech/demo');
    }

    public function testPingWithoutValidCsrfTokenIsRejected(): void
    {
        $this->client->request('POST', '/tech/demo/ping', ['_token' => 'invalid'], server: ['HTTP_HX-Request' => 'true']);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertSame(0, self::getContainer()->get(EntityManagerInterface::class)->getRepository(TechDemoPing::class)->count());
    }
}
