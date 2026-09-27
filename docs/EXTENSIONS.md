# Extending the API from another plugin

This plugin serves what i-MSCP itself knows about. Other plugins know more:
a domain's PHP version, whether its site is cached, whether it has a Let's
Encrypt certificate. The extension hooks let such a plugin add its own fields
and mutations to this API.

**This plugin knows nothing about any other plugin.** The other plugin opts
in. It listens for an event, registers an extension, and writes its own SDL,
resolvers and authorisation rules. This plugin checks that the extension fits,
builds it into the schema, and hands its resolvers the same database handle,
batch loader and ownership rule that this plugin's own resolvers use.

## The shape of it

```
another plugin's register()          this plugin, on each API request
───────────────────────────          ─────────────────────────────────
listens for                    ◀──   dispatches onGraphQLRegisterExtensions
'onGraphQLRegisterExtensions'          with an ExtensionRegistry
  └─ registry->register(new X)   ──▶ ExtensionLoader checks each extension
                                     builds the schema: core SDL + X's SDL
                                     X's resolvers get an ExtensionContext
```

| Class (`iMSCP\Plugin\SGW_GraphQL\Extension\…`) | What it is |
| --- | --- |
| `Extension` | The interface an extension implements: `getName()`, `getSdl()`, `getResolvers(ExtensionContext)`, `getComplexity()`. |
| `ExtensionRegistry` | Where extensions are registered. It is passed to the listener as the event's `registry` parameter. |
| `ExtensionContext` | What resolvers work with: `db()`, `core()`, `loader()`, `identity()`, `requireScope()`, `virtualHost()`, `targetVirtualHost()`, `virtualHostReference()`, `customerReference()`. |
| `VirtualHostRef` | A vhost in the panel's own vocabulary. `getKind()` and `getKey()` are the `(domain_type, domain_id)` pair: `dmn`, `sub`, `als` or `alssub`, and the id in that vhost's own table. |

## Opting in

Two methods on the other plugin's class:

```php
public function register(EventManagerInterface $eventsManager)
{
    $eventsManager->registerListener(
        array(
            Events::onClientScriptStart,
            // ... the plugin's own events ...
            'onGraphQLRegisterExtensions'
        ),
        $this
    );
}

/**
 * Only ever called when SGW_GraphQL is installed and serving a request, so
 * this is the only place the plugin touches a GraphQL class.
 */
public function onGraphQLRegisterExtensions(Event $event)
{
    $event->getParam('registry')->register(new GraphQL\PhpVersionExtension());
}
```

Write the event name as a string, not as `ExtensionRegistry::EVENT`. Then the
plugin's `register()` never loads a class from this plugin, and the plugin
works the same whether or not SGW_GraphQL is installed. The extension class
itself is autoloaded only when the listener runs. The panel's autoloader
already maps `iMSCP\Plugin\` onto the plugins directory, so no `require` is
needed.

## An extension, end to end

The example below adds a domain's PHP version as the PHP version plugin
records it: a field to read it and a mutation to change it. The table and the
helper functions are illustrative. The pattern is the point.

```php
<?php
namespace iMSCP\Plugin\SGW_PhpVersion\GraphQL;

use iMSCP\Plugin\SGW_GraphQL\Auth\Scope;
use iMSCP\Plugin\SGW_GraphQL\Extension\Extension;
use iMSCP\Plugin\SGW_GraphQL\Extension\ExtensionContext;
use iMSCP\Plugin\SGW_GraphQL\Security\Guard;
use iMSCP\Plugin\SGW_GraphQL\Support\Provisioning;

final class PhpVersionExtension implements Extension
{
    public function getName(): string
    {
        return 'SGW_PhpVersion';
    }

    public function getSdl(): string
    {
        return '
            type PhpVersionSetting {
              "The version chosen for this site."
              version: String!
              "The version the web server is running it on now."
              appliedVersion: String
            }

            extend type Domain { phpVersion: PhpVersionSetting }
            extend type Subdomain { phpVersion: PhpVersionSetting }
            extend type DomainAlias { phpVersion: PhpVersionSetting }

            input PhpVersionSetInput {
              "A Domain, Subdomain or DomainAlias."
              id: ID!
              version: String!
            }

            extend type Mutation {
              phpVersionSet(input: PhpVersionSetInput!): VirtualHost!
            }
        ';
    }

    public function getResolvers(ExtensionContext $context): array
    {
        $read = static function ($source, array $args, $ctx) use ($context) {
            // Read gate: the scope of the object the field hangs off.
            $context->requireScope($ctx, Scope::DOMAINS_READ);
            $vhost = $context->virtualHost($source);

            // Batched: fifty domains on one page cost one query, not fifty.
            // Prefix the bucket name with the extension's name.
            return $context->loader()->keyed(
                'SGW_PhpVersion:php_version',
                $vhost->getKind() . ':' . $vhost->getKey(),
                static function (array $keys) use ($context) {
                    $found = array();
                    $where = array();
                    $bind = array();

                    foreach ($keys as $key) {
                        list($kind, $id) = explode(':', $key, 2);
                        $where[] = '(domain_type = ? AND domain_id = ?)';
                        $bind[] = $kind;
                        $bind[] = (int)$id;
                    }

                    foreach ($context->db()->rows(
                        'SELECT domain_type, domain_id, php_version, applied_version
                         FROM php_version WHERE ' . implode(' OR ', $where),
                        $bind
                    ) as $row) {
                        $found[$row['domain_type'] . ':' . $row['domain_id']] = array(
                            'version'        => $row['php_version'],
                            'appliedVersion' => $row['applied_version']
                        );
                    }

                    return $found;
                }
            );
        };

        return array(
            'Domain.phpVersion'      => $read,
            'Subdomain.phpVersion'   => $read,
            'DomainAlias.phpVersion' => $read,

            'Mutation.phpVersionSet' => static function ($source, array $args, $ctx) use ($context) {
                $input = (array)$args['input'];

                // Spec section 8.1, in order: ownership (NOT_FOUND), then
                // scope (FORBIDDEN), then state (CONFLICT), then input.
                $vhost = $context->targetVirtualHost(
                    $context->identity($ctx), $input['id'] ?? null, Scope::DOMAINS_WRITE, 'input.id'
                );
                Guard::requireState((string)$vhost->getStatus(), array(Provisioning::STATE_OK));

                if (!in_array($input['version'], installedVersions(), true)) {
                    throw Guard::badInput('input.version', 'That PHP version is not installed.');
                }

                // The plugin's own write, keyed on the panel's pair.
                setChoice($vhost->getKind(), $vhost->getKey(), $vhost->getOwnerId(), $input['version']);

                // Wake the daemon. Never inside a transaction.
                $context->core()->sendRequest();

                // The object returned must be read after the write
                // (decision D18).
                $context->loader()->reset();

                return $context->virtualHostReference($vhost);
            }
        );
    }

