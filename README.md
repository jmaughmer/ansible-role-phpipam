# ansible-role-phpipam

Ansible role that installs and configures [phpIPAM](https://phpipam.net/) on RHEL 8, 9 and 10 (and compatible: AlmaLinux, Rocky, Oracle Linux), on top of the [`jmaughmer.mariadb`](https://github.com/jmaughmer/ansible-role-mariadb) and [`jmaughmer.apache`](https://github.com/jmaughmer/ansible-role-apache) roles.

Features:

- phpIPAM from the upstream release tarball (default 1.8.3), unpacked into a versioned directory with an atomic symlink switch. Upgrades back up the database, run phpIPAM's upgrade queries from the command line, then switch the symlink. Old releases are pruned.
- MariaDB and the phpIPAM database and user through the MariaDB role (AppStream packages, TLS, STIG settings). The schema is imported on first run and the built-in `Admin` password is set, so there is no web installer step. The installer and upgrader URLs are blocked.
- Apache httpd, mod_ssl and PHP-FPM through the Apache role, with a dedicated `phpipam` PHP-FPM pool and a name-based TLS virtual host. phpIPAM's `.htaccess` rules are built into the vhost (`AllowOverride None`), and internal paths (schema, scripts, uploads, `config.php`) are never served.
- TLS: a self-signed certificate (RSA 3072/SHA-256, renewed before it expires), a certificate you supply (PEM content or files, checked against the key), or the Apache role's certificate.
- Tuning: PHP-FPM pool sizing from host memory, PHP limits for imports and scans, opcache and realpath cache, and MariaDB settings (buffer pool, temporary table sizes, connections) handed to the MariaDB role.
- phpIPAM settings, API applications and `config.php` options managed as variables.
- Kea DHCP integration (see below): an API app for the Kea role's reservation sync, a shared Kea reservation database on the same MariaDB, and phpIPAM's built-in Kea DHCP page.
- Scheduled jobs as systemd timers (ping check, discovery, optional DNS resolve and offline cleanup) and a nightly database backup.
- SELinux labels and booleans, a fapolicyd trust file for the phpIPAM tree, and FIPS-friendly defaults.
- Molecule tests with podman on EL8, EL9 and EL10.

## Requirements

- Ansible core 2.16 or newer. RHEL 8 targets need ansible-core 2.16 on the controller, because 2.17+ no longer supports EL8's Python 3.6, which is where the dnf bindings live.
- Roles and collections from `requirements.yml`: `jmaughmer.mariadb`, `jmaughmer.apache`, `ansible.posix`, `community.general`, `ansible.mysql` (the new home of `community.mysql`'s modules) and `community.crypto`.
- Managed hosts need the AppStream repositories (PHP 8.2 module stream on EL8/9, the default PHP on EL10, which the Apache role selects) and either internet access to GitHub for the release tarball or a copy of it on the controller (`phpipam_archive_src`).

```bash
ansible-galaxy role install -r requirements.yml
ansible-galaxy collection install -r requirements.yml
```

## Quick start

```yaml
- hosts: ipam
  become: true
  roles:
    - role: jmaughmer.phpipam
      vars:
        phpipam_server_name: ipam.example.com
        phpipam_db_password: "{{ vault_phpipam_db_password }}"     # 15+ chars, upper, lower, digit, special
        phpipam_admin_password: "{{ vault_phpipam_admin_password }}"
        phpipam_site_admin_mail: netops@example.com
```

Then browse to `https://ipam.example.com/` and log in as `Admin`. Everything else in the MariaDB and Apache roles (firewall, STIG settings, TLS for MariaDB, crypto policy) is configured with their own `mariadb_*` and `apache_*` variables in your inventory. phpIPAM merges its databases, users, PHP packages and SELinux booleans into those roles' lists instead of replacing them.

## Release and upgrades

| Variable | Default | |
|---|---|---|
| `phpipam_version` | `1.8.3` | Change it to upgrade. |
| `phpipam_archive_url` | GitHub release tarball | Or a mirror. |
| `phpipam_archive_checksum` | `""` | e.g. `sha256:…` |
| `phpipam_archive_src` | `""` | Tarball on the controller, for air-gapped hosts. |
| `phpipam_releases_dir` | `/var/www/phpipam-releases` | One directory per version. |
| `phpipam_web_root` | `/var/www/phpipam` | Symlink to the active release. |
| `phpipam_keep_releases` | `2` | Including the active one. |

On a version change the role unpacks the new release, writes `pre-upgrade-<old version>.sql.gz` into `phpipam_backup_dir` (local database), runs the upgrade through `/usr/local/libexec/phpipam-ansible.php`, and then points the web root at the new release. `config.php` lives in `/etc/phpipam` (root:apache, 0640) and is linked into each release.

Logos uploaded through the UI are stored inside the release, so upload the logo again after an upgrade.

## Database

By default the database is local and phpIPAM connects over the Unix socket as `phpipam@localhost`. For a database on another server, set `phpipam_manage_mariadb: false` and `phpipam_db_host`. TCP connections use TLS (`phpipam_db_tls`, CA in `phpipam_db_tls_ca`), which the MariaDB role requires. Create the database and user on the database server with the MariaDB role:

```yaml
mariadb_databases: [{ name: phpipam, encoding: utf8mb4, collation: utf8mb4_unicode_ci }]
mariadb_users:
  - { name: phpipam, host: "ipam.example.com", password: "{{ vault_phpipam_db_password }}", priv: "phpipam.*:ALL" }
```

The role also sets `httpd_can_network_connect_db` in that case.

## TLS

| `phpipam_tls_source` | |
|---|---|
| `selfsigned` (default) | Key and certificate in `/etc/pki/phpipam/`, SANs from `phpipam_server_name`, `phpipam_server_aliases` and the host's IPv4 address. Renewed `phpipam_tls_selfsigned_renew_days` before expiry. |
| `provided` | `phpipam_tls_cert` / `phpipam_tls_key` / `phpipam_tls_chain` (PEM content, e.g. from a vault) or `phpipam_tls_cert_src` / `phpipam_tls_key_src` / `phpipam_tls_chain_src` (controller files). The role checks the key matches and the certificate is not expired. |
| `apache` | Use the Apache role's certificate (`apache_tls_cert_path`, `apache_tls_key_path`). |

```yaml
phpipam_tls_source: provided
phpipam_tls_cert: "{{ vault_ipam_cert }}"
phpipam_tls_key: "{{ vault_ipam_key }}"
phpipam_tls_chain: "{{ lookup('file', 'files/corp-ca-chain.pem') }}"
```

Protocols and ciphers come from the Apache role (`SSLCipherSuite PROFILE=SYSTEM`), so they follow the system crypto policy. HTTP on port 80 redirects to HTTPS for the phpIPAM name. The phpIPAM vhost is `/etc/httpd/conf.d/zz-phpipam.conf`, loaded after the Apache role's default vhost.

## phpIPAM settings and API

```yaml
phpipam_site_title: Corp IPAM
phpipam_settings:                # columns of phpIPAM's settings table, applied every run
  enableVRF: 1
  enableNAT: 1
  enforceUnique: 1
  inactivityTimeout: 1800
phpipam_api_apps:
  - app_id: ansible
    security: ssl_token          # log in with a phpIPAM user, get a token
    permissions: 2               # 0 disabled, 1 read, 2 read/write, 3 read/write/admin
  - app_id: monitoring
    security: ssl_code           # static token sent in the "token" header
    app_code: "{{ vault_monitoring_token }}"   # 16-32 characters
    permissions: 1
```

The API only accepts HTTPS (`$api_allow_unsafe = false`). Other `config.php` options: `phpipam_session_storage` (database), `phpipam_cookie_samesite` (Strict), `phpipam_trust_proxy_headers`, `phpipam_proxy_*`, `phpipam_offline_mode`, `phpipam_base_path` (serve under `/ipam/`), and `phpipam_config_extra` for raw PHP. `phpipam_admin_password` is set on first install; set `phpipam_admin_password_enforce: true` to reset it whenever it differs.

## Kea DHCP integration

Three pieces, each optional. They line up with [`ansible-role-kea_dhcp`](https://github.com/jmaughmer/ansible-role-kea_dhcp).

**1. API app for reservation sync.** The Kea role's `kea-phpipam-sync` service reads addresses with a MAC from phpIPAM and pushes them to Kea as reservations. It authenticates with a static app code (security `ssl_code`), read-only by default.

```yaml
# phpIPAM host
phpipam_kea_api_app_enabled: true
phpipam_kea_api_app_id: kea
phpipam_kea_api_app_token: "{{ vault_phpipam_kea_token }}"   # 16-32 characters

# Kea host (one node)
kea_phpipam_sync_enabled: true
kea_phpipam_url: https://ipam.example.com
kea_phpipam_app_id: kea
kea_phpipam_token: "{{ vault_phpipam_kea_token }}"
kea_phpipam_subnet_map:
  - { phpipam_subnet_id: 7, kea_subnet_id: 1, family: 4 }
```

If phpIPAM uses a self-signed certificate, copy it to the Kea host's trust store or set `kea_phpipam_verify_tls: false`.

**2. Shared reservation database.** `kea-phpipam-sync` writes through Kea's `host_cmds`, which needs a hosts database so every Kea server sees the same reservations. The role can create it on phpIPAM's MariaDB, with a `kea` user for the Kea servers and read access for phpIPAM:

```yaml
# phpIPAM host
phpipam_kea_hosts_db_enabled: true
phpipam_kea_hosts_db_password: "{{ vault_kea_db_password }}"
phpipam_kea_hosts_db_client_hosts: ["10.0.0.11", "10.0.0.12"]
mariadb_bind_address: 0.0.0.0                 # MariaDB role: listen for the Kea servers
mariadb_firewalld_sources: [10.0.0.11/32, 10.0.0.12/32]

# Kea hosts
kea_hosts_database:
  type: mysql
  name: kea
  host: ipam.example.com
  user: kea
  password: "{{ vault_kea_db_password }}"
  trust-anchor: /etc/pki/tls/certs/ipam-mariadb-ca.crt   # the MariaDB role's CA
kea_db_init: true
```

Remote users get `REQUIRE SSL` from the MariaDB role, so Kea needs `trust-anchor`. `kea-admin` (used by `kea_db_init`) runs the `mysql` client, so on the Kea host also add `[client]` / `ssl-ca=…` to a file in `/etc/my.cnf.d/` for the schema step.

**3. phpIPAM's Kea DHCP page.** phpIPAM can show Kea subnets, leases and reservations (Administration > phpIPAM settings > DHCP, then the DHCP menu). Its Kea adapter reads a Kea-style config file on the phpIPAM host and connects to the databases named in it. The role writes that file from the running Kea configuration (`config-get` through the Control Agent, using the Kea role's `kea_api_users`) or from a static `phpipam_kea_config`:

```yaml
phpipam_kea_dhcp_module_enabled: true
phpipam_kea_config_source: api
phpipam_kea_ctrl_agent_url: http://10.0.0.11:8000/
phpipam_kea_ctrl_agent_user: phpipam
phpipam_kea_ctrl_agent_password: "{{ vault_kea_api_phpipam_password }}"
phpipam_kea_services: [dhcp4, dhcp6]
```

The file keeps only what phpIPAM reads: subnets (shared networks flattened) and the lease and hosts databases. phpIPAM connects to those databases on port 3306 without TLS, so in practice it works with the local reservation database from step 2, which the role puts in the file automatically (`phpipam_kea_hosts_database`). Leases are only visible when Kea keeps them in MySQL (`phpipam_kea_lease_database`); with Kea's default memfile backend the lease page stays empty. The file is refreshed on every run, so re-run the role after changing Kea subnets.

## Scheduled jobs and backups

```yaml
phpipam_jobs:
  - { name: ping-check, script: pingCheck.php, on_calendar: "*:0/15", enabled: true }
  - { name: discovery-check, script: discoveryCheck.php, on_calendar: hourly, enabled: true }
  - { name: resolve-addresses, script: resolveIPaddresses.php, on_calendar: daily, enabled: false }
  - { name: remove-offline, script: remove_offline_addresses.php, on_calendar: daily, enabled: false }
```

Each job is a `phpipam-<name>.service`/`.timer` pair running as `apache`. Enable scanning per subnet in phpIPAM ("Check hosts status", "Discover new hosts"). `phpipam_backup_enabled` (default on, local database) runs a nightly `mysqldump` into `/var/lib/phpipam/backup`, kept `phpipam_backup_keep_days` days.

## Tuning

| Variable | Default | |
|---|---|---|
| `phpipam_fpm_max_children` | memory / 160 MB, 8 to 50 | Dedicated `phpipam` pool on `/run/php-fpm/phpipam.sock`. |
| `phpipam_fpm_request_terminate_timeout` | `600` | Also the Apache `ProxyTimeout`. |
| `phpipam_php_memory_limit` | `512M` | Large subnets and imports. |
| `phpipam_php_max_execution_time` | `600` | Scans and imports from the UI. |
| `phpipam_php_max_input_vars` | `10000` | Big permission and custom field forms. |
| `phpipam_php_upload_max_filesize` / `post_max_size` | `32M` | Also `LimitRequestBody`. |
| `phpipam_php_opcache_*` | 128 MB, 10000 files | `/etc/php.d/90-phpipam.ini`. |
| `phpipam_mariadb_tuning_defaults` | buffer pool 25% of RAM, `tmp_table_size`/`max_heap_table_size` 64M, `max_connections` 2 × FPM children + 50 | Merged with `phpipam_mariadb_tuning` and passed to the MariaDB role; written to `/etc/my.cnf.d/zz-phpipam.cnf` when that role is not managed. |
| `phpipam_db_tmptable_engine` | `MEMORY` | phpIPAM's temporary tables; set `InnoDB` if you lower the temp table sizes. |

Sessions are stored in the database (`phpipam_session_storage`), so nothing depends on `/var/lib/php/session`.

## Security

**SELinux.** The release tree is web content (`httpd_sys_content_t`; also labeled when `phpipam_releases_dir` is outside `/var/www`), the three upload folders are `httpd_sys_rw_content_t`, and everything else is read-only for PHP. Booleans are only switched on when needed: `httpd_can_network_connect_db` for a remote database and `httpd_can_sendmail` when scan mails are on. Add more with `phpipam_selinux_booleans`, e.g. `httpd_can_network_connect: true` for LDAP/RADIUS login or the update check, and `httpd_can_connect_ldap: true` for LDAP only.

**fapolicyd.** fapolicyd's default rules only let interpreters read trusted scripts, and PHP files count. The phpIPAM tarball is not an RPM, so when fapolicyd is present the role writes `/etc/fapolicyd/trust.d/phpipam` with the path, size and SHA-256 of every file under `phpipam_releases_dir`, plus `config.php` and the helper, and loads it before any PHP runs. It is recomputed every run, so upgrades and config changes stay trusted. Files uploaded through the UI are images and CSVs, which fapolicyd does not treat as code.

**FIPS.** phpIPAM hashes passwords with SHA-512 crypt and encrypts API data with AES (OpenSSL), and TLS uses the system OpenSSL with the crypto policy's ciphers, so the role works on hosts in FIPS mode. The self-signed certificate is RSA 3072 with SHA-256. The fapolicyd hashes are SHA-256.

**Other hardening.** The tree is owned by root, `config.php` is 0640 root:apache outside the web root, the web installer and upgrader are disabled and blocked, public IP requests on the login page are off, the API is HTTPS-only, and HSTS is sent. Security headers, `ServerTokens` and the other STIG settings come from the Apache role.

## Testing

Tests run locally with podman, not in CI. On the AlmaLinux 10 WSL environment:

```bash
scripts/test.sh            # lint, default (EL9 + EL10) and el8
scripts/test.sh lint
scripts/test.sh default
scripts/test.sh el8        # uses ansible-core 2.16
```

The script creates its virtualenvs and fetches the dependent roles into `.cache/test`. The repository directory must be named `ansible-role-phpipam`. The scenarios install fapolicyd (stopped) to exercise the trust file, enable the Kea pieces with a static Kea configuration, and verify the login page, redirects, blocked paths, both API apps, the settings, the Kea config file, the certificate, the jobs and a backup. Rootless podman needs working systemd containers; if they fail under WSL, run the script with `sudo`.

### One-time AlmaLinux 10 WSL setup

1. Turn on systemd in the distro. Put this in `/etc/wsl.conf`, run `wsl --shutdown` from Windows, then reopen the distro:

   ```ini
   [boot]
   systemd=true
   ```

2. Install the tools: `sudo dnf install -y podman python3 python3-pip git`.
3. Set up rootless podman for your user:

   ```bash
   grep -q "^$USER:" /etc/subuid || sudo usermod --add-subuids 100000-165535 --add-subgids 100000-165535 "$USER"
   sudo loginctl enable-linger "$USER"
   podman system migrate
   podman info          # should succeed and show cgroupVersion: v2
   ```

4. Clone the repositories side by side under your Linux home directory (for example `~/src/`), not under `/mnt/c`. The Windows mount is slow and does not keep Linux file permissions.

`scripts/test.sh` checks for systemd, cgroup v2, subordinate IDs and a user session before it starts, and prints the fix for anything missing. If rootless systemd containers still won't start, run `sudo scripts/test.sh` to use rootful podman.

## Main variables

See `defaults/main.yml` for everything, with comments.

| Variable | Default |
|---|---|
| `phpipam_server_name` | host FQDN |
| `phpipam_db_password` | required |
| `phpipam_admin_password` | required |
| `phpipam_manage_mariadb` / `phpipam_manage_apache` | `true` |
| `phpipam_tls_source` | `selfsigned` |
| `phpipam_kea_api_app_enabled` | `false` |
| `phpipam_kea_hosts_db_enabled` | `false` |
| `phpipam_kea_dhcp_module_enabled` | `false` |
| `phpipam_backup_enabled` | `true` |
| `phpipam_selinux_manage` / `phpipam_fapolicyd_manage` | `true` |

Tags: `phpipam_dependencies`, `phpipam_install`, `phpipam_config`, `phpipam_kea`, `phpipam_security` (`phpipam_selinux`, `phpipam_fapolicyd`), `phpipam_database`, `phpipam_tls`, `phpipam_web`, `phpipam_tuning`, `phpipam_jobs`, `phpipam_service`.

## License

MIT
