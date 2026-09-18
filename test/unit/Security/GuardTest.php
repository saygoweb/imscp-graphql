<?php
namespace iMSCP\Plugin\SGW_GraphQL\Test\Security;

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

use iMSCP\Plugin\SGW_GraphQL\Auth\Identity;
use iMSCP\Plugin\SGW_GraphQL\Auth\Scope;
use iMSCP\Plugin\SGW_GraphQL\Security\Guard;
use iMSCP\Plugin\SGW_GraphQL\Security\OwnershipResolver;
use iMSCP\Plugin\SGW_GraphQL\Support\ApiException;
use iMSCP\Plugin\SGW_GraphQL\Support\ErrorCode;
use iMSCP\Plugin\SGW_GraphQL\Support\GlobalId;
use iMSCP\Plugin\SGW_GraphQL\Support\NodeType;
use iMSCP\Plugin\SGW_GraphQL\Support\Quota;
use PHPUnit\Framework\TestCase;

class GuardTest extends TestCase
{
    /** @var string[] Every query the ownership resolver ran. */
    private $queries = array();

    /**
     * Reseller 5 created customers 7 and 8; customer 7 owns subdomain 3 and
     * FTP user a@b.test.
     */
    private function guard(): Guard
    {
        $this->queries = array();

        return new Guard(new OwnershipResolver(function (string $sql, array $bind) {
            $this->queries[] = $sql;

            if (strpos($sql, 'FROM subdomain AS s') !== false) {
                return $bind === array(3) ? array(array('owner_id' => 7)) : array();
            }

            if (strpos($sql, 'FROM ftp_users AS f') !== false) {
                return $bind === array('a@b.test') ? array(array('owner_id' => 7)) : array();
            }

            if (strpos($sql, 'SELECT created_by') !== false) {
                return array(array('created_by' => 5));
            }

            return array();
        }));
    }

    private function identity(int $adminId, string $type, ?int $createdBy, array $scopes = array()): Identity
    {
        return new Identity(
            $adminId, 'user' . $adminId, $type, $createdBy, null, $scopes,
            $scopes === array() ? null : 1
        );
    }

    private function codeOf(callable $fn): array
    {
        try {
            $fn();
        } catch (ApiException $e) {
            return array($e->getErrorCode(), $e->getExtensions());
        }

        self::fail('expected an ApiException');
    }

    public function testTheOwnerReachesTheTarget(): void
    {
        $target = $this->guard()->target(
            $this->identity(7, 'user', 5),
            GlobalId::encode(NodeType::SUBDOMAIN, 3),
            array(NodeType::SUBDOMAIN, NodeType::ALIAS_SUBDOMAIN),
            Scope::DOMAINS_WRITE,
            'id'
        );

        self::assertSame(NodeType::SUBDOMAIN, $target->getTag());
        self::assertSame(3, $target->getKey());
        self::assertSame(7, $target->getOwnerId());
    }

    public function testTheOwningResellerAndAnAdministratorReachIt(): void
    {
        $id = GlobalId::encode(NodeType::SUBDOMAIN, 3);

        self::assertSame(7, $this->guard()->target(
            $this->identity(5, 'reseller', 1), $id, array(NodeType::SUBDOMAIN), Scope::DOMAINS_WRITE, 'id'
        )->getOwnerId());
        self::assertSame(7, $this->guard()->target(
            $this->identity(1, 'admin', null), $id, array(NodeType::SUBDOMAIN), Scope::DOMAINS_WRITE, 'id'
        )->getOwnerId());
    }

    public function testASiblingGetsNotFoundNamingTheField(): void
    {
        list($code, $extensions) = $this->codeOf(function () {
            $this->guard()->target(
                $this->identity(8, 'user', 5),
                GlobalId::encode(NodeType::SUBDOMAIN, 3),
                array(NodeType::SUBDOMAIN), Scope::DOMAINS_WRITE, 'input.parentId'
            );
        });

        self::assertSame(ErrorCode::NOT_FOUND, $code);
        self::assertSame('input.parentId', $extensions['field']);
    }

