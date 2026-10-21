<?php
namespace iMSCP\Plugin\SGW_GraphQL\Test\Service;

use GraphQL\Language\Parser;
use GraphQL\Utils\BuildSchema;
use iMSCP\Plugin\SGW_GraphQL\Auth\Identity;
use iMSCP\Plugin\SGW_GraphQL\Service\AuditContext;
use iMSCP\Plugin\SGW_GraphQL\Service\ErrorLog;
use PHPUnit\Framework\TestCase;

final class ErrorLogTest extends TestCase
{
    /** @var string */
    private $dir;

    /** @var string */
    private $path;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/sgw-error-log-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        $this->path = $this->dir . '/sgw_graphql.log';
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: array() as $file) {
            unlink($file);
        }

        rmdir($this->dir);
    }

    private function log(int $maxBytes = ErrorLog::MAX_BYTES): ErrorLog
    {
        $schema = BuildSchema::build('
            type Query { ok: Boolean }
            type Mutation { domainAliasCreate(input: AliasInput!): Boolean }
            input AliasInput { domainId: ID! name: String! password: Secret }
            scalar Secret
        ');

        return new ErrorLog($this->path, $schema, static function () {
            return 1792546963; // 2026-10-21 01:42:43 UTC
        }, $maxBytes);
    }

    private function context(): AuditContext
    {
        $context = new AuditContext('34.212.49.11', microtime(true));
        $context->carries(new Identity(12, 'admin', 'admin', 0, 'a@example.com', array('DOMAINS_WRITE'), 5));

        return $context;
    }

    private function conflict(): array
    {
        return array('errors' => array(array(
            'message'    => 'The backend has not finished with this object yet.',
            'extensions' => array('state' => 'pending', 'retryAfterSeconds' => 5, 'code' => 'CONFLICT'),
            'path'       => array('domainAliasCreate')
        )));
    }

    private function document()
    {
        return Parser::parse('mutation Preview($input: AliasInput!) { domainAliasCreate(input: $input) }');
    }

    public function testAnErrorIsOneLineWithWhoWhatAndWhy(): void
    {
        $this->log()->record(
            $this->document(),
            array('input' => array('domainId' => 'RG9t', 'name' => 'h0001.saygoweb.net', 'password' => 'hunter2')),
            $this->conflict(),
            $this->context()
        );

        $lines = file($this->path, FILE_IGNORE_NEW_LINES);

        self::assertCount(1, $lines);
        self::assertStringStartsWith('[' . gmdate('Y-m-d H:i:s', 1792546963) . '] [CONFLICT] ', $lines[0]);

        foreach (array(
            'admin=12', 'token=5', 'via=http', 'ip=34.212.49.11',
            'operation=Preview', 'fields=domainAliasCreate', 'path=domainAliasCreate',
            'The backend has not finished with this object yet.',
            '"retryAfterSeconds":5', 'h0001.saygoweb.net'
        ) as $expected) {
            self::assertStringContainsString($expected, $lines[0]);
        }
    }

    public function testASecretNeverReachesTheLog(): void
    {
        $this->log()->record(
            $this->document(),
            array('input' => array('domainId' => 'RG9t', 'name' => 'x.test', 'password' => 'hunter2')),
            $this->conflict(),
            $this->context()
        );

        self::assertStringNotContainsString('hunter2', (string)file_get_contents($this->path));
    }

    public function testEachErrorInTheEnvelopeIsALine(): void
    {
        $result = $this->conflict();
        $result['errors'][] = array('message' => 'Nope.', 'extensions' => array('code' => 'FORBIDDEN'));

        $this->log()->record($this->document(), array(), $result, $this->context());

        $lines = file($this->path, FILE_IGNORE_NEW_LINES);

        self::assertCount(2, $lines);
        self::assertStringContainsString('[FORBIDDEN]', $lines[1]);
    }

    public function testASuccessWritesNothing(): void
    {
        $this->log()->record($this->document(), array(), array('data' => array('ok' => true)), $this->context());

        self::assertFileDoesNotExist($this->path);
    }

    public function testADocumentThatDidNotParseIsStillLogged(): void
    {
        $this->log()->record(null, array('x' => 1), array('errors' => array(array(
            'message' => 'Syntax Error', 'extensions' => array('code' => 'BAD_USER_INPUT')
        ))), $this->context());

        self::assertStringContainsString('[BAD_USER_INPUT]', (string)file_get_contents($this->path));
    }

    public function testAFullLogIsRotatedToOneOlderFile(): void
    {
        file_put_contents($this->path, str_repeat('x', 100) . "\n");

        $this->log(50)->record($this->document(), array(), $this->conflict(), $this->context());

        self::assertFileExists($this->path . '.1');
        self::assertStringContainsString('[CONFLICT]', (string)file_get_contents($this->path));
        self::assertStringNotContainsString('xxxx', (string)file_get_contents($this->path));
    }

    public function testAnUnwritableLogNeverFailsTheRequest(): void
    {
        $log = new ErrorLog($this->dir . '/missing/dir/sgw_graphql.log', BuildSchema::build('type Query { ok: Boolean }'),
            static function () {
                return 0;
            });

        // error_log() would print in the test runner; send it nowhere.
        $previous = ini_set('error_log', $this->dir . '/php-errors.log');

        try {
            $log->record(null, array(), $this->conflict(), $this->context());
        } finally {
            ini_set('error_log', (string)$previous);
        }

        self::assertFileDoesNotExist($this->dir . '/missing/dir/sgw_graphql.log');
    }
}
