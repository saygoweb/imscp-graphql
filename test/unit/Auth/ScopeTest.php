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
}
