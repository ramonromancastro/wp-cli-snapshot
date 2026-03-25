# WP-CLI Snapshot

[![Minimum PHP Version](https://img.shields.io/badge/php-%3E%3D%207.4-8892BF.svg)](https://php.net/)
[![WP-CLI](https://img.shields.io/badge/WP--CLI-%3E%3D%202.8-blue.svg)](https://wp-cli.org/)

A professional, declarative state management tool for WordPress. 

Tired of managing plugins, themes, and core updates manually across multiple environments? **WP-CLI Snapshot** allows you to export your current environment state to a JSON file (`snapshot.json`), audit it against WordPress.org for security vulnerabilities or abandoned code, and enforce that exact state across any other WordPress installation idempotently.

Perfect for CI/CD pipelines, agency workflows, and local development environments.

## 📦 Installation

Installing this package via WP-CLI is straightforward. Run the following command in your terminal:

```bash
wp package install ramonromancastro/wp-cli-snapshot
```

## 🚀 Commands Overview

This package introduces the wp snapshot namespace, providing three powerful commands: export, validate, and apply.

### 1. wp snapshot export

Exports the current state of WordPress Core, installed Themes, and Plugins into a declarative JSON file.

#### Basic Usage:

```Bash
wp snapshot export
```

#### Options:

- --file=<file>: Path to save the JSON file. (Default: snapshot.json)
- --custom-prefix=<prefixes>: Comma-separated list of prefixes to identify private/custom plugins that shouldn't be tracked against the WordPress.org repo.

#### Example (Agency Workflow):

```Bash
wp snapshot export --file=production-state.json --custom-prefix=acme_,myagency-
```

### 2. wp snapshot validate

Audits your snapshot.json file against the official WordPress.org APIs without modifying your local installation. It checks for outdated plugins, insecure Core versions, and stale/abandoned packages.

#### Basic Usage:

```Bash
wp snapshot validate
```

#### Options:

- --file=<file>: Path to the JSON file to read. (Default: snapshot.json)
- --stale-days=<days>: Number of days without an update before a package is flagged as abandoned. (Default: 730 - 2 years).
- --strict: Return a non-zero exit code if ANY package is outdated or stale. By default, only critical security issues (Insecure Core, Removed packages) return an error code.
- --format=<format>: Output format (table, json, csv, yaml). Essential for CI/CD integrations. (Default: table).

#### Example (CI/CD Pipeline Security Check):

```Bash
# Check with strict 1-year stale policy and export to JSON
wp snapshot validate --stale-days=365 --format=json > security-audit.json
```

### 3. wp snapshot apply

Reads the snapshot.json file and synchronizes the local WordPress environment to match it. It installs missing plugins/themes, updates outdated ones, and activates/deactivates them to match the exact state of the snapshot.

> Safety First: By default, it will refuse to downgrade a package if the local version is higher than the snapshot version, preventing fatal database errors.

#### Basic Usage:

```Bash
wp snapshot apply
```

#### Options:

- --file=<file>: Path to the JSON file to read. (Default: snapshot.json)
- --dry-run: Simulates the deployment process without modifying the filesystem.
- --force: Bypasses safety checks and forces downgrades if necessary.

#### Example (Safe Deployment Simulation):

```
Bash
wp snapshot apply --file=staging-state.json --dry-run
```

## 🛡️ CI/CD Integration

Because validate supports stdout formatting and apply is fully idempotent, you can easily integrate this into GitHub Actions, GitLab CI, or any bash deployment script to ensure all your WordPress nodes share the exact same configuration state.

## Development & Transparency

### Human-in-the-loop
This project leverages **AI for code generation**. I believe in the ethical use of AI to speed up Open Source development while maintaining manual oversight on security and architecture. 

Every line of code generated has been reviewed, tested, and adapted to meet WP-CLI standards and ensure the reliability of your WordPress snapshots.

## Credits

* **Author:** Ramón Román Castro ([@ramonromancastro](https://github.com/ramonromancastro))
* **Homepage:** [rrc2software.org](https://www.rrc2software.org)

## License

This project is licensed under the MIT License.