    public function testOwnershipIsAskedBeforeScope(): void
    {
        // Spec section 8.1: a stranger with no scope learns NOT_FOUND, never
        // FORBIDDEN, because FORBIDDEN would confirm the object exists.
        $guard = $this->guard();

        list($code) = $this->codeOf(function () use ($guard) {
            $guard->target(
                $this->identity(8, 'user', 5, array(Scope::MAIL_READ)),
                GlobalId::encode(NodeType::SUBDOMAIN, 3),
                array(NodeType::SUBDOMAIN), Scope::DOMAINS_WRITE, 'id'
            );
        });

        self::assertSame(ErrorCode::NOT_FOUND, $code);
        self::assertNotEmpty($this->queries, 'ownership was resolved');
    }

    public function testTheOwnerWithoutTheScopeGetsForbidden(): void
    {
        list($code, $extensions) = $this->codeOf(function () {
            $this->guard()->target(
                $this->identity(7, 'user', 5, array(Scope::DOMAINS_READ)),
                GlobalId::encode(NodeType::SUBDOMAIN, 3),
                array(NodeType::SUBDOMAIN), Scope::DOMAINS_WRITE, 'id'
            );
        });

        self::assertSame(ErrorCode::FORBIDDEN, $code);
        self::assertSame(Scope::DOMAINS_WRITE, $extensions['scope']);
    }

    /**
     * @dataProvider unparseable
     */
    public function testAnIdentifierThatIsNotOneOfTheTagsIsNotFound(string $encoded): void
    {
        list($code) = $this->codeOf(function () use ($encoded) {
            Guard::parse($encoded, array(NodeType::SUBDOMAIN, NodeType::ALIAS_SUBDOMAIN));
        });

        self::assertSame(ErrorCode::NOT_FOUND, $code);
    }

    public function unparseable(): array
    {
        return array(
            'not base64'              => array('!!!'),
            'the wrong kind'          => array(GlobalId::encode(NodeType::MAIL_ACCOUNT, 3)),
            'a non-numeric int key'   => array(GlobalId::encodeKey(NodeType::SUBDOMAIN, 'abc')),
            'an unknown tag'          => array(GlobalId::encodeKey('Nonsense', '3')),
            'empty'                   => array('')
        );
    }

    public function testAStringKeyedTagParses(): void
    {
        $id = Guard::parse(GlobalId::encodeKey(NodeType::FTP_USER, 'a@b.test'), array(NodeType::FTP_USER));

        self::assertSame('a@b.test', $id->getKey());
    }

    public function testATargetOfAStringKeyedTagKeepsTheStringKey(): void
    {
        $target = $this->guard()->target(
            $this->identity(7, 'user', 5),
            GlobalId::encodeKey(NodeType::FTP_USER, 'a@b.test'),
            array(NodeType::FTP_USER), Scope::FTP_WRITE, 'id'
        );

        self::assertSame('a@b.test', $target->getKey());
    }

    public function testANonStringIdentifierIsNotFound(): void
    {
        // GraphQL's ID is always a string by the time it reaches a resolver,
        // but a service called from PHP could be handed anything.
        list($code) = $this->codeOf(function () {
            $this->guard()->target(
                $this->identity(7, 'user', 5), null, array(NodeType::SUBDOMAIN), Scope::DOMAINS_WRITE, 'id'
            );
        });

        self::assertSame(ErrorCode::NOT_FOUND, $code);
    }

    public function testAWithheldFeatureIsFeatureUnavailable(): void
    {
        list($code, $extensions) = $this->codeOf(function () {
            Guard::requireFeature(false, 'subdomains');
        });

        self::assertSame(ErrorCode::FEATURE_UNAVAILABLE, $code);
        self::assertSame('subdomains', $extensions['feature']);

        Guard::requireFeature(true, 'subdomains');
    }

