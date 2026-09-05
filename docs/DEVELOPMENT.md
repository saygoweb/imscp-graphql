# Development environment

The plugin is developed against the Debian 13 (Trixie) Vagrant box in the
i-MSCP repository. The box ships the panel on **PHP 7.3.33** from
`packages.sury.org`; the plugin targets **7.4**, so moving the panel is a
prerequisite. It needs no code changes — measured, 21 of 21 pages render
unmodified — only `PHP_FPM_BIN_PATH` in
`configs/debian/default/frontend/frontend.data.dist` and the version gate in
`engine/PerlLib/iMSCP/Requirements.pm`. See
[SPECIFICATION.md §2.5](SPECIFICATION.md#25-the-frontend-runs-on-php-73-today-and-moves-to-74-first).

Plugin source must lint under **both** `php7.4` and `php8.3`, so that the later
8.3 migration costs this plugin nothing. `test/lint/` enforces it.

## Bringing the box up

The box lives in the i-MSCP repository, not this one:

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

## Deploying the working copy

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

Two runtime dependencies — `webonyx/graphql-php ^15` and `saygoweb/anorm ^3.1`
— vendored into the release archive. Resolve them **against the panel's PHP**,
so either install inside the box or pin the platform on the host:

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
make.phar test          # unit and schema tests, inside the box
test/api/smoke.sh       # integration, against the running box
```

`make.phar test` runs `tools/test.sh`, which pushes the tree over `vagrant ssh`
and runs the lint script and the PHPUnit suite inside the box — see the note
above about why the box, not the host: the host has no PHP 7.4.

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

## Reference plugins

Three sibling repositories are worth reading before writing anything here.
`../imscp-apache-cache` is the most recent and the closest model for layout,
packaging, versioning and the reseller permission pattern.
`../imscp-letsencrypt` shows the same shape a generation earlier.
`../imscp/gui/public/client/` and `../imscp/gui/public/reseller/` are the
specification for what each mutation has to do.
