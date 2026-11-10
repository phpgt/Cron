<?php
namespace GT\Cron\Test\Cli;

use GT\Cron\Cli\RunCommand;
use GT\Cli\Argument\ArgumentValueList;
use GT\Cli\Stream;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RunCommandTest extends TestCase {
	private string $directory;

	protected function setUp():void {
		$this->directory = sys_get_temp_dir() . "/cron-missing-" . uniqid();
		mkdir($this->directory);
	}

	protected function tearDown():void {
		foreach(glob("$this->directory/*") as $file) {
			unlink($file);
		}
		rmdir($this->directory);
	}

	#[DataProvider("missingCrontabModes")]
	public function testRun_missingCrontabExitsSuccessfully(array $arguments):void {
		$sut = [PHP_BINARY, dirname(__DIR__, 3) . "/bin/cron", ...$arguments];
		[$status, $output, $errors] = $this->runCommand($sut);

		self::assertSame(0, $status, $errors);
		self::assertStringContainsString("Skipping cron as there is no crontab file.", $output);
		self::assertSame("", $errors);
		self::assertSame([], glob("$this->directory/*"));
	}

	public static function missingCrontabModes():iterable {
		yield "default" => [[]];
		yield "watch" => [["--watch"]];
		yield "validate" => [["--validate"]];
		yield "explain" => [["--explain"]];
		yield "custom filename" => [["--file", "custom.crontab"]];
	}

	public function testRun_missingCrontabIsANormalSkippedCommand():void {
		$sut = new RunCommand();
		$stream = new Stream("php://memory", "php://memory", "php://memory");
		$sut->setStream($stream);
		$previousCwd = getcwd();
		$previousTimezone = date_default_timezone_get();
		chdir($this->directory);

		try {
			self::assertSame(0, $sut->run(new ArgumentValueList()));
		}
		finally {
			chdir($previousCwd);
			date_default_timezone_set($previousTimezone);
		}

		$stream->getOutStream()->rewind();
		self::assertStringContainsString("Skipping cron", $stream->getOutStream()->fread(4096));
		$stream->getErrorStream()->rewind();
		self::assertSame("", $stream->getErrorStream()->fread(4096));
	}

	public function testRun_invalidExistingCrontabStillFails():void {
		file_put_contents("$this->directory/crontab", "not a valid schedule");
		$sut = [PHP_BINARY, dirname(__DIR__, 3) . "/bin/cron"];
		[$status, $output, $errors] = $this->runCommand($sut);

		self::assertSame(2, $status);
		self::assertStringContainsString("Invalid syntax", $errors);
		self::assertStringNotContainsString("Skipping cron", $output);
	}

	private function runCommand(array $command):array {
		$process = proc_open($command, [1 => ["pipe", "w"], 2 => ["pipe", "w"]], $pipes, $this->directory);
		$output = stream_get_contents($pipes[1]);
		$errors = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		return [proc_close($process), $output, $errors];
	}
}
