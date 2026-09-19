<?php
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

/**
 * Provision real objects through the API's services, run i-MSCP's backend over
 * them, check the result on disk and in MariaDB, then delete them and check
 * again. Spec section 17's integration layer.
 *
 * Run inside the container, as root:
 *
 *   ../imscp/docker/imscp exec sh -c \
 *     'cd /var/www/imscp-plugins/imscp-graphql && php7.4 test/api/provision.php [customer-login]'
 *
 * It commits. Everything it creates is named sgwe2e*, and a run that dies
 * part way is swept up by the next run against the same customer - the
 * script runs as that customer's own identity, so it cannot reach another
 * customer's objects. A run aimed at a different customer must be swept by
 * naming that customer again.
 *
 * It also runs a whole customer through customerCreate and customerDelete, as
 * an administrator: a throwaway account and domain (also sgwe2e*), created
 * under the primary customer's own reseller, settled, checked in the
 * database and on disk, then deleted and checked again. That part is swept by
 * admin_name rather than by the primary customer's own reachable objects, so
 * it is found and cleaned up regardless of which customer this script was
 * pointed at.
 */

require '/var/www/imscp/gui/include/imscp-lib.php';
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use GraphQL\Error\Error;
use GraphQL\GraphQL;
use iMSCP\Plugin\SGW_GraphQL\Api\Container;
use iMSCP\Plugin\SGW_GraphQL\Auth\Identity;
use iMSCP\Plugin\SGW_GraphQL\Repository\Db;
use iMSCP\Plugin\SGW_GraphQL\Service\MariaDbSqlServer;
use iMSCP\Plugin\SGW_GraphQL\Service\PanelCore;
use iMSCP\Plugin\SGW_GraphQL\Service\VfsDirectoryProbe;
use iMSCP\Plugin\SGW_GraphQL\Support\ErrorFactory;
use iMSCP\Plugin\SGW_GraphQL\Support\GlobalId;
use iMSCP\Plugin\SGW_GraphQL\Support\NodeType;

const REQUEST_MANAGER = '/var/www/imscp/engine/imscp-rqst-mngr';
const VHOST_DIR = '/etc/apache2/sites-available';
const SETTLE_SECONDS = 180;
const PREFIX = 'sgwe2e';

$passed = 0;
$failed = 0;

function check(string $what, bool $ok, string $detail = ''): void
{
    global $passed, $failed;

    if ($ok) {
        $passed++;
        printf("  ok    %s\n", $what);
    } else {
        $failed++;
        printf("  FAIL  %s%s\n", $what, $detail === '' ? '' : ' (' . $detail . ')');
    }
}

function die2(string $message): void
{
    fwrite(STDERR, 'provision.php: ' . $message . "\n");
    exit(2);
}

$db = Db::fromPanel();

// ---- the customer ---------------------------------------------------------

$login = $argv[1] ?? null;
$account = $db->row(
    "
        SELECT a.admin_id, a.admin_name, a.admin_type, a.created_by, a.email, d.domain_id, d.domain_name,
            d.domain_status, d.domain_subd_limit, d.domain_mailacc_limit, d.domain_ftpacc_limit,
            d.domain_sqld_limit, d.domain_sqlu_limit, d.domain_dns, d.mail_quota, d.domain_ip_id
        FROM admin AS a JOIN domain AS d ON d.domain_admin_id = a.admin_id
        WHERE a.admin_type = 'user' AND d.domain_status = 'ok'" . ($login === null ? '' : ' AND a.admin_name = ?') . "
        ORDER BY a.admin_id LIMIT 1
    ",
    $login === null ? array() : array($login)
);

if ($account === null) {
    die2('no customer with a settled domain on this box' . ($login === null ? '' : ' named ' . $login));
}