    public function testAPendingObjectIsAConflictWithARetryHint(): void
    {
        list($code, $extensions) = $this->codeOf(function () {
            Guard::requireState('tochange', array('OK'));
        });

        self::assertSame(ErrorCode::CONFLICT, $code);
        self::assertSame(Guard::RETRY_AFTER_SECONDS, $extensions['retryAfterSeconds']);
        self::assertSame('PENDING', $extensions['state']);
    }

    public function testASettledObjectInTheWrongStateIsForbiddenWithoutARetryHint(): void
    {
        // Retrying cannot help: a disabled account stays disabled until a
        // reseller acts, so a retry hint would send a client into a loop.
        list($code, $extensions) = $this->codeOf(function () {
            Guard::requireState('disabled', array('OK'));
        });

        self::assertSame(ErrorCode::FORBIDDEN, $code);
        self::assertSame('DISABLED', $extensions['state']);
        self::assertArrayNotHasKey('retryAfterSeconds', $extensions);
    }

    public function testAFailedObjectMayBeDeleted(): void
    {
        // Spec section 8.3's one exception.
        Guard::requireState('Could not create vhost: permission denied', array('OK', 'ERROR'));
        Guard::requireState('ok', array('OK', 'ERROR'));
        $this->addToAssertionCount(2);
    }

    public function testAQuotaThatIsReachedIsLimitExceeded(): void
    {
        list($code, $extensions) = $this->codeOf(function () {
            Guard::requireQuota(Quota::fromCustomerLimit(2, 2), 'sqlDatabases');
        });

        self::assertSame(ErrorCode::LIMIT_EXCEEDED, $code);
        self::assertSame(2, $extensions['limit']);
        self::assertSame(2, $extensions['used']);
        self::assertSame('sqlDatabases', $extensions['quota']);
    }

    public function testAQuotaOverItsLimitIsAlsoExceeded(): void
    {
        // A reseller can lower a limit below what a customer already has.
        list($code) = $this->codeOf(function () {
            Guard::requireQuota(Quota::fromCustomerLimit(2, 5), 'sqlDatabases');
        });

        self::assertSame(ErrorCode::LIMIT_EXCEEDED, $code);
    }

    public function testRoomLeftAndUnlimitedPass(): void
    {
        Guard::requireQuota(Quota::fromCustomerLimit(2, 1), 'x');
        Guard::requireQuota(Quota::fromCustomerLimit(0, 999), 'x');
        $this->addToAssertionCount(2);
    }

    public function testBadInputNamesTheField(): void
    {
        $e = Guard::badInput('input.label', 'Nope.', array('maximum' => 3));

        self::assertSame(ErrorCode::BAD_USER_INPUT, $e->getErrorCode());
        self::assertSame(array('field' => 'input.label', 'maximum' => 3), $e->getExtensions());
    }

    public function testLimitExceededCarriesTheSameExtensionsRequireQuotaProduces(): void
    {
        // A limit measured against two ledgers has no Quota to hand, but the
        // client reads the same three keys either way.
        $e = Guard::limitExceeded('Not that many.', array('quota' => 'subdomains', 'limit' => 4, 'used' => 3));

        self::assertSame(ErrorCode::LIMIT_EXCEEDED, $e->getErrorCode());
        self::assertSame('Not that many.', $e->getMessage());
        self::assertSame(array('quota' => 'subdomains', 'limit' => 4, 'used' => 3), $e->getExtensions());
    }

    public function testLimitExceededNeedsNoExtensionsAtAll(): void
    {
        $e = Guard::limitExceeded('Not that many.');

        self::assertSame(ErrorCode::LIMIT_EXCEEDED, $e->getErrorCode());
        self::assertSame(array(), $e->getExtensions());
    }
}
