<?php
namespace GT\Cron\Test\Command;

use DirectoryIterator;
use Gt\Cli\Stream;
use PHPUnit\Framework\TestCase;

class CommandTestCase extends TestCase {
	protected $projectDirectory;

	protected function setUp():void {
		$this->projectDirectory = implode(DIRECTORY_SEPARATOR, [
			sys_get_temp_dir(),
			"phpgt",
			"cron",
			"test-project-" . uniqid(),
		]);
		mkdir(
			$this->projectDirectory,
			0775,
			true
		);
	}

	protected function tearDown():void {
		$this->recursiveRemove($this->projectDirectory);
	}

	protected function recursiveRemove(string $path):void {
		if(is_dir($path)) {
			foreach(new DirectoryIterator($path) as $file) {
				if($file->isDot()) {
					continue;
				}

				$pathname = $file->getPathname();

				if(is_dir($pathname)) {
					$this->recursiveRemove($pathname);
				}
				else {
					unlink($pathname);
				}
			}
		}
		else {
			unlink($path);
		}
	}

	protected function writeCronContents(string $contents):void {
		file_put_contents(implode(DIRECTORY_SEPARATOR, [
			$this->projectDirectory,
			"crontab"
		]), $contents);
	}

	protected function writeProjectFile(string $relativePath, string $contents):void {
		$pathName = implode(DIRECTORY_SEPARATOR, [
			$this->projectDirectory,
			$relativePath,
		]);
		$directory = dirname($pathName);
		if(!is_dir($directory)) {
			mkdir($directory, 0775, true);
		}
		file_put_contents($pathName, $contents);
	}

	protected function getStream():Stream {
		$stream = new Stream(
			"php://memory",
			"php://memory",
			"php://memory"
		);

		return $stream;
	}

	protected static function assertStreamOutput(
		string $message,
		Stream $stream
	) {
		$errorStream = $stream->getOutStream();
		$errorStream->rewind();
		$contents = $errorStream->fgets();
		self::assertStringContainsString($message, $contents);
	}

	protected static function assertStreamError(
		string $message,
		Stream $stream
	) {
		$errorStream = $stream->getErrorStream();
		$errorStream->rewind();
		$contents = $errorStream->fgets();
		self::assertStringContainsString($message, $contents);
	}
}