foreach (array('apache2', 'proftpd', 'dovecot', 'postfix') as $service) {
    if (trim((string)shell_exec('systemctl is-active ' . escapeshellarg($service))) !== 'active') {
        die2($service . ' is not active; start it before running this (see docs/DEVELOPMENT.md)');
    }
}

$identity = new Identity(
    (int)$account['admin_id'], (string)$account['admin_name'], 'user',
    $account['created_by'] === null ? null : (int)$account['created_by'], $account['email'], array(), null
);
$domain = (string)$account['domain_name'];
$domainId = GlobalId::encode(NodeType::DOMAIN, (int)$account['domain_id']);
$mailRoot = (string)(new \iMSCP\Config\FileConfig(
    \iMSCP\Registry::get('config')['CONF_DIR'] . '/postfix/postfix.data'
))['MTA_VIRTUAL_MAIL_DIR'];
$panelConfig = (array)\iMSCP\Registry::get('config');
$webRoot = isset($panelConfig['USER_WEB_DIR']) ? (string)$panelConfig['USER_WEB_DIR'] : '/var/www/virtual';

printf("Customer %s (%s)\n\n", $account['admin_name'], $domain);

// ---- an administrator, for the customer lifecycle below -------------------
//
// customerCreate/customerDelete are reseller-or-administrator-only (spec
// section 8.1's role refusal), so the primary customer's own identity above
// cannot run them. An administrator that also names the reseller explicitly
// works regardless of which reseller (if any) owns the box's other
// customers, which running as the reseller itself would not.
$adminRow = $db->row("SELECT admin_id, admin_name, email FROM admin WHERE admin_type = 'admin' ORDER BY admin_id LIMIT 1");

if ($adminRow === null) {
    die2('no administrator account on this box');
}

if ($account['created_by'] === null) {
    die2('customer ' . $account['admin_name'] . ' has no reseller (admin.created_by is null); '
        . 'the customer-lifecycle step needs one to create a throwaway customer under');
}

$adminIdentity = new Identity(
    (int)$adminRow['admin_id'], (string)$adminRow['admin_name'], 'admin', null, $adminRow['email'], array(), null
);
$resellerGlobalId = GlobalId::encode(NodeType::RESELLER, (int)$account['created_by']);
$ipGlobalId = GlobalId::encode(NodeType::IP_ADDRESS, (int)$account['domain_ip_id']);
$throwawayUsername = PREFIX;
$throwawayDomain = PREFIX . '.test';

// ---- the API, as a request would build it ---------------------------------

function run(string $document, array $variables = array(), ?Identity $as = null): array
{
    global $db, $identity;

    // A fresh container per document, as production builds one per request.
    $schema = Container::forTesting(
        dirname(__DIR__, 2), array(),
        static function (string $sql, array $bind = array()) { return null; },
        static function (int $adminId) { return null; },
        static function (int $adminId) { return true; },
        $db, (array)\iMSCP\Registry::get('config'),
        new PanelCore(false), new VfsDirectoryProbe(), MariaDbSqlServer::fromPanel($db)
    )->schemaFactory()->create();

    $result = GraphQL::executeQuery($schema, $document, null, array('identity' => $as ?? $identity), $variables);
    $result->setErrorFormatter(static function (Error $error) {
        return $error->getPrevious() instanceof Throwable
            ? ErrorFactory::format($error->getPrevious(), true)
            : array('message' => $error->getMessage());
    });

    return $result->toArray();
}

function backend(): void
{
    exec(REQUEST_MANAGER . ' 2>&1', $output, $status);

    if ($status !== 0) {
        printf("  note  the request manager exited %d: %s\n", $status, implode(' / ', array_slice($output, -3)));
    }
}

/**
 * Run the backend until nothing of the customer's is pending, or give up.
 *
 * @return array<int, array> what was still pending
 */
