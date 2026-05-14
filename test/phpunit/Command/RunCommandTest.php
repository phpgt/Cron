<?php
namespace GT\Cron\Test\Command;

use Gt\Cli\Argument\ArgumentValueList;
use Gt\Cli\Stream;
use GT\Cron\Cli\RunCommand;
use GT\Cron\Test\Command\CommandTestCase;
use GT\Cron\Test\Helper\ExampleClass;
use GT\Cron\Test\Helper\Override;

/** @runTestsInSeparateProcesses  */
class RunCommandTest extends CommandTestCase {
	/** @dataProvider cronGoAliasData */
	public function testRunNowCronGoScriptAlias(string $command):void {
		$outputFile = $this->projectDirectory . "/cron-go-output.txt";
		$this->writeProjectFile("cron/cache.php", <<<PHP
<?php
use Gt\Input\Input;

function go(Input \$input):void {
	file_put_contents("$outputFile", \$input->getString("type") . ":" . \$input->getString("mode"));
}
PHP);

		$cronContents = <<<CRON
* * * * * $command
CRON;
		$this->writeCronContents($cronContents);
		$stream = $this->getStream();
		chdir($this->projectDirectory);

		$args = new ArgumentValueList();
		$args->set("once");
		$commandInstance = new RunCommand();
		$commandInstance->setStream($stream);
		$commandInstance->run($args);

		self::assertSame("db:daily", file_get_contents($outputFile));
	}

	public static function cronGoAliasData():array {
		return [
			["cache?type=db&mode=daily"],
			["/cache?type=db&mode=daily"],
			["cache.php?type=db&mode=daily"],
			["cron/cache?type=db&mode=daily"],
			["/cron/cache?type=db&mode=daily"],
		];
	}

	public function testRunNowCronGoScriptUsesProjectServiceContainer():void {
		$outputFile = $this->projectDirectory . "/service-output.txt";
		$this->writeProjectFile("config.default.ini", <<<INI
[app]
namespace = TestApp
class_dir = src
service_loader = ServiceContainer
INI);

		$this->writeProjectFile("src/ServiceContainer.php", <<<PHP
<?php
namespace TestApp;

use Gt\Config\Config;
use Gt\ServiceContainer\Container;
use TestApp\Service\Recorder;

class ServiceContainer {
	public function __construct(
		private readonly Config \$config,
		private readonly Container \$container,
	) {
	}

	public function loadRecorder():Recorder {
		return new Recorder("$outputFile");
	}
}
PHP);

		$this->writeProjectFile("src/Service/Recorder.php", <<<'PHP'
<?php
namespace TestApp\Service;

class Recorder {
	public function __construct(
		private readonly string $path,
	) {
	}

	public function write(string $value):void {
		file_put_contents($this->path, $value);
	}
}
PHP);

		$this->writeProjectFile("cron/cache.php", <<<'PHP'
<?php
use Gt\Input\Input;
use TestApp\Service\Recorder;

function go(Input $input, Recorder $recorder):void {
	$recorder->write($input->getString("type"));
}
PHP);

		$cronContents = <<<CRON
* * * * * cache?type=db
CRON;
		$this->writeCronContents($cronContents);
		$stream = $this->getStream();
		chdir($this->projectDirectory);

		$args = new ArgumentValueList();
		$args->set("once");
		$command = new RunCommand();
		$command->setStream($stream);
		$command->run($args);

		self::assertSame("db", file_get_contents($outputFile));
	}

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

	public function testExplain():void {
		$cronContents = <<<CRON
0 0 * * FRI /backup
0 * * * * /clean-cache
05 01 * * SUN#1 /first-sunday
CRON;
		$this->writeCronContents($cronContents);
		$stream = $this->getStream();
		chdir($this->projectDirectory);

		$args = new ArgumentValueList();
		$args->set("explain");
		$command = new RunCommand();
		$command->setStream($stream);
		$command->run($args);

		$output = $this->getFullOutput($stream);
		self::assertStringContainsString(
			"0 0 * * FRI /backup\t\tAt 12:00 AM, only on Friday",
			$output
		);
		self::assertStringContainsString(
			"0 * * * * /clean-cache\t\tEvery hour",
			$output
		);
		self::assertStringContainsString(
			"05 01 * * SUN#1 /first-sunday\t\tAt 01:05 AM, on the first Sunday of the month",
			$output
		);
	}

	public function testRunNowFunction() {
		$cronContents = <<<CRON
* * * * * \GT\Cron\Test\Helper\ExampleClass::doSomething
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

		$output = $this->getFullOutput($stream);
		self::assertStringContainsString(
			"Just ran 1 job (ExampleClass::doSomething)",
			$output
		);
		self::assertStringContainsString(
			"Next job at:",
			$output
		);
		self::assertStringContainsString(
			"(ExampleClass::doSomething)",
			$output
		);
	}

	public function testRunNowFunctionWithArguments() {
		$cronContents = <<<CRON
* * * * * \GT\Cron\Test\Helper\ExampleClass::doSomething("a test message", 123)
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
			\GT\Cron\Test\Helper\ExampleClass::$message
		);
		self::assertEquals(
			0,
			\GT\Cron\Test\Helper\ExampleClass::$counter
		);
		$command->run($args);
		self::assertEquals(
			"a test message",
			\GT\Cron\Test\Helper\ExampleClass::$message
		);
		self::assertEquals(
			123,
			\GT\Cron\Test\Helper\ExampleClass::$counter
		);
	}

	public function testRunNowFunctionNoSlash() {
		$cronContents = <<<CRON
* * * * * GT\Cron\Test\Helper\ExampleClass::doSomething
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
			\GT\Cron\Test\Helper\ExampleClass::$calls
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
* * * * * GT\Cron\Test\Helper\ExampleClass::doSomething
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
			\GT\Cron\Test\Helper\ExampleClass::$calls
		);

		$output = $this->getFullOutput($stream);
		self::assertStringContainsString(
			"Just ran 2 jobs (doSomething, ExampleClass::doSomething)",
			$output
		);
		self::assertStringContainsString(
			"Next job at:",
			$output
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
* * * * * GT\Cron\Test\Nothing::thisDoesNotExist
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
			"Error executing function: GT\\Cron\\Test\\Nothing::thisDoesNotExist",
			$stream
		);
	}

	protected function getFullOutput(Stream $stream):string {
		$out = $stream->getOutStream();
		$out->rewind();
		return $out->fread(10000);
	}
}
