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

use iMSCP\Plugin\SGW_GraphQL\Support\GlobalId;
use iMSCP\Plugin\SGW_GraphQL\Support\NodeType;
use iMSCP\Plugin\SGW_GraphQL\Test\Authz\AuthzTestCase;

class FtpMutationsTest extends AuthzTestCase
{
    public function testAnFtpUserReadsBackWithAStringKeyedIdentifier(): void
    {
        $this->db->execute('UPDATE domain SET domain_ftpacc_limit = 0 WHERE domain_id = ?', array($this->fixture->domainId()));

        $result = $this->execute(
            'mutation($input: FtpUserCreateInput!) { ftpUserCreate(input: $input) { id username homeDirectory provisioning { state } } }',
            array('input' => array(
                'hostId' => GlobalId::encode(NodeType::DOMAIN, $this->fixture->domainId()),
                'username' => 'web', 'password' => 'Ftp0Password', 'directory' => '/shop'
            )),
            $this->fixture->identity('customer')
        );

        $userid = 'web@' . $this->fixture->domainName();
        self::assertArrayNotHasKey('errors', $result, json_encode($result));
        self::assertSame(array(
            'id'            => GlobalId::encodeKey(NodeType::FTP_USER, $userid),
            'username'      => $userid,
            'homeDirectory' => '/var/www/virtual/' . $this->fixture->domainName() . '/shop',
            'provisioning'  => array('state' => 'PENDING')
        ), $result['data']['ftpUserCreate']);
    }
}