function settle(): array
{
    $deadline = time() + SETTLE_SECONDS;

    do {
        backend();
        $pending = run('{ pending { id __typename ... on Provisioned { provisioning { state raw } } } }');

        if (isset($pending['errors']) || !array_key_exists('pending', (array)($pending['data'] ?? array()))) {
            die2('the pending query failed: ' . json_encode($pending['errors'] ?? $pending));
        }

        $items = $pending['data']['pending'];

        if ($items === array()) {
            return array();
        }

        sleep(2);
    } while (time() < $deadline);

    return $items;
}

function state(string $id): ?array
{
    $result = run('query($id: ID!) { node(id: $id) { ... on Provisioned { provisioning { state message } } } }', array('id' => $id));

    return $result['data']['node']['provisioning'] ?? null;
}

function counts(): array
{
    global $db, $account;

    $domainId = (int)$account['domain_id'];

    return array(
        'subdomains' => (int)$db->value('SELECT COUNT(*) FROM subdomain WHERE domain_id = ?', array($domainId)),
        'mail'       => (int)$db->value('SELECT COUNT(*) FROM mail_users WHERE domain_id = ?', array($domainId)),
        'ftp'        => (int)$db->value('SELECT COUNT(*) FROM ftp_users WHERE admin_id = ?', array((int)$account['admin_id'])),
        'sqlDbs'     => (int)$db->value('SELECT COUNT(*) FROM sql_database WHERE domain_id = ?', array($domainId)),
        'sqlUsers'   => (int)$db->value('SELECT COUNT(*) FROM sql_user AS u JOIN sql_database AS d USING (sqld_id) WHERE d.domain_id = ?', array($domainId)),
        'dns'        => (int)$db->value('SELECT COUNT(*) FROM domain_dns WHERE domain_id = ?', array($domainId))
    );
}

/**
 * Wait until node(id) is null for every id in $ids, using identity $as,
 * running the backend between polls.
 *
 * @param array<int, string> $ids
 * @return array<int, string> the ids still present when the deadline passed
 */
function waitForGone(Identity $as, array $ids): array
{
    $deadline = time() + SETTLE_SECONDS;
    $remaining = $ids;

    do {
        backend();
        $remaining = array_values(array_filter($remaining, static function (string $id) use ($as): bool {
            $result = run('query($id: ID!) { node(id: $id) { __typename } }', array('id' => $id), $as);

            return ($result['data']['node'] ?? null) !== null;
        }));

        if ($remaining === array()) {
            return array();
        }

        sleep(2);
    } while (time() < $deadline);

    return $remaining;
}

/**
 * Wait until $id's provisioning.state is $want, using identity $as, running
 * the backend between polls.
 *
 * @return array|null the last-seen provisioning, or null on success
 */
function waitForState(Identity $as, string $id, string $want): ?array
{
    $deadline = time() + SETTLE_SECONDS;
    $provisioning = null;

    do {
        backend();
        $result = run(
            'query($id: ID!) { node(id: $id) { ... on Provisioned { provisioning { state message } } } }',
            array('id' => $id), $as
        );
        $provisioning = $result['data']['node']['provisioning'] ?? null;

        if (($provisioning['state'] ?? null) === $want) {
            return null;
        }

        sleep(2);
    } while (time() < $deadline);

    return $provisioning;
}

