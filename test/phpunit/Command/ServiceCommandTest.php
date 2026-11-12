<?php
namespace GT\Cron\Test\Command;

use GT\Cli\Argument\CommandArgumentList;
use GT\Cli\Argument\ArgumentValueList;
use GT\Cron\Cli\RunCommand;
use GT\Cron\Cli\ServiceCommand;
use GT\Cron\Test\Helper\Override;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class ServiceCommandTest extends CommandTestCase {
	/** @dataProvider installationFlags */
	public function testRun_generateThenInstall(array $flags, bool $start):void {
		$this->writeProjectFile("vendor/bin/cron", "<?php");
		$this->writeProjectFile("bin/systemctl", "#!/bin/sh\nprintf '%s\\n' \"\$*\" >> "
			. escapeshellarg("$this->projectDirectory/systemctl.log") . "\n");
		chmod("$this->projectDirectory/bin/systemctl", 0755);
		$this->writeCronContents("* * * * * example");
		$this->writeProjectFile("cron/example.php", '<?php function go():void { file_put_contents("executed", "yes"); }');
		$stream = $this->getStream();
		$sut = new RunCommand();
		$sut->setStream($stream);
		$arguments = new CommandArgumentList("run", "cron", ...[
			...$flags, "--user", "www-data", "--group", "www-data",
			"--working-directory", $this->projectDirectory, "--php-binary", PHP_BINARY,
			"--cron-binary", "$this->projectDirectory/vendor/bin/cron", "--restart-seconds", "15",
		]);
		$previousDirectory = getcwd();
		$previousPath = getenv("PATH");
		$name = basename($this->projectDirectory) . "-cron.service";
		Override::setCallback("symlink", function(string $source, string $target) use ($name):bool {
			self::assertSame("$this->projectDirectory/cron.service", $source);
			self::assertSame("/etc/systemd/system/$name", $target);
			self::assertStringContainsString("User=www-data\nGroup=www-data", file_get_contents($source));
			return true;
		});
		try {
			chdir($this->projectDirectory);
			putenv("PATH=$this->projectDirectory/bin:$previousPath");
			$sut->checkArguments($arguments);
			self::assertSame(0, $sut->run($sut->getArgumentValueList($arguments)));
			$enable = $start ? "enable --now $name" : "enable $name";
			self::assertSame("daemon-reload\n$enable\n", file_get_contents("systemctl.log"));
			$contents = file_get_contents("cron.service");
			self::assertStringContainsString("WorkingDirectory=$this->projectDirectory\n", $contents);
			self::assertStringContainsString('ExecStart="' . PHP_BINARY . '" "' . $this->projectDirectory . '/vendor/bin/cron" --watch', $contents);
			self::assertStringContainsString("RestartSec=15", $contents);
			self::assertFileDoesNotExist("executed");
			self::assertStreamOutput("Generated", $stream);
			$stream->getOutStream()->rewind();
			$message = $start ? "Enabled and started $name." : "Enabled $name. Start with: systemctl start $name";
			self::assertStringContainsString($message, $stream->getOutStream()->fread(10000));
		}
		finally {
			chdir($previousDirectory);
			putenv("PATH=$previousPath");
		}
	}

	public static function installationFlags():array {
		return [
			"explicit generation" => [["--generate-service", "--install-service"], true],
			"automatic generation" => [["--install-service"], true],
			"enable only" => [["--install-service", "--no-start"], false],
		];
	}

	public function testRun_installPreservesCustomServiceWithoutProjectDependencies():void {
		$contents = "[Service]\nUser=custom-user\nExecStart=/custom/cron --watch\n";
		$this->writeProjectFile("cron.service", $contents);
		$arguments = new ArgumentValueList();
		$arguments->set("install-service");
		$arguments->set("user", "ignored-override");
		$stream = $this->getStream();
		$sut = new ServiceCommand($stream);
		Override::setCallback("symlink", function(string $source) use ($contents):bool {
			self::assertSame($contents, file_get_contents($source));
			throw new \GT\Cron\CronException("Reached installation with custom service intact");
		});
		$previousDirectory = getcwd();
		try {
			chdir($this->projectDirectory);
			self::assertSame(2, $sut->run($arguments));
			self::assertStreamError("Reached installation with custom service intact", $stream);
			self::assertSame($contents, file_get_contents("cron.service"));
		}
		finally {
			chdir($previousDirectory);
		}
	}

	public function testRun_invalidGenerationDoesNotProceedToInstall():void {
		$this->writeProjectFile("vendor/bin/cron", "<?php");
		$this->writeCronContents("* * * * * example");
		$stream = $this->getStream();
		$sut = new ServiceCommand($stream);
		$arguments = new ArgumentValueList();
		$arguments->set("generate-service");
		$arguments->set("install-service");
		$arguments->set("restart-seconds", "-1");
		Override::setCallback("symlink", function():bool {
			self::fail("Installation must not proceed after failed generation.");
		});
		$previousDirectory = getcwd();
		try {
			chdir($this->projectDirectory);
			self::assertSame(2, $sut->run($arguments));
			self::assertStreamError("Restart seconds must be greater than zero", $stream);
			self::assertFileDoesNotExist("cron.service");
		}
		finally {
			chdir($previousDirectory);
		}
	}
}
