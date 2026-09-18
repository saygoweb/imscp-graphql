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
            d.domain_sqld_limit, d.domain_sqlu_limit, d.domain_dns, d.mail_quota
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

printf("Customer %s (%s)\n\n", $account['admin_name'], $domain);

// ---- the API, as a request would build it ---------------------------------

function run(string $document, array $variables = array()): array
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

    $result = GraphQL::executeQuery($schema, $document, null, array('identity' => $identity), $variables);
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

/** Delete anything a previous, interrupted run left behind. */
function sweep(): void
{
    global $db, $account;

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