/** Delete anything a previous, interrupted run left behind. */
function sweep(): void
{
    global $db, $account, $adminIdentity;

    // A whole customer left behind by an interrupted customer-lifecycle run
    // (see below), not one of the per-object leftovers further down: deleting
    // the Customer node removes its admin row and its domain row together, so
    // one mutation covers both new shapes rather than two separate sweeps,
    // and it needs the administrator's identity, not the primary test
    // customer's - customerDelete is reseller-or-administrator-only.
    $strayCustomers = $db->rows(
        "SELECT admin_id AS k, admin_status AS s FROM admin WHERE admin_type = 'user' AND admin_name LIKE 'sgwe2e%'"
    );

    foreach ($strayCustomers as $row) {
        $id = GlobalId::encode(NodeType::CUSTOMER, (int)$row['k']);

        // A stray already marked `todelete` is a run that asked for the
        // delete and died before the backend ran. It must not be skipped:
        // nothing else here runs the backend over it, so the next run would
        // find it in the same state, and the lifecycle below would then call
        // customerCreate with the username this row still holds, be refused
        // as a duplicate, and die - for ever. Only the mutation is skipped
        // (it is already asked for, and customerDelete refuses an unsettled
        // account anyway); the wait, which runs the backend between polls,
        // is what this loop is actually for.
        if ($row['s'] === 'todelete') {
            printf("  already todelete, driving the backend over %s\n", $row['k']);
        } else {
            $result = run('mutation($id: ID!) { customerDelete(id: $id) { id } }', array('id' => $id), $adminIdentity);

            if (isset($result['errors'][0])) {
                die2(sprintf(
                    'SWEEP FAILED customerDelete %s: %s (%s)',
                    $row['k'], $result['errors'][0]['message'],
                    $result['errors'][0]['extensions']['code'] ?? 'no code'
                ));
            }

            printf("  swept customerDelete %s\n", $row['k']);
        }

        $stuck = waitForGone($adminIdentity, array($id));

        if ($stuck !== array()) {
            die2('SWEEP FAILED: a swept customer did not settle within ' . SETTLE_SECONDS . 's');
        }
    }

    $domainId = (int)$account['domain_id'];
    $leftovers = array(
        array('subdomainDelete', NodeType::SUBDOMAIN, $db->rows("SELECT subdomain_id AS k, subdomain_status AS s FROM subdomain WHERE domain_id = ? AND subdomain_name LIKE 'sgwe2e%'", array($domainId))),
        array('mailAccountDelete', NodeType::MAIL_ACCOUNT, $db->rows("SELECT mail_id AS k, status AS s FROM mail_users WHERE domain_id = ? AND mail_acc LIKE 'sgwe2e%'", array($domainId))),
        array('ftpUserDelete', NodeType::FTP_USER, $db->rows("SELECT userid AS k, status AS s FROM ftp_users WHERE admin_id = ? AND userid LIKE 'sgwe2e%'", array((int)$account['admin_id']))),
        // MYSQL_PREFIX ('infront'/'behind') puts the domain id before or after PREFIX
        // (SqlService::prefixed()), so the name is only ever a substring of what we
        // asked for. The '%' on both sides is what makes a prefixed or suffixed name
        // still match here; it stays selective because every query is also scoped to
        // this domain_id.
        array('sqlDatabaseDelete', NodeType::SQL_DATABASE, $db->rows("SELECT sqld_id AS k, 'ok' AS s FROM sql_database WHERE domain_id = ? AND sqld_name LIKE '%sgwe2e%'", array($domainId))),
        array('dnsRecordDelete', NodeType::DNS_RECORD, $db->rows("SELECT domain_dns_id AS k, domain_dns_status AS s FROM domain_dns WHERE domain_id = ? AND domain_dns LIKE 'sgwe2e%'", array($domainId)))
    );

    foreach ($leftovers as list($mutation, $tag, $rows)) {
        foreach ($rows as $row) {
            if ($row['s'] === 'todelete') {
                continue;
            }

            $id = NodeType::isStringKeyed($tag) ? GlobalId::encodeKey($tag, (string)$row['k']) : GlobalId::encode($tag, (int)$row['k']);
            $result = run('mutation($id: ID!) { ' . $mutation . '(id: $id) { id } }', array('id' => $id));

            if (isset($result['errors'][0])) {
                die2(sprintf(
                    'SWEEP FAILED %s %s: %s (%s)',
                    $mutation, $row['k'], $result['errors'][0]['message'],
                    $result['errors'][0]['extensions']['code'] ?? 'no code'
                ));
            }

            printf("  swept %s %s\n", $mutation, $row['k']);
        }
    }

    settle();
}

