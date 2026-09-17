<?php
namespace iMSCP\Plugin\SGW_GraphQL\Test\Integration;

/**
 * i-MSCP SGW_GraphQL plugin
 * Copyright (C) 2026 Cambell Prince <cambell.prince@gmail.com>
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
 */

use iMSCP\Plugin\SGW_GraphQL\Repository\Db;
use iMSCP\Plugin\SGW_GraphQL\Service\PanelCore;
use iMSCP\Plugin\SGW_GraphQL\Support\MailType;
use InvalidArgumentException;

/**
 * The port against the real panel. Expected values are measurements M17-M19,
 * taken in the box before this plan was written.
 */
class PanelCoreTest extends IntegrationTestCase
{
    /** @var PanelCore */
    private $core;

    protected function setUp(): void
    {
        $this->core = new PanelCore(false);
    }

    public function testTheDomainNameValidatorGivesThePanelsReason(): void
    {
        self::assertNull($this->core->domainNameError('shop.example.test'));
        self::assertSame(
            'Usage of dot in domain name labels is prohibited.',
            $this->core->domainNameError('bad..name')
        );
        self::assertNotNull($this->core->domainNameError('single'), 'one label is not a domain');
    }

    public function testTheReasonDoesNotLeakFromOneCallToTheNext(): void
    {
        // isValidDomainName() reports through a global. A stale message from
        // an earlier failure must not be read as the reason for a later one.
        $this->core->domainNameError('bad..name');

        self::assertNull($this->core->domainNameError('good.example.test'));
    }

    public function testTheIdnCodecRoundTrips(): void
    {
        self::assertSame('xn--bcher-kva.test', $this->core->toAscii('bücher.test'));
        self::assertSame('bücher.test', $this->core->toUnicode('xn--bcher-kva.test'));
    }

    public function testTheEmailValidators(): void
    {
        self::assertTrue($this->core->isValidEmail('a@example.net'));
        self::assertFalse($this->core->isValidEmail('not an email'));
        self::assertTrue($this->core->isValidEmailLocalPart('sales'));
    }

    public function testThePasswordPolicyIsThePanels(): void
    {
        // PASSWD_CHARS 6 and PASSWD_STRONG 1 on the box (M17).
        self::assertFalse($this->core->isAcceptablePassword('abc'));
        self::assertFalse($this->core->isAcceptablePassword('lettersonly'));
        self::assertTrue($this->core->isAcceptablePassword('Str0ngPassw0rd'));
    }

    public function testTheUsernameValidator(): void
    {
        self::assertTrue($this->core->isValidUsername('ftpuser'));
        self::assertFalse($this->core->isValidUsername('-bad'));
    }

    public function testTheSqlHostRule(): void
    {
        self::assertTrue($this->core->isValidSqlHost('localhost'));
        self::assertTrue($this->core->isValidSqlHost('%'));
        self::assertTrue($this->core->isValidSqlHost('db.example.net'));
        self::assertTrue($this->core->isValidSqlHost('10.0.0.1'));
        self::assertFalse($this->core->isValidSqlHost('bad host'));
    }

    public function testPasswordsAreHashedAsTheBackendExpects(): void
    {
        self::assertStringStartsWith('$6$', $this->core->hashPassword('x'));
    }

    public function testPathNormalisation(): void
    {
        self::assertSame('/x', $this->core->normalisePath('/htdocs/../x'));
        self::assertSame('/htdocs/a/b', $this->core->normalisePath('/htdocs//a/./b/'));
    }

    public function testConfigFallsBackToTheDefault(): void
    {
        self::assertSame('/var/www/virtual', $this->core->config('USER_WEB_DIR'));
        self::assertSame('fallback', $this->core->config('SGWT_NO_SUCH_KEY', 'fallback'));
    }

    /**
     * @dataProvider forwardUrls
     */
    public function testForwardUrlsAreNormalisedAsThePagesDo(string $url, string $expected): void
    {
        self::assertSame($expected, $this->core->normaliseForwardUrl($url, 'self.example.test', false));
    }

