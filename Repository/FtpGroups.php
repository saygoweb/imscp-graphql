<?php
namespace iMSCP\Plugin\SGW_GraphQL\Repository;

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

use iMSCP\Plugin\SGW_GraphQL\Support\VhostRules;

/**
 * ProFTPD's group table: one row per customer, named after the customer's
 * login, listing their FTP users. Removing the last member removes the group
 * and its quota rows, as every page that touches it does.
 */
final class FtpGroups
{
    /** @var Db */
    private $db;

    public function __construct(Db $db)
    {
        $this->db = $db;
    }

    /**
     * @return array{groupname: string, members: string}|null
     */
    public function ofCustomer(string $customerUsername): ?array
    {
        return $this->db->row('SELECT groupname, members FROM ftp_group WHERE groupname = ?', array($customerUsername));
    }

    /**
     * The group holding an FTP user whose login ends in @$asciiHostName.
     *
     * CORE-DEBT(C1): transcribed from gui/include/Client.php:478-487.
     * CORE-DEBT(C11): with the name escaped for LIKE (C11 item 6).
     *
     * @return array{groupname: string, members: string}|null
     */
    public function withMemberOn(string $asciiHostName): ?array
    {
        return $this->db->row(
            'SELECT groupname, members FROM ftp_group JOIN ftp_users USING(gid) WHERE userid LIKE ? LIMIT 1',
            array('%@' . VhostRules::likeEscape($asciiHostName))
        );
    }

    /**
     * CORE-DEBT(C1): transcribed from gui/include/Client.php:488-521.
     *
     * @param array{groupname: string, members: string} $group
     * @param callable $isRemoved fn(string $member): bool
     */
    public function removeMembers(array $group, callable $isRemoved): void
    {
        $members = array_values(array_filter(
            preg_split('/,/', (string)$group['members'], -1, PREG_SPLIT_NO_EMPTY),
            static function (string $member) use ($isRemoved) {
                return !$isRemoved($member);
            }
        ));

        if ($members === array()) {
            $this->db->execute('DELETE FROM ftp_group WHERE groupname = ?', array($group['groupname']));
            $this->db->execute('DELETE FROM quotalimits WHERE name = ?', array($group['groupname']));
            $this->db->execute('DELETE FROM quotatallies WHERE name = ?', array($group['groupname']));

            return;
        }

        $this->db->execute(
            'UPDATE ftp_group SET members = ? WHERE groupname = ?',
            array(implode(',', $members), $group['groupname'])
        );
    }

    /**
     * CORE-DEBT(C3): transcribed from gui/public/client/ftp_add.php:289-299.
     */
    public function addMember(string $groupname, int $gid, string $member): void
    {
        $this->db->execute(
            'INSERT INTO ftp_group (groupname, gid, members) VALUES (?, ?, ?)'
                . " ON DUPLICATE KEY UPDATE members = CONCAT(members, ',', ?)",
            array($groupname, $gid, $member, $member)
        );
    }
}