// ---- the run ---------------------------------------------------------------

echo "Sweep:\n";
sweep();

echo "\nCustomer lifecycle:\n";

$customerResult = run(
    'mutation($input: CustomerCreateInput!) {
        customerCreate(input: $input) {
            id
            provisioning { state }
            domain { id provisioning { state } }
        }
    }',
    array('input' => array(
        'username'    => $throwawayUsername,
        'password'    => 'E2e0Password',
        'domainName'  => $throwawayDomain,
        'ipAddressId' => $ipGlobalId,
        'resellerId'  => $resellerGlobalId,
        'contact'     => array('email' => $throwawayUsername . '@example.test'),
        'allowances'  => array(
            'subdomains' => 1, 'domainAliases' => 0, 'mailAccounts' => 1, 'ftpUsers' => 1,
            'sqlDatabases' => 0, 'sqlUsers' => 0, 'traffic' => 1024 * 1048576, 'disk' => 512 * 1048576,
            'mailQuota' => 104857600, 'php' => true, 'cgi' => false, 'customDns' => false,
            'externalMail' => false, 'backup' => array(), 'phpEditor' => false
        ),
        'sendWelcomeEmail' => false
    )),
    $adminIdentity
);
check(
    'customerCreate returns PENDING',
    ($customerResult['data']['customerCreate']['provisioning']['state'] ?? null) === 'PENDING',
    json_encode($customerResult['errors'] ?? null)
);
$throwawayCustomerId = $customerResult['data']['customerCreate']['id'] ?? null;
$throwawayDomainId = $customerResult['data']['customerCreate']['domain']['id'] ?? null;

if ($throwawayCustomerId === null || $throwawayDomainId === null) {
    die2('customerCreate did not return an id to follow: ' . json_encode($customerResult));
}

$stuck = waitForState($adminIdentity, $throwawayCustomerId, 'OK');
check('the new customer reached OK within ' . SETTLE_SECONDS . 's', $stuck === null, json_encode($stuck));
$stuck = waitForState($adminIdentity, $throwawayDomainId, 'OK');
check('the new customer\'s domain reached OK within ' . SETTLE_SECONDS . 's', $stuck === null, json_encode($stuck));

$throwawayAdminRow = $db->row('SELECT admin_status FROM admin WHERE admin_name = ?', array($throwawayUsername));
check('the account exists in admin, settled', ($throwawayAdminRow['admin_status'] ?? null) === 'ok');
$throwawayDomainRow = $db->row('SELECT domain_status FROM domain WHERE domain_name = ?', array($throwawayDomain));
check('the domain exists in domain, settled', ($throwawayDomainRow['domain_status'] ?? null) === 'ok');
check('the web directory exists', is_dir($webRoot . '/' . $throwawayDomain . '/htdocs'));

$deleteResult = run(
    'mutation($id: ID!) { customerDelete(id: $id) { id } }', array('id' => $throwawayCustomerId), $adminIdentity
);
check('customerDelete accepted', !isset($deleteResult['errors']), json_encode($deleteResult['errors'] ?? null));

$stuck = waitForGone($adminIdentity, array($throwawayCustomerId));
check('the deleted customer settled within ' . SETTLE_SECONDS . 's', $stuck === array(), json_encode($stuck));

check(
    'the account is gone from admin',
    $db->row('SELECT 1 FROM admin WHERE admin_name = ?', array($throwawayUsername)) === null
);
check(
    'the domain is gone from domain',
    $db->row('SELECT 1 FROM domain WHERE domain_name = ?', array($throwawayDomain)) === null
);
check('the web directory is gone', !is_dir($webRoot . '/' . $throwawayDomain));

$before = counts();
$created = array();

echo "\nCreate:\n";

