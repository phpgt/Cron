# Run scripts or static functions at regular intervals.

Define background jobs in standard crontab format and the Cron Runner will execute them when they are due. Jobs can be either normal scripts, or calls to static functions with automatic autoloading taken care of. 

*** 

<a href="https://github.com/PhpGt/Cron/actions" target="_blank">
	<img src="https://badge.status.php.gt/cron-build.svg" alt="Build status" />
</a>
<a href="https://app.codacy.com/gh/PhpGt/Cron" target="_blank">
	<img src="https://badge.status.php.gt/cron-quality.svg" alt="Code quality" />
</a>
<a href="https://app.codacy.com/gh/PhpGt/Cron" target="_blank">
	<img src="https://badge.status.php.gt/cron-coverage.svg" alt="Code coverage" />
</a>
<a href="https://packagist.org/packages/PhpGt/Cron" target="_blank">
	<img src="https://badge.status.php.gt/cron-version.svg" alt="Current version" />
</a>
<a href="http://www.php.gt/cron" target="_blank">
	<img src="https://badge.status.php.gt/cron-docs.svg" alt="PHP.Gt/Cron documentation" />
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

If you're using [WebEngine](https://php.gt/webengine), the Cron Runner is automatically started for you by running `gt run`.
