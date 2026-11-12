<?php
namespace GT\Cron\Test;

use GT\Cron\CronException;
use GT\Cron\Service;
use GT\Cron\Test\Helper\Override;
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

	public function testGenerate_relativeOverridesResolveAgainstProject():void {
		mkdir("$this->project/working");
		file_put_contents("$this->project/working/crontab", "0 * * * * example\n");
		symlink(PHP_BINARY, "$this->project/php");
		$sut = new Service($this->project);
		$contents = file_get_contents($sut->generate([
			"working-directory" => "working",
			"php-binary" => "php",
			"cron-binary" => "vendor/bin/cron",
			"user" => "www-data",
			"group" => "www-data",
		]));
		self::assertStringContainsString("WorkingDirectory=$this->project/working\n", $contents);
		self::assertStringContainsString('ExecStart="' . realpath(PHP_BINARY) . '" "' . $this->project . '/vendor/bin/cron" --watch', $contents);
		self::assertFileDoesNotExist("$this->project/working/cron.service");
	}

	public function testGenerate_missingGroupCannotBeInferredWithExplicitUser():void {
		$sut = new Service($this->project);
		$this->expectExceptionMessage("Cannot infer service group");
		$sut->generate(["working-directory" => "units", "user" => "www-data"]);
	}

	public function testGenerate_nonExecutablePhpPreservesExistingService():void {
		file_put_contents("$this->project/cron.service", "existing service");
		$sut = new Service($this->project);
		$this->expectExceptionMessage("PHP binary must be executable");
		try {
			$sut->generate(["php-binary" => "vendor/bin/cron"]);
		}
		finally {
			self::assertSame("existing service", file_get_contents("$this->project/cron.service"));
		}
	}

	public function testGenerate_missingCrontabPreservesExistingService():void {
		unlink("$this->project/crontab");
		file_put_contents("$this->project/cron.service", "existing service");
		$sut = new Service($this->project);
		$this->expectExceptionMessage("crontab file not found");
		try {
			$sut->generate();
		}
		finally {
			self::assertSame("existing service", file_get_contents("$this->project/cron.service"));
		}
	}

	public function testGenerate_controlCharactersInExistingPathAreRejected():void {
		$binary = "$this->project/cron\nUser=root";
		file_put_contents($binary, "<?php");
		$sut = new Service($this->project);
		$this->expectExceptionMessage("Service paths cannot contain control characters");
		try {
			$sut->generate(["cron-binary" => $binary]);
		}
		finally {
			self::assertFileDoesNotExist("$this->project/cron.service");
		}
	}

	public function testGenerate_writeFailureIsReported():void {
		mkdir("$this->project/cron.service");
		$sut = new Service($this->project);
		$this->expectExceptionMessage("Unable to write $this->project/cron.service");
		$warnings = [];
		set_error_handler(function(int $severity, string $message) use (&$warnings):bool {
			$warnings []= $message;
			return true;
		});
		try {
			$sut->generate();
		}
		finally {
			restore_error_handler();
			self::assertStringContainsString("Is a directory", implode("\n", $warnings));
			self::assertDirectoryExists("$this->project/cron.service");
		}
	}

	/** @dataProvider conflictingLinks */
	public function testInstall_conflictingSymlinkIsPreserved(bool $broken):void {
		file_put_contents("$this->project/cron.service", "[Unit]\n");
		$other = "$this->project/other.service";
		if(!$broken) {
			file_put_contents($other, "other service");
		}
		$target = "$this->units/" . basename($this->project) . "-cron.service";
		symlink($other, $target);
		$sut = $this->getMockBuilder(Service::class)
			->setConstructorArgs([$this->project, $this->units])
			->onlyMethods(["systemctl"])->getMock();
		$sut->expects(self::never())->method("systemctl");
		$this->expectExceptionMessage("Refusing to replace existing service");
		try {
			$sut->install();
		}
		finally {
			self::assertSame($other, readlink($target));
		}
	}

	public static function conflictingLinks():array {
		return ["existing destination" => [false], "broken destination" => [true]];
	}

	public function testInstall_invalidProjectNameDoesNotCallSystemctl():void {
		$project = "$this->project/invalid name";
		mkdir($project);
		file_put_contents("$project/cron.service", "[Unit]\n");
		$sut = $this->getMockBuilder(Service::class)
			->setConstructorArgs([$project, $this->units])
			->onlyMethods(["systemctl"])->getMock();
		$sut->expects(self::never())->method("systemctl");
		$this->expectExceptionMessage("Project directory name is not a valid service name");
		$sut->install();
	}

	public function testInstall_linkFailureDoesNotCallSystemctl():void {
		file_put_contents("$this->project/cron.service", "[Unit]\n");
		$sut = $this->getMockBuilder(Service::class)
			->setConstructorArgs([$this->project, "$this->units/missing"])
			->onlyMethods(["systemctl"])->getMock();
		$sut->expects(self::never())->method("systemctl");
		$this->expectExceptionMessage("Installation requires system privileges");
		$warnings = [];
		set_error_handler(function(int $severity, string $message) use (&$warnings):bool {
			$warnings []= $message;
			return true;
		});
		try {
			$sut->install();
		}
		finally {
			restore_error_handler();
			self::assertNotEmpty($warnings);
			self::assertFileExists("$this->project/cron.service");
		}
	}

	/** @dataProvider startModes */
	public function testInstall_linksReloadsAndEnablesIdempotently(bool $start):void {
		file_put_contents("$this->project/cron.service", "[Unit]\n");
		$sut = $this->getMockBuilder(Service::class)
			->setConstructorArgs([$this->project, $this->units])
			->onlyMethods(["systemctl"])->getMock();
		$commands = [];
		$sut->expects(self::exactly(4))->method("systemctl")
			->willReturnCallback(function(array $arguments) use (&$commands):void {
				$commands []= $arguments;
			});
		$name = $sut->install($start);
		self::assertSame(basename($this->project) . "-cron.service", $name);
		self::assertSame("$this->project/cron.service", readlink("$this->units/$name"));
		self::assertSame($name, $sut->install($start));
		$enable = $start ? ["enable", "--now", $name] : ["enable", $name];
		self::assertSame([
			["daemon-reload"], $enable,
			["daemon-reload"], $enable,
		], $commands);
	}

	public static function startModes():array {
		return ["start immediately" => [true], "enable only" => [false]];
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
	public function testInstall_noTemporaryStoragePreventsSystemctlExecution():void {
		file_put_contents("$this->project/cron.service", "[Unit]\n");
		Override::setCallback("tmpfile", fn() => false);
		Override::setCallback("proc_open", function():void {
			self::fail("Cannot launch systemctl without being able to capture errors.");
		});
		$sut = new Service($this->project, $this->units);
		$this->expectException(CronException::class);
		$this->expectExceptionMessage("Unable to capture systemctl output");
		$sut->install();
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function testInstall_processLaunchFailureClosesOutputFile():void {
		file_put_contents("$this->project/cron.service", "[Unit]\n");
		$output = \tmpfile();
		Override::setCallback("tmpfile", fn() => $output);
		Override::setCallback("proc_open", function(array $command, array $descriptors) use ($output) {
			self::assertSame(["systemctl", "daemon-reload"], $command);
			self::assertSame($output, $descriptors[1]);
			self::assertSame($output, $descriptors[2]);
			return false;
		});
		$sut = new Service($this->project, $this->units);
		$this->expectException(CronException::class);
		$this->expectExceptionMessage("Unable to execute systemctl");
		try {
			$sut->install();
		}
		finally {
			self::assertFalse(is_resource($output), "Capture file must be closed after a failed launch.");
		}
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