if ((int)$account['domain_subd_limit'] >= 0) {
    $result = run(
        'mutation($input: SubdomainCreateInput!) { subdomainCreate(input: $input) { id name provisioning { state } } }',
        array('input' => array('parentId' => $domainId, 'label' => PREFIX))
    );
    check('subdomainCreate returns PENDING', ($result['data']['subdomainCreate']['provisioning']['state'] ?? null) === 'PENDING', json_encode($result['errors'] ?? null));
    $created['subdomain'] = $result['data']['subdomainCreate']['id'] ?? null;
} else {
    echo "  skip  subdomains are withheld from this customer\n";
}

if ((int)$account['domain_mailacc_limit'] >= 0) {
    $result = run(
        'mutation($input: MailAccountCreateInput!) { mailAccountCreate(input: $input) { id provisioning { state } } }',
        array('input' => array(
            'hostId' => $domainId, 'localPart' => PREFIX, 'kind' => 'MAILBOX', 'password' => 'E2e0Password',
            'quota' => (int)$account['mail_quota'] > 0 ? '10485760' : null
        ))
    );
    check('mailAccountCreate returns PENDING', ($result['data']['mailAccountCreate']['provisioning']['state'] ?? null) === 'PENDING', json_encode($result['errors'] ?? null));
    $created['mail'] = $result['data']['mailAccountCreate']['id'] ?? null;
} else {
    echo "  skip  mail is withheld from this customer\n";
}

if ((int)$account['domain_ftpacc_limit'] >= 0) {
    // directory '/htdocs' exists for any provisioned customer, and takes the
    // real VFS probe - the only place in the plan it runs.
    $result = run(
        'mutation($input: FtpUserCreateInput!) { ftpUserCreate(input: $input) { id homeDirectory provisioning { state } } }',
        array('input' => array('hostId' => $domainId, 'username' => PREFIX, 'password' => 'E2e0Password', 'directory' => '/htdocs'))
    );
    check('ftpUserCreate passes the VFS directory check and returns PENDING', ($result['data']['ftpUserCreate']['provisioning']['state'] ?? null) === 'PENDING', json_encode($result['errors'] ?? null));
    $created['ftp'] = $result['data']['ftpUserCreate']['id'] ?? null;
} else {
    echo "  skip  FTP is withheld from this customer\n";
}

// MYSQL_PREFIX may make the panel rename what we asked for (SqlService::prefixed());
// read back the names the server actually gave these objects and use those
// everywhere below, rather than rebuilding them from PREFIX.
$sqlDbName = PREFIX . '_db';
$sqlUserName = PREFIX . '_u';
$sqlUserHost = 'localhost';

if ((int)$account['domain_sqld_limit'] >= 0 && (int)$account['domain_sqlu_limit'] >= 0) {
    $result = run(
        'mutation($input: SqlDatabaseCreateInput!) { sqlDatabaseCreate(input: $input) { id name } }',
        array('input' => array('domainId' => $domainId, 'name' => PREFIX . '_db'))
    );
    $created['sqlDatabase'] = $result['data']['sqlDatabaseCreate']['id'] ?? null;
    $sqlDbName = $result['data']['sqlDatabaseCreate']['name'] ?? $sqlDbName;
    check('sqlDatabaseCreate creates the database at once',
        (int)$db->value('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?', array($sqlDbName)) === 1,
        json_encode($result['errors'] ?? null));

    $result = run(
        'mutation($input: SqlUserCreateInput!) { sqlUserCreate(input: $input) { id name host } }',
        array('input' => array('databaseId' => $created['sqlDatabase'], 'name' => PREFIX . '_u', 'host' => 'localhost', 'password' => 'E2e0Password'))
    );
    $created['sqlUser'] = $result['data']['sqlUserCreate']['id'] ?? null;
    $sqlUserName = $result['data']['sqlUserCreate']['name'] ?? $sqlUserName;
    $sqlUserHost = $result['data']['sqlUserCreate']['host'] ?? $sqlUserHost;

    try {
        $pdo = new PDO('mysql:unix_socket=' . $db->value('SELECT @@socket'), $sqlUserName, 'E2e0Password');
        check('the SQL user can log in and see its database', in_array($sqlDbName, $pdo->query('SHOW DATABASES')->fetchAll(PDO::FETCH_COLUMN), true));
    } catch (PDOException $e) {
        check('the SQL user can log in and see its database', false, $e->getMessage() . ' ' . json_encode($result['errors'] ?? null));
    }
} else {
    echo "  skip  SQL is withheld from this customer\n";
}

