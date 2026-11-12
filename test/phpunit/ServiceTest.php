<?php
namespace GT\Cron\Test;

use GT\Cron\CronException;
use GT\Cron\Service;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use FilesystemIterator;

class ServiceTest extends TestCase {
	private string $project;
	private string $units;

	protected function setUp():void {
		$this->project = sys_get_temp_dir() . "/www.php.gt-" . uniqid();
		$this->units = "$this->project/units";
		mkdir("$this->project/vendor/bin", 0777, true);
		mkdir($this->units);
		file_put_contents("$this->project/vendor/bin/cron", "<?php");
		file_put_contents("$this->project/crontab", "0 * * * * example\n");
	}

	protected function tearDown():void {
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($this->project, FilesystemIterator::SKIP_DOTS),
			RecursiveIteratorIterator::CHILD_FIRST,
		);
		foreach($iterator as $file) {
			if($file->isDir() && !$file->isLink()) {
				rmdir($file->getPathname());
			}
			else {
				unlink($file->getPathname());
			}
		}
		rmdir($this->project);
	}

	public function testGenerate_infersVendorOwnership():void {
		$sut = new Service($this->project);
		$path = $sut->generate();
		$contents = file_get_contents($path);
		self::assertSame("$this->project/cron.service", $path);
		self::assertStringContainsString("User=" . fileowner("$this->project/vendor") . "\n", $contents);
		self::assertStringContainsString("Group=" . filegroup("$this->project/vendor") . "\n", $contents);
		self::assertStringContainsString('WorkingDirectory=' . $this->project . "\n", $contents);
		self::assertStringContainsString('ExecStart="' . PHP_BINARY . '" "' . $this->project . '/vendor/bin/cron" --watch', $contents);
		self::assertStringContainsString("Restart=always\nRestartSec=10", $contents);
	}

	public function testGenerate_overridesAndEscapesPaths():void {
		$working = $this->project . '/space % " $value';
		mkdir($working);
		file_put_contents("$working/crontab", "0 * * * * example\n");
		$binary = "$working/cron";
		file_put_contents($binary, "<?php");
		$sut = new Service($this->project);
		$contents = file_get_contents($sut->generate([
			"user" => "www-data",
			"group" => "www-data",
			"working-directory" => $working,
			"php-binary" => PHP_BINARY,
			"cron-binary" => $binary,
			"restart-seconds" => "2.5",
		]));
		self::assertStringContainsString("User=www-data\nGroup=www-data", $contents);
		self::assertStringContainsString('space %% " $value', $contents);
		self::assertStringContainsString('space %% \\" $$value/cron" --watch', $contents);
		self::assertStringContainsString("RestartSec=2.5", $contents);
	}

	/** @dataProvider invalidOptions */
	public function testGenerate_rejectsInvalidOptions(array $options):void {
		$sut = new Service($this->project);
		$this->expectException(CronException::class);
		$sut->generate($options);
	}

	public static function invalidOptions():array {
		return [
			[["user" => "www-data\nExecStart=/bin/false"]],
			[["group" => "invalid group"]],
			[["restart-seconds" => "0"]],
			[["restart-seconds" => "-1"]],
			[["restart-seconds" => "10\nUser=root"]],
			[["php-binary" => "/does/not/exist"]],
			[["cron-binary" => "/does/not/exist"]],
		];
	}

	public function testGenerate_missingVendor():void {
		unlink("$this->project/vendor/bin/cron");
		rmdir("$this->project/vendor/bin");
		rmdir("$this->project/vendor");
		$sut = new Service($this->project);
		$this->expectException(CronException::class);
		$this->expectExceptionMessage("Cannot infer service user");
		$sut->generate();
	}

	public function testGenerate_invalidCrontabDoesNotWriteService():void {
		file_put_contents("$this->project/crontab", "invalid");
		$sut = new Service($this->project);
		try {
			$sut->generate();
			self::fail("Expected invalid crontab to be rejected.");
		}
		catch(CronException) {
			self::assertFileDoesNotExist("$this->project/cron.service");
		}
	}

	public function testInstall_linksReloadsAndEnablesIdempotently():void {
		file_put_contents("$this->project/cron.service", "[Unit]\n");
		$sut = $this->getMockBuilder(Service::class)
			->setConstructorArgs([$this->project, $this->units])
			->onlyMethods(["systemctl"])->getMock();
		$commands = [];
		$sut->expects(self::exactly(4))->method("systemctl")
			->willReturnCallback(function(array $arguments) use (&$commands):void {
				$commands []= $arguments;
			});
		$name = $sut->install();
		self::assertSame(basename($this->project) . "-cron.service", $name);
		self::assertSame("$this->project/cron.service", readlink("$this->units/$name"));
		self::assertSame($name, $sut->install());
		self::assertSame([
			["daemon-reload"], ["enable", $name],
			["daemon-reload"], ["enable", $name],
		], $commands);
	}

	public function testInstall_refusesExistingFile():void {
		file_put_contents("$this->project/cron.service", "[Unit]\n");
		$target = "$this->units/" . basename($this->project) . "-cron.service";
		file_put_contents($target, "existing");
		$sut = new Service($this->project, $this->units);
		$this->expectException(CronException::class);
		$this->expectExceptionMessage("Refusing to replace");
		try {
			$sut->install();
		}
		finally {
			self::assertSame("existing", file_get_contents($target));
		}
	}

	public function testInstall_missingService():void {
		$sut = new Service($this->project, $this->units);
		$this->expectException(CronException::class);
		$this->expectExceptionMessage("Generate cron.service");
		$sut->install();
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function testInstall_systemctlFailureIsReported():void {
		$bin = "$this->project/bin";
		mkdir($bin);
		$log = "$this->project/systemctl.log";
		file_put_contents("$bin/systemctl", "#!/bin/sh\nprintf '%s\\n' \"\$*\" >> " . escapeshellarg($log)
			. "\necho 'test reload failure' >&2\nexit 1\n");
		chmod("$bin/systemctl", 0755);
		file_put_contents("$this->project/cron.service", "[Unit]\n");
		$previousPath = getenv("PATH");
		putenv("PATH=$bin:$previousPath");
		$sut = new Service($this->project, $this->units);
		$this->expectException(CronException::class);
		$this->expectExceptionMessage("test reload failure");
		try {
			$sut->install();
		}
		finally {
			putenv("PATH=$previousPath");
			self::assertSame("daemon-reload\n", file_get_contents($log));
		}
	}
}
