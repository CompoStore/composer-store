# cstore

> **⚠️ Early Development — MVP Testing Phase**
> This project is under active development and is currently being tested as an MVP. APIs, behaviour, and file formats may change. Not recommended for production use yet. Feedback and bug reports welcome.

---

> A pnpm-inspired global store for Composer packages.
> Share packages across projects. Stop duplicating `vendor/`.

---

## The Problem

Every Laravel/PHP project has its own `vendor/` directory. With 100 projects, `laravel/framework` is downloaded and stored 100 times. pnpm solved this for Node.js — `cstore` brings the same idea to Composer.

## How It Works

```
~/.composer-store/packages/
  laravel+framework@11.0.0/   ← stored ONCE
  filament+filament@3.2.0/    ← stored ONCE

project-a/vendor/laravel/framework/  ← hard linked to store (inode: 269463130)
project-b/vendor/laravel/framework/  ← hard linked to same file (inode: 269463130)
project-c/vendor/laravel/framework/  ← hard linked to same file (inode: 269463130)
```

**Hard links** = same inode, no disk duplication, but each project sees its own copy.
`vendor/composer/` (autoloader) is always **per-project** — never shared.

---

## Two Ways to Use

### Option 1: Composer Plugin (Recommended)

Add cstore to your project and `composer install` works transparently:

```json
{
    "require": {
        "compostore/compostore": "*@dev"
    },
    "config": {
        "allow-plugins": {
            "compostore/compostore": true
        }
    }
}
```

Then just run `composer install` as usual — cstore intercepts library package installs and routes them through the global store automatically.

### Option 2: Standalone CLI

```bash
git clone https://github.com/CompoStore/composer-store
cd composer-store && composer install

# Install packages for a project
./bin/cstore install /path/to/project

# Check store status
./bin/cstore status

# Prune unused packages
./bin/cstore prune --scan ~/projects --dry-run
```

---

## CLI Commands

### `cstore install [path] [--no-dev] [--store=PATH]`

Reads `composer.lock`, syncs packages to the global store, and hard links them into `vendor/`.

```bash
cstore install                 # current directory
cstore install /path/to/project
cstore install --no-dev        # skip dev dependencies
```

Supported dist types: `zip`, `tar`, `tgz`/`tar.gz`, and `path`.

### `cstore status [--store=PATH]`

Shows store location, total packages, and disk usage.

```
Store Info
  Location:         /Users/you/.composer-store
  Total packages:   142
  Total size:       1.2 GB

Stored Packages
  ✓ laravel+framework@11.0.0
  ✓ filament+filament@3.2.0
```

### `cstore prune [--dry-run] [--scan=DIR] [--store=PATH]`

Removes packages from the store that are no longer referenced by any project.

```bash
cstore prune --dry-run --scan ~/projects   # preview
cstore prune --scan ~/projects             # actually remove
```

---

## Integration Matrix (10 Projects, Composer Plugin)

All integration fixtures now live under `integration/projects/` (no root `example` folders).

### What the matrix covers

- 10 separate projects using the **Composer Plugin** approach (`compostore/compostore` in `require`)
- Popular public packages across Symfony, Guzzle, Monolog, Doctrine, Flysystem, and Laravel
- One local private package (`acme/private-toolkit`) installed in `project-05`
- Hard-link verification for shared files (`psr/log`) across projects

### Fixture layout

```text
integration/
  projects/
    project-01 ... project-10
  private-packages/
    acme-private-toolkit/
  results/
    latest-summary.md
```

### Run the full matrix

```bash
./bin/run-integration-matrix --clean
```

This generates per-project logs in `integration/results/` and a summary file:
`integration/results/latest-summary.md`.

### Latest run summary

- Projects tested: **10**
- Successful installs: **10**
- Failed installs: **0**
- Total elapsed: **62s**
- Store packages: **41**
- Store size: **14M**
- Private package installed: **yes** (`project-05`)
- Hard link verification (`psr/log`): **verified**