if ($account['domain_dns'] !== 'no') {
    $result = run(
        'mutation($input: DnsRecordCreateInput!) { dnsRecordCreate(input: $input) { id provisioning { state } } }',
        array('input' => array('hostId' => $domainId, 'name' => PREFIX, 'type' => 'TXT', 'data' => array('text' => 'sgw end to end')))
    );
    check('dnsRecordCreate returns PENDING', ($result['data']['dnsRecordCreate']['provisioning']['state'] ?? null) === 'PENDING', json_encode($result['errors'] ?? null));
    $created['dns'] = $result['data']['dnsRecordCreate']['id'] ?? null;
} else {
    echo "  skip  custom DNS is withheld from this customer (measurement M22: it is on cust1.test)\n";
}

echo "\nSettle:\n";
$stuck = settle();
check('the backend settled everything within ' . SETTLE_SECONDS . 's', $stuck === array(), json_encode($stuck));

foreach ($created as $what => $id) {
    if ($id === null || in_array($what, array('sqlDatabase', 'sqlUser'), true)) {
        continue;
    }

    $state = state($id);
    check($what . ' reached OK', ($state['state'] ?? null) === 'OK', json_encode($state));
}

if (isset($created['subdomain'])) {
    check('the subdomain has a vhost file', is_file(VHOST_DIR . '/' . PREFIX . '.' . $domain . '.conf'));
}

if (isset($created['mail'])) {
    check('the mailbox has a maildir', is_dir($mailRoot . '/' . $domain . '/' . PREFIX));
}

if (isset($created['ftp'])) {
    check('the FTP user is in the customer\'s group', strpos((string)$db->value('SELECT members FROM ftp_group WHERE groupname = ?', array($account['admin_name'])), PREFIX . '@' . $domain) !== false);
}

echo "\nDelete:\n";

foreach (array('dns' => 'dnsRecordDelete', 'sqlUser' => 'sqlUserDelete', 'sqlDatabase' => 'sqlDatabaseDelete', 'ftp' => 'ftpUserDelete', 'mail' => 'mailAccountDelete', 'subdomain' => 'subdomainDelete') as $what => $mutation) {
    if (!isset($created[$what])) {
        continue;
    }

    $result = run('mutation($id: ID!) { ' . $mutation . '(id: $id) { id } }', array('id' => $created[$what]));
    check($mutation . ' accepted', !isset($result['errors']), json_encode($result['errors'] ?? null));
}

$stuck = settle();
check('the backend settled every deletion within ' . SETTLE_SECONDS . 's', $stuck === array(), json_encode($stuck));

check('the database is back where it started', counts() === $before, json_encode(array('before' => $before, 'after' => counts())));
check('no vhost file is left', !is_file(VHOST_DIR . '/' . PREFIX . '.' . $domain . '.conf'));
check('no maildir is left', !is_dir($mailRoot . '/' . $domain . '/' . PREFIX));
check('no SQL database is left', (int)$db->value('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?', array($sqlDbName)) === 0);
check('no SQL user is left', (int)$db->value("SELECT COUNT(*) FROM mysql.user WHERE User = ? AND Host = ?", array($sqlUserName, $sqlUserHost)) === 0);

printf("\n%d passed, %d failed\n", $passed, $failed);
exit($failed === 0 ? 0 : 1);