    public function getComplexity(): array
    {
        // No list fields, so nothing to charge beyond the default.
        return array();
    }
}
```

A client then writes:

```graphql
mutation {
  phpVersionSet(input: { id: "RG9tYWluOjE", version: "8.3" }) {
    name
    provisioning { state }
    ... on Domain { phpVersion { version appliedVersion } }
  }
}
```

## What an extension may declare

`ExtensionLoader` enforces these rules on every request. It does not take
them on trust.

* **New object, input and enum types.** A new type may not implement an
  interface. `Node`, `Provisioned` and `VirtualHost` carry this plugin's
  identifier space and type resolution, so an extension's own objects are
  plain values that hang off an existing node.
* **`extend type X { ... }` on any object type**, including `Query` and
  `Mutation`. An extension may not add an interface to an existing type.
* **No scalars, interfaces, unions, directives, or extensions of enums,
  inputs or interfaces.** In particular, `extend enum Scope` is refused. An
  extension reuses the existing scopes (next section).
* **Resolvers and complexity only for fields the extension's own SDL
  declares.** An extension cannot replace a field this plugin serves, or one
  another extension already claimed.

An extension that breaks a rule is **left out**, and the API comes up without
it. The same applies to an extension that throws from any of its methods, or
whose SDL does not build against the schema, for example by extending a type
that does not exist or adding a field that already exists. The panel's log
(*System tools → Logs*) records which extension was left out and why, at
warning level, so the administrator is not mailed on every request. When two
extensions clash, the one registered later is dropped.

A plugin whose listener throws takes nothing down either. Whatever registered
before it is kept, and the failure is logged.

## Authorisation

An extension is trusted code, just like the plugin that ships it. The context
makes the right thing the easy thing:

* **Reads.** Call `requireScope($ctx, Scope::…)` in every resolver, using the
  scope of the object the field hangs off: `DOMAINS_READ` for a field on a
  vhost. The object itself was already reached by the query that led to it,
  so no second ownership check is needed.
* **Writes.** Resolve every identifier the caller sends through
  `targetVirtualHost()`. It runs the same two checks as this plugin's own
  mutations, in the same order: NOT_FOUND for anything the caller cannot
  reach, then FORBIDDEN for a credential without the scope. The answer
  therefore never confirms that something exists. Customers reach their own
  vhosts, resellers reach their customers' vhosts, and the administrator
  reaches every vhost.
* **Feature gates.** A per-customer permission is the extension's own rule.
  Enforce it with `Guard::requireFeature($allowed, 'phpVersion')`, asked of
  `$vhost->getOwnerId()` rather than of the caller.
* **Errors.** Throw the `ApiException`s that `Security\Guard` builds:
  `badInput()`, `forbidden()`, `conflict()`, `requireState()`,
  `requireFeature()`, `limitExceeded()`. Clients already handle those codes.
  Any other exception becomes `INTERNAL`. The client gets a correlation id,
  the detail goes to the panel's log, and the message is shown to the client
  only when `debug` is on.

## What comes for free

The endpoint's other behaviour applies to an extension's fields exactly as it
applies to the core's:

* TLS, CORS, authentication and API-access withdrawal.
* Both rate-limit buckets. An extension's mutation counts against
  `rate_limit_mutations`.
* Depth and complexity limits. Declare a complexity for any list field (see
  `Extension::getComplexity()`).
* The audit trail, with `Secret`-typed arguments redacted.
* The browser explorer and introspection.

## The schema a client sees

* **`GET /api/graphql/schema`** serves the effective SDL: this plugin's
  schema, followed by each extension that was kept, under an
  `# Extension: <name>` comment. Generate client code from this route when you
  use an extension's fields.
* **`schema/schema.printed.graphql`** is this plugin's own contract and
  nothing more. It does not change when another plugin is installed.
* **`apiVersion`** versions this plugin's schema. An extension's fields are
  versioned by the plugin that ships them.

## Testing an extension

`Container::forTesting()` takes an `ExtensionRegistry` as its last argument.
It dispatches no event: a test states the extensions it means.
`test/integration/ExtensionContextTest.php` shows the whole pattern: an
extension shaped like the plugins beside this one, run as the six fixture
accounts. `test/unit/Extension/ExtensionLoaderTest.php` lists each way an
extension can be left out.

To try an extension against a running panel, enable both plugins and query
the schema route. If the extension's fields are not there, the reason is in
the panel's log.