---

## Real Laravel Live Smoke Test (Ephemeral)

In addition to fixture projects, a live smoke test was executed with two real `laravel/laravel` projects created under `/tmp`, both configured to use cstore via Composer Plugin.

### What was validated

- Both Laravel projects completed dependency install with cstore plugin enabled
- Both apps ran concurrently with `php artisan serve` on separate ports
  - `127.0.0.1:8101`
  - `127.0.0.1:8102`
- Health endpoints returned success on both apps
  - `GET /up` -> `200` on both

### Cleanup

- Both temporary Laravel projects and runtime artifacts were deleted after test completion
- No repository files were changed by this smoke test run

---

## Architecture

```
src/
  Application.php                 ← Symfony Console bootstrap
  Commands/
    InstallCommand.php            ← cstore install
    StatusCommand.php             ← cstore status
    PruneCommand.php              ← cstore prune
  Store/
    GlobalStore.php               ← manages ~/.composer-store
    PackageDownloader.php         ← syncs package archives/path repos into store (with integrity checks)
    PackageInspector.php          ← detects packages with scripts (copy instead of link)
  Linker/
    VendorLinker.php              ← hard links store → vendor/ (or copies for script packages)
    AutoloaderGenerator.php       ← runs composer dump-autoload
  Parser/
    LockFileParser.php            ← parses composer.lock
  Plugin/
    CStorePlugin.php              ← Composer plugin entry point
    IOOutputAdapter.php           ← bridges Composer IO to Symfony Output
  Installer/
    CStoreInstaller.php           ← custom installer for Composer plugin
tests/
  Linker/VendorLinkerTest.php
  Parser/LockFileParserTest.php
  Store/GlobalStoreTest.php
  Store/PackageDownloaderTest.php
  Store/PackageInspectorTest.php
bin/
  cstore                          ← CLI entry point
  run-integration-matrix          ← 10-project Composer plugin test runner
integration/
  projects/                       ← 10 integration fixture projects
  private-packages/               ← local private package fixture
  results/                        ← matrix logs + summary output
```

---

## How the Composer Plugin Works

1. User adds `compostore/compostore` to their project's `composer.json`
2. `composer install` installs cstore and its dependencies first (normal Composer flow)
3. Plugin activates and registers a custom installer (`CStoreInstaller`)
4. All subsequent `library` type packages go through the store:
   - **Sync phase**: package is downloaded/extracted (or copied for `path` repos) to `~/.composer-store/packages/`
   - **Install phase**: files are hard linked from store into `vendor/`
5. On re-install: packages already in the store are linked instantly (zero downloads)

---

## Known Limitations

- Source-only VCS installs (no dist archive URL) fall back to Composer's default installer
- Packages with `scripts` in their `composer.json` are copied, not hard-linked (safe but uses extra space)
- Windows not yet supported
- No parallel downloads (sequential)
- Plugin's own dependencies (symfony/console, etc.) install via Composer's default flow

---

## Roadmap

| Phase | Status | Goal |
|-------|--------|------|
| 1 | Done | CLI MVP — `install`, `status`, `prune` |
| 2 | Done | Composer Plugin — transparent `composer install` integration |
| 3 | Done | Integrity checks, post-install script safety, PHPUnit test suite |
| 4 | Planned | Windows support, Packagist release, parallel downloads |

---

## Tech Stack

- PHP 8.1+
- Symfony Console 6/7
- Composer Plugin API 2.0
- PHPUnit 10+

## AI-Assisted Development

This project was initially generated and developed with the assistance of [Claude](https://claude.ai) (Anthropic) and [Codex](https://openai.com/blog/openai-codex) (OpenAI).

AI tools were used throughout: architecture design, code generation, and documentation. All code has been reviewed, tested, and is maintained by human developers. The ideas, decisions, and responsibility for this software remain with the authors.

---

## License

MIT
