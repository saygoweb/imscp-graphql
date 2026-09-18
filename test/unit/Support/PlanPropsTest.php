<?php
namespace iMSCP\Plugin\SGW_GraphQL\Test\Support;

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

use iMSCP\Plugin\SGW_GraphQL\Support\Allowances;
use iMSCP\Plugin\SGW_GraphQL\Support\PlanProps;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class PlanPropsTest extends TestCase
{
    /**
     * A plan as hosting_plan_add.php:446 writes it: PHP and CGI on, DNS off,
     * ten subdomains, five aliases, unlimited mail, no FTP, two databases,
     * two SQL users, 10 GiB of traffic, 5 GiB of disk, domain and SQL backup,
     * the PHP editor on, external mail off, web folder protection on and a
     * 100 MiB default mailbox.
     */
    const SAMPLE = '_yes_;_yes_;10;5;0;-1;2;2;10240;5120;_dmn_|_sql_;_no_;yes;'
        . 'no;no;no;yes;10;5;30;60;128;_no_;_yes_;104857600';

    public function testItParsesTwentyFiveFields(): void
    {
        self::assertCount(25, PlanProps::FIELDS);

        $props = PlanProps::parse(self::SAMPLE);

        self::assertSame(
            array('enabled' => true, 'limit' => 10), $props->allowance('subdomains')
        );
        self::assertSame(
            array('enabled' => true, 'limit' => 5), $props->allowance('domainAliases')
        );
    }

    public function testZeroIsUnlimitedAndMinusOneIsWithheld(): void
    {
        // The same three-valued encoding as a customer's own limits: spec
        // section 2.3. A plan that says -1 for FTP creates a customer with the
        // FTP feature withheld, not with a limit of minus one.
        $props = PlanProps::parse(self::SAMPLE);

        self::assertSame(
            array('enabled' => true, 'limit' => null), $props->allowance('mailAccounts')
        );
        self::assertSame(
            array('enabled' => false, 'limit' => 0), $props->allowance('ftpUsers')
        );
    }

    public function testStorageIsReportedInBytes(): void
    {
        // The props file holds MiB for disk and traffic and bytes for the mail
        // quota. Everything BigInt in this schema is bytes: decision D3.
        self::assertSame(
            array(
                'disk'      => 5120 * 1048576,
                'traffic'   => 10240 * 1048576,
                'mailQuota' => 104857600
            ),
            PlanProps::parse(self::SAMPLE)->storage()
        );
    }

    public function testAZeroStorageLimitIsUnlimited(): void
    {
        $props = PlanProps::parse(str_replace(';10240;5120;', ';0;0;', self::SAMPLE));

        self::assertNull($props->storage()['disk']);
        self::assertNull($props->storage()['traffic']);
    }

    public function testFeaturesReadTheUnderscoredBooleans(): void
    {
        self::assertSame(
            array(
                'php'                 => true,
                'phpEditor'           => true,
                'cgi'                 => true,
                'customDns'           => false,
                'externalMail'        => false,
                'webFolderProtection' => true,
                'backup'              => array('DOMAIN', 'SQL')
            ),
            PlanProps::parse(self::SAMPLE)->features()
        );
    }

    public function testAnEmptyBackupFieldIsNoBackupTargets(): void
    {
        $props = PlanProps::parse(str_replace(';_dmn_|_sql_;', ';;', self::SAMPLE));

        self::assertSame(array(), $props->features()['backup']);
    }

    public function testItRoundTripsByteForByte(): void
    {
        self::assertSame(self::SAMPLE, PlanProps::parse(self::SAMPLE)->toString());
    }

    /**
     * Spec section 17 requires this as a property test: parse then re-emit
     * must be byte-identical for generated inputs as well as for real plans.
     */
    public function testItRoundTripsForGeneratedInputs(): void
    {
        mt_srand(20260905);

        for ($i = 0; $i < 500; $i++) {
            $props = implode(';', array(
                $this->bool(), $this->bool(),
                $this->limit(), $this->limit(), $this->limit(), $this->limit(),
                $this->limit(), $this->limit(), $this->limit(), $this->limit(),
                $this->backup(), $this->bool(),
                $this->perm(), $this->perm(), $this->perm(),
                $this->disableFunctions(), $this->perm(),
                (string)mt_rand(1, 1024), (string)mt_rand(1, 1024),
                (string)mt_rand(1, 600), (string)mt_rand(1, 600),
                (string)mt_rand(16, 4096),
                $this->bool(), $this->bool(),
                (string)(mt_rand(0, 1024) * 1048576)
            ));

            self::assertSame($props, PlanProps::parse($props)->toString(), $props);
        }
    }

    private function bool(): string
    {
        return mt_rand(0, 1) ? '_yes_' : '_no_';
    }

    private function perm(): string
    {
        return mt_rand(0, 1) ? 'yes' : 'no';
    }

    private function disableFunctions(): string
    {
        $values = array('yes', 'no', 'exec');

        return $values[mt_rand(0, 2)];
    }

    private function limit(): string
    {
        return (string)mt_rand(-1, 500);
    }

    private function backup(): string
    {
        $chosen = array();

        foreach (array('_dmn_', '_sql_', '_mail_') as $target) {
            if (mt_rand(0, 1)) {
                $chosen[] = $target;
            }
        }

        return implode('|', $chosen);
    }

    public function testTooFewFieldsIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PlanProps::parse('_yes_;_no_;1;2;3');
    }

    public function testTooManyFieldsIsRejected(): void
    {
        // A 26th field would silently shift every reader by one, which is how
        // a customer ends up with somebody else's limits.
        $this->expectException(InvalidArgumentException::class);

        PlanProps::parse(self::SAMPLE . ';extra');
    }

    public function testAnUnknownAllowanceNameIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PlanProps::parse(self::SAMPLE)->allowance('htaccessUsers');
    }

    /**
     * Spec section 17 asks for this as a property test: whatever the panel
     * stored, parsing and re-emitting it must give back the same 25 fields in
     * the same order, byte for byte. A props string that does not round-trip
     * silently rewrites a reseller's plan.
     */
    public function testEveryPropsStringRoundTrips(): void
    {
        foreach ($this->propsVectors() as $label => $props) {
            self::assertSame($props, PlanProps::parse($props)->toString(), $label);
        }
    }

    public function testAllowancesRoundTripThroughPropsAndBack(): void
    {
        foreach ($this->propsVectors() as $label => $props) {
            $once = Allowances::fromPlanProps(PlanProps::parse($props));

            self::assertSame($props, $once->toProps()->toString(), $label);
        }
    }

    /** @return array<string, string> */
    public function propsVectors(): array
    {
        // The box's own plan, then the shapes the pages can write:
        // hosting_plan_add.php:446-457 builds exactly this string.
        return array(
            'the panel default' =>
                '_no_;_no_;0;0;0;0;0;0;0;0;_no_;_no_;no;no;no;no;no;0;0;0;0;0;_no_;_yes_;0',
            'everything on, unlimited' =>
                '_yes_;_yes_;0;0;0;0;0;0;0;0;_dmn_|_sql_|_mail_;_yes_;yes;yes;yes;yes;yes;10;10;30;60;128;_yes_;_yes_;0',
            'everything withheld' =>
                '_no_;_no_;-1;-1;-1;-1;-1;-1;-1;-1;_no_;_no_;no;no;no;no;no;0;0;0;0;0;_no_;_yes_;0',
            'finite limits' =>
                '_yes_;_no_;5;3;20;10;4;4;10240;5120;_dmn_;_yes_;yes;no;no;no;yes;8;8;30;60;64;_no_;_yes_;104857600',
            'a single backup target' =>
                '_yes_;_no_;1;1;1;1;1;1;1;1;_sql_;_no_;no;no;no;no;no;0;0;0;0;0;_no_;_no_;1048576'
        );
    }
}
