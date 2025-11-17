# Local Migrator – WordPress Download POC

Local Migrator has two parts:

- A WordPress plugin (`plugin/localpoc.php`) that exposes authenticated REST endpoints.
- A CLI (`localpoc`), packaged as a PHAR, that connects to the plugin and downloads the site (files + database) efficiently.

You install the plugin on a site, copy the command it shows you, and run that command locally.

---

## 1. Install the WordPress plugin

1. Download the latest `localpoc-plugin.zip` from the [releases page](https://github.com/joeguilmette/local-migrator-poc/releases/latest).
2. Install and activate the plugin in WordPress.

## 2. Install the CLI (PHAR)

One-liner install (Mac/Linux):

```bash
curl -L "https://github.com/joeguilmette/local-migrator-poc/releases/latest/download/localpoc.phar" -o localpoc \
  && chmod +x localpoc \
  && sudo mkdir -p /usr/local/bin \
  && sudo mv localpoc /usr/local/bin/localpoc \
  && localpoc --help
```

---

## 3. Run a download

Basic command:

```bash
localpoc download --url=<URL> --key=<KEY> [--output=<DIR>] [--concurrency=<N>]
```

* `--url`          Site base URL (from the plugin page), e.g. `https://example.com`
* `--key`          Access key (from the plugin page)
* `--output`       Local destination directory (default: `./local-backup`)
* `--concurrency`  Number of parallel file downloads (default: `4`)

Example:

```bash
localpoc download \
  --url="https://example.com" \
  --key="ACCESS_KEY_FROM_PLUGIN" \
  --output="./example-backup"
```

On success, Local Migrator will:

* Stream all `wp-content/` files plus the database into a temporary workspace.
* Build a single ZIP archive at `<output>/archives/<domain>-<YYYYmmdd-HHMMSS>.zip`.
  * The archive contains `wp-content/` (with the full tree) and `db.sql` at the root.
* Remove the workspace and print the final archive path plus transfer stats.

Exit codes:

* `0` – success
* `2` – bad or missing arguments
* `3` – HTTP / network error
* `4` – internal error

---

## 4. Development

These steps are for contributors and release maintainers.

### Requirements

* PHP ^8.0 with the cURL and PHAR extensions
* Composer
* [humbug/box](https://github.com/box-project/box) listed in `cli/composer.json` (as a dev dependency)

### Build the PHAR from source

```bash
cd /path/to/local-migrator-poc/cli
composer install
vendor/bin/box compile
```

The build artifact is `cli/dist/localpoc.phar`.

### Install locally (for testing)

```bash
cd /path/to/local-migrator-poc/cli
sudo mkdir -p /usr/local/bin
sudo install -m 755 dist/localpoc.phar /usr/local/bin/localpoc
localpoc --help
```

Or run directly without installing:

```bash
php dist/localpoc.phar download --url=... --key=... --output=...
```

### Generate Plugin and PHAR, then publish a release

Use the helper script (`scripts/release.sh`, requires the [GitHub CLI](https://cli.github.com/) with `gh auth login`):

```bash
./scripts/release.sh
```

The script builds the plugin ZIP and PHAR, then creates or updates the GitHub release (attaching `localpoc-plugin-<version>.zip` and `cli/dist/localpoc.phar`).

It will also install the latest version of the PHAR locally.

---

## 5. Uninstall

* Remove the CLI:

  ```bash
  sudo rm /usr/local/bin/localpoc
  ```

* Remove the plugin:

  * Deactivate it from the WordPress Plugins screen.
  * Delete `wp-content/plugins/localpoc/`.
