<?php
namespace GT\Cron\Test;

use GT\Cron\ResolvedScriptCommand;
use PHPUnit\Framework\TestCase;

class ResolvedScriptCommandTest extends TestCase {
	/** @runInSeparateProcess */
	public function testResolveReturnsOriginalCommandWhenNoLocalScriptExists():void {
		$command = "example --flag";
		$resolved = new ResolvedScriptCommand();

		self::assertSame($command, $resolved->resolve($command));
	}

	/** @runInSeparateProcess */
	public function testResolveUsesLocalCronScriptAlias():void {
		$tempDir = sys_get_temp_dir() . "/cron-test-" . uniqid();
		mkdir($tempDir);
		mkdir("$tempDir/cron");
		file_put_contents("$tempDir/cron/example.php", "<?php echo 'ok';");
		chdir($tempDir);

		$resolved = new ResolvedScriptCommand();

		self::assertSame(
			PHP_BINARY . " " . escapeshellarg("$tempDir/cron/example.php") . " --flag",
			$resolved->resolve("example --flag")
		);
	}

	/** @runInSeparateProcess */
	public function testResolveIgnoresNestedPaths():void {
		$command = "scripts/example.php --flag";
		$resolved = new ResolvedScriptCommand();

		self::assertSame($command, $resolved->resolve($command));
	}
}
