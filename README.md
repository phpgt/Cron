# Run scripts or static functions at regular intervals.

Define background jobs in standard crontab format and the Cron Runner will execute them when they are due. Jobs can be either normal scripts, or calls to static functions with automatic autoloading taken care of. 

*** 

<a href="https://github.com/PhpGt/Cron/actions" target="_blank">
	<img src="https://badge.status.php.gt/cron-build.svg" alt="Build status" />
</a>
<a href="https://app.codacy.com/gh/PhpGt/Cron" target="_blank">
	<img src="https://badge.status.php.gt/cron-quality.svg" alt="Code quality" />
</a>
<a href="https://app.codecov.io/gh/PhpGt/Cron" target="_blank">
	<img src="https://badge.status.php.gt/cron-coverage.svg" alt="Code coverage" />
</a>
<a href="https://packagist.org/packages/PhpGt/Cron" target="_blank">
	<img src="https://badge.status.php.gt/cron-version.svg" alt="Current version" />
</a>
<a href="http://www.php.gt/cron" target="_blank">
	<img src="https://badge.status.php.gt/cron-docs.svg" alt="PHP.GT/Cron documentation" />
</a>

## Example usage

`crontab` file within your project directory:

```
00 * * * *	ExampleClass::hourlyTask()
0 22 * * 1-5	YourApp\Accounts\Daily::nightRoutine("you can pass properties too!")
# This is a comment. The above job will run at 10pm Mon-Fri.
*/10 * * * *	ExampleClass::runEveryTenMinutes
```

Start the Runner: `vendor/bin/cron`.

A missing crontab is a normal no-op: the command prints a skip message and exits successfully with status 0, including in watch, validate and explain modes. Existing malformed crontab files still report an error.

If you're using [WebEngine](https://php.gt/webengine), the Cron Runner is automatically started for you by running `gt run`.

## Examples

There is an [example](example/README.md) directory with numbered scripts that can be run directly with `php`. Each one embeds its own crontab string so the schedule and output stay together.

# Proudly sponsored by

[JetBrains Open Source sponsorship program](https://www.jetbrains.com/community/opensource/)

[![JetBrains logo.](https://resources.jetbrains.com/storage/products/company/brand/logos/jetbrains.svg)](https://www.jetbrains.com/community/opensource/)

## Running on a systemd server

From your application's root directory, generate its service file:

```sh
gt cron --generate-service
# Without GtCommand: vendor/bin/cron --generate-service
```

This writes `cron.service` in the current project directory (replacing a previous
version). It validates the project's crontab without executing jobs. Defaults are
inferred from the project: the owner UID and group GID of `vendor`, the current
working directory, the running PHP executable, and `vendor/bin/cron`. Restart delay
is 10 seconds. Numeric UID/GID values are accepted by systemd.

Override individual settings when needed:

```sh
gt cron --generate-service --user www-data --group www-data \
  --working-directory /var/www/phpgt/www.php.gt \
  --php-binary /usr/bin/php --cron-binary /var/www/phpgt/www.php.gt/vendor/bin/cron \
  --restart-seconds 15
```

Relative override paths resolve against the current project directory. Even with a
working-directory override, the generated file stays in the current project root.
Review it, then install with system privileges:

```sh
sudo vendor/bin/cron --install-service
# Or: sudo gt cron --install-service
sudo systemctl start www.php.gt-cron.service
```

Installation symlinks `cron.service` into `/etc/systemd/system`, using the current
project directory's basename followed by `-cron.service`, reloads systemd, and
enables startup at boot. It does not start the service immediately. Reinstalling
the same symlink is supported; an existing file or link to another service is
rejected. Project basenames must contain only letters, numbers, dots, underscores
or hyphens. Keep the project and service file at stable paths. On a shared host,
project basenames must be unique. Generation and installation can also be combined
with `--generate-service --install-service`.

The service runs `cron --watch` in the foreground and restarts after exits. To
inspect or manage it (substitute your project's service name):

```sh
systemctl status www.php.gt-cron.service
journalctl -u www.php.gt-cron.service -f
sudo systemctl restart www.php.gt-cron.service
sudo systemctl disable --now www.php.gt-cron.service
```

Restart after deployments to load changed schedules, configuration and PHP code.
For removal, disable/stop it first, remove its symlink from `/etc/systemd/system`,
and run `sudo systemctl daemon-reload`. The service does not add job timeouts,
missed-run replay, or graceful job completion on shutdown.
