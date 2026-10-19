<?php
namespace iMSCP\Plugin\SGW_GraphQL\Test\Auth;

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

use iMSCP\Plugin\SGW_GraphQL\Auth\Scope;
use iMSCP\Plugin\SGW_GraphQL\Support\ApiException;
use iMSCP\Plugin\SGW_GraphQL\Support\ErrorCode;
use PHPUnit\Framework\TestCase;

class ScopeTest extends TestCase
{
    public function testTheVocabularyMatchesTheSpecification(): void
    {
        self::assertSame([
            'ACCOUNT_READ',
            'DOMAINS_READ', 'DOMAINS_WRITE',
            'MAIL_READ', 'MAIL_WRITE',
            'FTP_READ', 'FTP_WRITE',
            'SQL_READ', 'SQL_WRITE',
            'DNS_READ', 'DNS_WRITE',
            'CUSTOMERS_READ', 'CUSTOMERS_WRITE',
            'RESELLERS_READ', 'RESELLERS_WRITE',
        ], Scope::all());
    }

    public function testValidationIsExact(): void
    {
        self::assertTrue(Scope::isValid('DOMAINS_WRITE'));
        self::assertFalse(Scope::isValid('domains_write'));
        self::assertFalse(Scope::isValid('EVERYTHING'));
    }

    // ---- the role rule tokenIssue and Api\Executor share ------------------

    public function testAnAdministratorCanHoldEveryScope(): void
    {
        self::assertSame(Scope::all(), Scope::grantableTo('admin'));
    }

    public function testAResellerCannotHoldTheResellerScopes(): void
    {
        $grantable = Scope::grantableTo('reseller');

        self::assertContains(Scope::CUSTOMERS_WRITE, $grantable);
        self::assertNotContains(Scope::RESELLERS_READ, $grantable);
        self::assertNotContains(Scope::RESELLERS_WRITE, $grantable);
    }

    public function testACustomerCannotHoldTheCustomerOrResellerScopes(): void
    {
        $grantable = Scope::grantableTo('user');

        self::assertContains(Scope::DOMAINS_WRITE, $grantable);
        self::assertContains(Scope::ACCOUNT_READ, $grantable);

        foreach (array(Scope::CUSTOMERS_READ, Scope::CUSTOMERS_WRITE,
            Scope::RESELLERS_READ, Scope::RESELLERS_WRITE) as $scope
        ) {
            self::assertNotContains($scope, $grantable);
        }
    }

    public function testAnUnknownAdminTypeIsTheNarrowestRole(): void
    {
        self::assertSame(Scope::grantableTo('user'), Scope::grantableTo('superuser'));
    }

    public function testRequireGrantableKeepsTheAskedScopesOnceEach(): void
    {
        self::assertSame(
            array(Scope::DOMAINS_READ, Scope::MAIL_READ),
            Scope::requireGrantable('user', array(Scope::DOMAINS_READ, Scope::MAIL_READ, Scope::DOMAINS_READ))
        );
    }

    public function testAScopeTheRoleCannotHoldIsForbiddenNotDropped(): void
    {
        $e = $this->refusal(function (): void {
            Scope::requireGrantable('user', array(Scope::DOMAINS_READ, Scope::RESELLERS_READ));
        });

        self::assertSame(ErrorCode::FORBIDDEN, $e->getErrorCode());
        self::assertSame(Scope::RESELLERS_READ, $e->getExtensions()['scope']);
    }

    public function testAStringThatIsNotAScopeIsBadInput(): void
    {
        $e = $this->refusal(function (): void {
            Scope::requireGrantable('admin', array('EVERYTHING'), 'scopes');
        });

        self::assertSame(ErrorCode::BAD_USER_INPUT, $e->getErrorCode());
        self::assertSame('scopes', $e->getExtensions()['field']);
    }

    /**
     * Identity::hasScope() reads an empty list as a full credential, so an
     * empty list is the one request that must never be granted as asked.
     */
    public function testAnEmptyListIsRefused(): void
    {
        $e = $this->refusal(function (): void {
            Scope::requireGrantable('admin', array());
        });

        self::assertSame(ErrorCode::BAD_USER_INPUT, $e->getErrorCode());
        self::assertSame('input.scopes', $e->getExtensions()['field']);
    }

    private function refusal(callable $call): ApiException
    {
        try {
            $call();
        } catch (ApiException $e) {
            return $e;
        }

        self::fail('Expected an ApiException.');
    }
}