    public function forwardUrls(): array
    {
        return array(
            'scheme and host lower-cased, path collapsed' => array('HTTP://Example.COM/a/../b', 'http://example.com/b/'),
            'a trailing slash added'                      => array('https://example.net', 'https://example.net/'),
            'port, query and fragment kept'               => array('http://example.net:8080/x?y=1#f', 'http://example.net:8080/x/?y=1#f'),
            'ftp is a scheme the panel accepts'           => array('ftp://files.example.net/pub', 'ftp://files.example.net/pub/')
        );
    }

    /**
     * @dataProvider refusedForwardUrls
     */
    public function testForwardUrlsThePagesRefuse(string $url, bool $proxy): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->core->normaliseForwardUrl($url, 'self.example.test', $proxy);
    }

    public function refusedForwardUrls(): array
    {
        return array(
            'not a scheme the panel accepts' => array('javascript:alert(1)', false),
            'no host'                        => array('http://', false),
            'a space in the host'            => array('http://bad host/', false),
            'forwarded to itself'            => array('https://self.example.test/', false),
            'forwarded to itself on 443'     => array('https://self.example.test:443', false),
            'a proxy to a privileged port'   => array('http://example.net:80/', true)
        );
    }

    public function testAForwardToItselfOnAnotherPathIsAllowed(): void
    {
        self::assertSame(
            'https://self.example.test/app/',
            $this->core->normaliseForwardUrl('https://self.example.test/app', 'self.example.test', false)
        );
    }

    public function testDomainExistsSeesTheFixturesDomain(): void
    {
        $db = Db::fromPanel();
        $fixture = new Fixture($db);
        $fixture->seed();

        try {
            self::assertTrue($this->core->domainExists($fixture->domainName(), $fixture->resellerId()));
            self::assertFalse($this->core->domainExists('sgwt-nobody-owns-this.test', $fixture->resellerId()));
        } finally {
            $fixture->rollBack();
        }
    }

    public function testSavePhpIniWritesTheVhostsRow(): void
    {
        $db = Db::fromPanel();
        $fixture = new Fixture($db);
        $fixture->seed();

        try {
            $this->core->savePhpIni(
                $fixture->resellerId(), $fixture->customerId(), $fixture->domainId(),
                $fixture->subdomainId(), 'sub'
            );

            self::assertSame('1', (string)$db->value(
                "SELECT COUNT(*) FROM php_ini WHERE admin_id = ? AND domain_id = ? AND domain_type = 'sub'",
                array($fixture->customerId(), $fixture->subdomainId())
            ));
        } finally {
            $fixture->rollBack();
        }
    }

    public function testDefaultMailAccountsForASubdomainAreOneWebmaster(): void
    {
        // Inside the fixture's transaction: this is also D11 working, since
        // createDefaultMailAccounts() opens a transaction of its own.
        $db = Db::fromPanel();
        $fixture = new Fixture($db);
        $fixture->seed();

        try {
            $mailType = MailType::toMailType(MailType::HOST_SUB, MailType::KIND_FORWARD);

            $this->core->createDefaultMailAccounts(
                $fixture->domainId(), 'owner@example.test', $fixture->subdomainName(),
                $mailType, $fixture->subdomainId()
            );

            // mail_users.sub_id is not unique by itself: the fixture's own
            // 'blog' alias subdomain (table subdomain_alias) and the 'shop'
            // subdomain (table subdomain) each get their id from a separate
            // AUTO_INCREMENT sequence, and every Fixture::seed() call inserts
            // exactly one row into each, so the two counters stay in lock
            // step and the ids collide on every run. mail_type disambiguates,
            // the same way Repository\Counts::mailAccounts() does.
            self::assertSame(
                array(array('mail_addr' => 'webmaster@' . $fixture->subdomainName(), 'mail_type' => 'subdom_forward', 'status' => 'toadd')),
                $db->rows(
                    'SELECT mail_addr, mail_type, status FROM mail_users WHERE domain_id = ? AND sub_id = ? AND mail_type = ?',
                    array($fixture->domainId(), $fixture->subdomainId(), $mailType)
                )
            );
        } finally {
            $fixture->rollBack();
        }
    }
}
