<?php
namespace Gt\Cron\Test\Command;

use Gt\Cli\Argument\ArgumentValueList;
use Gt\Cron\Cli\RunCommand;
use Gt\Cron\Test\Command\CommandTestCase;
use Gt\Cron\Test\Helper\ExampleClass;
use Gt\Cron\Test\Helper\Override;

/** @runTestsInSeparateProcesses  */
class RunCommandTest extends CommandTestCase {
	public function testRunInvalidSyntax() {
		$cronContents = <<<CRON
* * This is wrong syntax
CRON;
		$this->writeCronContents($cronContents);
		$stream = $this->getStream();
		chdir($this->projectDirectory);
		$command  = new RunCommand();
		$command->setStream($stream);
		$command->run(new ArgumentValueList());

		self::assertStreamError(
			"Invalid syntax: * *",
			$stream
		);
	}

	public function testRunNowFunction() {
		$cronContents = <<<CRON
* * * * * \Gt\Cron\Test\Helper\ExampleClass::doSomething
CRON;
		$this->writeCronContents($cronContents);
		$stream = $this->getStream();
		chdir($this->projectDirectory);

		$args = new ArgumentValueList();
		$args->set("once");
		$command = new RunCommand();
		$command->setStream($stream);

		self::assertEquals(
			0,
			ExampleClass::$calls
		);
		$command->run($args);
		self::assertEquals(
			1,
			ExampleClass::$calls
		);

		self::assertStreamOutput("Just ran 1 job", $stream);
		self::assertStreamOutput("Stopping now", $stream);
	}

	public function testRunNowFunctionWithArguments() {
		$cronContents = <<<CRON
* * * * * \Gt\Cron\Test\Helper\ExampleClass::doSomething("a test message", 123)
CRON;
		$this->writeCronContents($cronContents);
		$stream = $this->getStream();
		chdir($this->projectDirectory);

		$args = new ArgumentValueList();
		$args->set("once");
		$command = new RunCommand();
		$command->setStream($stream);

		self::assertEquals(
			"",
			\Gt\Cron\Test\Helper\ExampleClass::$message
		);
		self::assertEquals(
			0,
			\Gt\Cron\Test\Helper\ExampleClass::$counter
		);
		$command->run($args);
		self::assertEquals(
			"a test message",
			\Gt\Cron\Test\Helper\ExampleClass::$message
		);
		self::assertEquals(
			123,
			\Gt\Cron\Test\Helper\ExampleClass::$counter
		);
	}

	public function testRunNowFunctionNoSlash() {
		$cronContents = <<<CRON
* * * * * Gt\Cron\Test\Helper\ExampleClass::doSomething
CRON;
		$this->writeCronContents($cronContents);
		$stream = $this->getStream();
		chdir($this->projectDirectory);

		$args = new ArgumentValueList();
		$args->set("once");
		$command = new RunCommand();
		$command->setStream($stream);
		$command->run($args);
		self::assertEquals(
			1,
			\Gt\Cron\Test\Helper\ExampleClass::$calls
		);
	}

	public function testRunNowScript() {
		$cronContents = <<<CRON
* * * * * /path/to/script/doSomething
CRON;
		$this->writeCronContents($cronContents);
		$stream = $this->getStream();
		chdir($this->projectDirectory);

		$calledCommand = null;

		Override::setCallback(
			"proc_open",
			function($command)use(&$calledCommand) {
				$calledCommand = $command;
			}
		);
		Override::load("proc_get_status");
		Override::load("proc_close");

		$args = new ArgumentValueList();
		$args->set("once");
		$command = new RunCommand();
		$command->setStream($stream);
		$command->run($args);
		self::assertEquals(
			"/path/to/script/doSomething",
			$calledCommand
		);
	}

	public function testRunNowScriptAliasInCronDirectoryWithExtension() {
		$cronScriptPath = implode(DIRECTORY_SEPARATOR, [
			$this->projectDirectory,
			"cron",
			"myScript.php",
		]);
		mkdir(dirname($cronScriptPath), 0775, true);
		file_put_contents($cronScriptPath, "<?php");

		$cronContents = <<<CRON
* * * * * myScript.php
CRON;
		$this->writeCronContents($cronContents);
		$stream = $this->getStream();
		chdir($this->projectDirectory);

		$calledCommand = null;
		Override::setCallback(
			"proc_open",
			function($command)use(&$calledCommand) {
				$calledCommand = $command;
			}
		);
		Override::load("proc_get_status");
		Override::load("proc_close");

		$args = new ArgumentValueList();
		$args->set("once");
		$command = new RunCommand();
		$command->setStream($stream);
		$command->run($args);

		self::assertEquals(
			PHP_BINARY . " " . escapeshellarg($cronScriptPath),
			$calledCommand
		);
	}

