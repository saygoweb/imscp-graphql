# Development environment

The plugin is developed against an i-MSCP server that the i-MSCP repository
brings up for you. There are two, and **docker is the one to use**: it is
faster, it costs a tenth of the disk, and — the reason that matters here — it
bind mounts this checkout into the panel, so the tests run against the working
tree rather than a copy of it. The Vagrant boxes still work and are documented
below for when a real virtual machine is the point.

Either server ships the panel on **PHP 7.3.33** from `packages.sury.org`; the
plugin targets **7.4**, so moving the panel is a prerequisite. It needs no code
changes — measured, 21 of 21 pages render unmodified — only `PHP_FPM_BIN_PATH`
in `configs/debian/default/frontend/frontend.data.dist`, the version gate in
`engine/PerlLib/iMSCP/Requirements.pm`, and the `php`/`phar` alternatives in the
autoinstaller package lists. See
[SPECIFICATION.md §2.5](SPECIFICATION.md#25-the-frontend-runs-on-php-73-today-and-moves-to-74-first).

Plugin source must lint under **both** `php7.4` and `php8.3`, so that the later
8.3 migration costs this plugin nothing. `test/lint/` enforces it.

## Bringing the server up — docker

The stack lives in the i-MSCP repository, not this one. `../imscp/docker/README.md`
is its full documentation; the short version is:

```shell
cd ../imscp
docker/imscp init          # pick host ports that are free on this machine
docker/imscp up --build    # build, boot, install i-MSCP  (20-40 min first time)
docker/imscp info          # where it is and how to log in
```

It needs Docker Engine with the `compose` plugin and a cgroup v2 host.

### Mounting this checkout

The directory holding the sibling plugin checkouts — by default the one the
i-MSCP repository sits in — is mounted whole at `/var/www/imscp-plugins`, so
this checkout is already *visible* inside the container. Making the panel serve
it takes one line in `../imscp/docker/.env`:

```shell
IMSCP_PLUGINS="imscp-php-version imscp-letsencrypt imscp-graphql"
```

and then `docker/imscp link`, which symlinks each named checkout into
`gui/plugins/` under the name its `makefile.json` gives it — so this one arrives
as `/var/www/imscp/gui/plugins/SGW_GraphQL`. Adding a plugin costs a symlink,
not a container rebuild. Install and enable it in the panel as usual, under
*System tools → Plugin management*.

Editing a file on the host changes what the panel serves on the next request.
There is nothing to deploy: `tools/deploy.sh` notices the link and only restarts
`imscp_panel`, so that opcache stops serving the previous bytecode.

Check it is healthy:

```shell
docker/imscp exec sh -c 'php7.4 -v | head -1; systemctl is-active imscp_panel nginx mariadb'
```

The panel is at `http://localhost:8880` (`docker/imscp info` prints the
credentials). Note **`http`**: the container installs with SSL off, because a
self-signed certificate on localhost only adds a click-through. The plugin
refuses plaintext by default, so reaching the endpoint on this server needs
`require_tls => false` in the plugin's configuration.

## Bringing the server up — Vagrant

The boxes live in the i-MSCP repository too:

```shell
cd ../imscp/Vagrant
cp ../docs/preseed.pl .          # first time only; fill in ADMIN_PASSWORD,
                                 # DEFAULT_ADMIN_ADDRESS and SERVER_HOSTNAME
vagrant up imscp_debian_trixie --provider=libvirt
```

Host requirements are in `../imscp/Vagrant/README.md`: libvirt/KVM, the
`vagrant-libvirt` and `vagrant-reload` plugins, and `rsync`.

Check it is healthy:

```shell
vagrant ssh imscp_debian_trixie -c \
  'php -v | head -1; systemctl is-active imscp_panel nginx mariadb'
```

On the reference box that reports PHP 7.3.33 and three `active` lines. The
panel is at `https://panel.<your-hostname>:8443`, and the box's address comes
from `vagrant ssh-config imscp_debian_trixie`.

## Deploying the working copy to a Vagrant box

```shell
tools/deploy.sh                        # from the host
tools/deploy.sh imscp_debian_bookworm  # or another box
```

Then in the panel: *System tools / Plugin management*, **Synchronize**, and
install.

`deploy.sh` does not need this repository to appear in the i-MSCP
`Vagrantfile`. It reads the box's connection details from
`vagrant ssh-config`, rsyncs the tree to a staging directory the `vagrant` user
owns, and re-enters as root to install it. That is deliberate: the sibling
plugin repositories are mounted into the box by a hard-coded list in the
i-MSCP `Vagrantfile`'s Trixie block, which lives on one branch and requires a
`vagrant reload` to change. Pushing over SSH keeps this repository independent
of that.

It still works the sibling way if you prefer it. Add `imscp-graphql` to that
list, `vagrant reload`, and then inside the box:

```shell
sudo /usr/local/src/imscp-graphql/tools/deploy.sh
```

The script detects which side it is on. Either way the tree is *copied* into
`/var/www/imscp/gui/plugins/SGW_GraphQL` rather than mounted there, because a
virtiofs share carries the host's uid and the panel runs as `vu2000`. It
restarts `imscp_panel` afterwards, since that pool's opcache would otherwise
keep serving the previous version of a file.

Set `IMSCP_VAGRANT_DIR` if the i-MSCP repository is not at `../imscp`.

## Dependencies

One runtime dependency — `webonyx/graphql-php ^15` — vendored into the release
archive. Resolve it **against the panel's PHP**, so either install inside the
box or pin the platform on the host:

```json
"config": { "platform": { "php": "7.4.33" } }
```

The panel ships a Composer at `/var/www/imscp/gui/bin/composer.phar`, so inside
the box:

```shell
php7.4 /var/www/imscp/gui/bin/composer.phar install --no-dev
```

`vendor/` is not committed. It is added at packaging time.

## Running the tests

```shell
make.phar test          # lint, unit, schema and integration tests
test/api/smoke.sh       # end-to-end, against a running Vagrant box
```

`make.phar test` runs `tools/test.sh`, which re-enters itself on the server and
runs the lint script under both PHP versions and then the PHPUnit suite. The
server, not the host, because the host has neither PHP 7.4 nor the panel the
integration suite bootstraps.

It picks the server the same way this document does: the docker container if one
is running, otherwise a Vagrant box. On docker it runs the suite against this
working tree through the bind mount — nothing is copied, so there is no staged
second copy to wonder about — while the Vagrant path pushes the tree into
`/tmp/SGW_GraphQL.test` and lets the staged copy test itself. Force one with
`--docker` or `--vagrant [box]`, and set `IMSCP_DIR` if the i-MSCP repository is
not at `../imscp`.

Anything after those flags is passed through to PHPUnit:

```shell
tools/test.sh --testsuite unit
tools/test.sh --filter GlobalId
```

The integration suite skips itself where there is no panel, so the unit suite
stays runnable anywhere PHP 7.4 is.

`test/api/smoke.sh` is different in kind: it drives the *deployed* endpoint
over HTTPS exactly as a real client would, rather than exercising the code in
isolation. It needs the plugin already installed and enabled on a running box
— `tools/deploy.sh` gets you there — plus SSH access to that box (it reads
`vagrant ssh-config` itself) and at least one customer account for it to mint
tokens against. It mints and deletes its own tokens, and restores whatever
`api_perm` row it found beforehand, so a normal run leaves the box exactly as
it found it; run it against a box you don't mind touching regardless, since a
run killed hard enough to skip its cleanup leaves tokens named
`smoke-run-%` behind for the next run to sweep up.

```shell
test/api/smoke.sh                        # against imscp_debian_trixie
test/api/smoke.sh imscp_debian_bookworm  # or another box
```

Set `IMSCP_VAGRANT_DIR` the same way as for `tools/deploy.sh` if the i-MSCP
repository is not at `../imscp`.

**It does not yet run against the docker server**, and needs two things before
it can. The container's panel is PHP 7.3, which this plugin's dependency tree
(resolved against 7.4.33) will not load — that is
[saygoweb/imscp#12](https://github.com/saygoweb/imscp/pull/12). And the container
installs with SSL off, so the run would need `require_tls => false`. Until both
are settled, end-to-end coverage comes from a Vagrant box and everything else
comes from docker.

### Provisioning end to end

`test/api/provision.php` creates a subdomain, a mailbox, an FTP user, a SQL
database and user, and (where the customer has it) a DNS record through the
API's services; runs i-MSCP's request manager over them; checks the vhost file,
the maildir and the SQL login; deletes everything; and checks that the database
and the filesystem are back where they started.

```shell
../imscp/docker/imscp exec systemctl start apache2    # the container does not start it
../imscp/docker/imscp exec sh -c \
  'cd /var/www/imscp-plugins/imscp-graphql && php7.4 test/api/provision.php [customer-login]'
```

It needs a customer with a settled domain (the docker install has `cust1.test`)
and commits real objects, named `sgwe2e*`; a run that dies part way is swept up
by the next. It runs the request manager itself, because the container's
`imscp_daemon` is not running.

The container's `cust1.test` has custom DNS withheld (`domain.domain_dns` is
`no`), so the DNS step skips unless you turn it on for that customer first.

**One thing a run does leave behind, and it is not the plugin's.** Deleting a
subdomain never removes that subdomain's records from its parent's zone, so
each run adds a stale `sgwe2e.<domain>` block to
`/etc/imscp/bind/working/<domain>.db` and the compiled zone beside it.
`Servers::named::bind::addSub()` writes the block between
`; subdomain [<name>] records BEGIN` and `... ENDING` — the markers
`bind/parts/db_sub.tpl` carries — while `deleteSub()` asks `replaceBloc()` to
strip `; sub [<name>] entry BEGIN` / `... ENDING`, which is text that appears in
no zone file, so it removes nothing and recompiles the zone unchanged. The
panel's own `deleteSubdomain()` schedules exactly the same
`subdomain_status = 'todelete'` and nothing else, so the panel leaks the same
records; there is nothing for a mutation to write differently. Clear them by
rebuilding the parent zone:

```shell
../imscp/docker/imscp exec sh -c \
  'mysql --defaults-extra-file=/etc/mysql/conf.d/imscp.cnf -e \
     "UPDATE domain SET domain_status = \"tochange\" WHERE domain_id = 1" imscp
   /var/www/imscp/engine/imscp-rqst-mngr'
```

## Reference plugins

Three sibling repositories are worth reading before writing anything here.
`../imscp-apache-cache` is the most recent and the closest model for layout,
packaging, versioning and the reseller permission pattern.
`../imscp-letsencrypt` shows the same shape a generation earlier.
`../imscp/gui/public/client/` and `../imscp/gui/public/reseller/` are the
specification for what each mutation has to do.
