# Changelog

## Unreleased

### Upgrade notes (action needed)

- **`phpipam_kea_hosts_db_client_hosts` has no default.** It used to be `["%"]`, any host. With `phpipam_kea_hosts_db_enabled`, list the Kea servers' addresses.
- **Release tarballs are verified.** Known releases (1.8.2, 1.8.3) have a pinned SHA-256. For any other `phpipam_version`, set `phpipam_archive_checksum`, or the role stops before downloading. `phpipam_archive_src` tarballs are checked as well.
- **The phpIPAM database user has day-to-day rights only** (`phpipam_db_privileges`), on a local database the role manages. Schema imports and upgrades get `ALL` while they run. Set `phpipam_db_privileges: ALL` if you use the UI's "fix database" repair.
- **Backups run as `phpipam_backup`** through a unix_socket MariaDB account with read-only rights. The backup directory now belongs to that user.
- **Logos move to `/var/lib/phpipam/logo`** (`phpipam_logo_dir`), linked into each release. Logos already uploaded into the current release are copied there on the next run.
- **PHP runs with `open_basedir` and without `allow_url_fopen`.** If something you use needs another path or URL wrappers (for example the update check), extend `phpipam_php_open_basedir` or set `phpipam_php_allow_url_fopen: true`.
- **The dependent roles are pinned to commits** in `requirements.yml`, not `main`.

### Added

- `phpipam_kea_api_urls`: one Kea API URL per service, for Kea 2.7.2 and newer, where each daemon serves the API itself (the Kea role's default). Leave it empty to keep using `phpipam_kea_ctrl_agent_url`.
- `phpipam_backup_encrypt_cert`: encrypt nightly and pre-upgrade backups to a certificate (CMS, AES-256, RSA-OAEP).
- `phpipam_kea_hosts_db_tls_requires`, to require client certificates for the Kea accounts.
- systemd sandboxing for the job and backup units.
- HTTP/2 (`phpipam_http2`) and `Cache-Control` for static files (`phpipam_static_cache_max_age`).
- `opcache.validate_timestamps` off by default (`phpipam_php_opcache_validate_timestamps`).

### Fixed

- fapolicyd trust no longer covers the upload folders, so a file that lands there is never trusted.