	public function testRunNowScriptAliasInCronDirectoryWithoutExtension() {
		$cronScriptPath = implode(DIRECTORY_SEPARATOR, [
			$this->projectDirectory,
			"cron",
			"myScript.php",
		]);
		mkdir(dirname($cronScriptPath), 0775, true);
		file_put_contents($cronScriptPath, "<?php");

		$cronContents = <<<CRON
* * * * * myScript
CRON;
		$this->writeCronContents($cronContents);
		$stream = $this->getStream();
		chdir($this->projectDirectory);

		$calledCommand = null;
		Override::setCallback(
			"proc_open",
			function($command)use(&$calledCommand) {
				$calledCommand = $command;
			}
		);
		Override::load("proc_get_status");
		Override::load("proc_close");

		$args = new ArgumentValueList();
		$args->set("once");
		$command = new RunCommand();
		$command->setStream($stream);
		$command->run($args);

		self::assertEquals(
			PHP_BINARY . " " . escapeshellarg($cronScriptPath),
			$calledCommand
		);
	}

	public function testRunNowScriptWithArguments() {
		$cronContents = <<<CRON
* * * * * /path/to/script/doSomething "a test message" 123
CRON;
		$this->writeCronContents($cronContents);
		$stream = $this->getStream();
		chdir($this->projectDirectory);

		$calledCommand = null;

		Override::setCallback(
			"proc_open",
			function($command)use(&$calledCommand) {
				$calledCommand = $command;
			}
		);
		Override::load("proc_get_status");
		Override::load("proc_close");

		$args = new ArgumentValueList();
		$args->set("once");
		$command = new RunCommand();
		$command->setStream($stream);
		$command->run($args);
		self::assertEquals(
			"/path/to/script/doSomething \"a test message\" 123",
			$calledCommand
		);
	}

	public function testRunNowScriptAndFunction() {
		$cronContents = <<<CRON
* * * * * /path/to/script/doSomething "a test message" 123
* * * * * Gt\Cron\Test\Helper\ExampleClass::doSomething
CRON;
		$this->writeCronContents($cronContents);
		$stream = $this->getStream();
		chdir($this->projectDirectory);

		$calledCommand = null;

		Override::setCallback(
			"proc_open",
			function($command)use(&$calledCommand) {
				$calledCommand = $command;
			}
		);
		Override::load("proc_get_status");
		Override::load("proc_close");

		$args = new ArgumentValueList();
		$args->set("once");
		$command = new RunCommand();
		$command->setStream($stream);
		$command->run($args);
		self::assertEquals(
			"/path/to/script/doSomething \"a test message\" 123",
			$calledCommand
		);
		self::assertEquals(
			1,
			\Gt\Cron\Test\Helper\ExampleClass::$calls
		);
	}

	public function testRunNowScriptNotExists() {
		$cronContents = <<<CRON
* * * * * /path/to/script/that/does/not/exist
CRON;
		$this->writeCronContents($cronContents);
		$stream = $this->getStream();
		chdir($this->projectDirectory);

		$calledCommand = null;

		$args = new ArgumentValueList();
		$args->set("once");
		$command = new RunCommand();
		$command->setStream($stream);

		$command->run($args);

		$this->assertStreamError(
			"Error executing command: /path/to/script/that/does/not/exist",
			$stream
		);
	}

	public function testRunNowFunctionNotExists() {
		$cronContents = <<<CRON
* * * * * Gt\Cron\Test\Nothing::thisDoesNotExist
CRON;
		$this->writeCronContents($cronContents);
		$stream = $this->getStream();
		chdir($this->projectDirectory);

		$calledCommand = null;

		$args = new ArgumentValueList();
		$args->set("once");
		$command = new RunCommand();
		$command->setStream($stream);

		$command->run($args);

		$this->assertStreamError(
			"Error executing function: Gt\\Cron\\Test\\Nothing::thisDoesNotExist",
			$stream
		);
	}
}
