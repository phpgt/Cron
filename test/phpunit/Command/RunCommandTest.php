<?php
namespace GT\Cron\Test\Command;

use DateTime;
use DateTimeZone;
use Gt\Cli\Argument\ArgumentValueList;
use Gt\Cli\Stream;
use GT\Cron\Cli\RunCommand;
use GT\Cron\Test\Command\CommandTestCase;
use GT\Cron\Test\Helper\ExampleClass;
use GT\Cron\Test\Helper\Override;

/** @runTestsInSeparateProcesses  */
class RunCommandTest extends CommandTestCase {
	public function testCronRunStepDisplaysCurrentTimeFirstWithUtc():void {
		$previousTimezone = date_default_timezone_get();
		date_default_timezone_set("Pacific/Chatham");

		try {
			$stream = $this->getStream();
			$command = new RunCommand();
			$command->setStream($stream);
			$wait = new DateTime(
				"2026-06-22 12:34:56",
				new DateTimeZone("Pacific/Chatham")
			);

			$command->cronRunStep(0, $wait, false, [], "build-index");
			$output = explode(PHP_EOL, trim($this->getFullOutput($stream)));

			self::assertMatchesRegularExpression(
				"/^Current time: \d\d:\d\d:\d\d \(\d\d:\d\d:\d\d UTC\)$/",
				$output[0]
			);
			self::assertSame("Just ran 0 jobs", $output[1]);
			self::assertSame(
				"Next job at: 12:34:56 (23:49:56 UTC) [build-index]",
				$output[2]
			);
		}
		finally {
			date_default_timezone_set($previousTimezone);
		}
	}

	public function testCronRunStepOmitsUtcWhenLocalTimeIsUtc():void {
		$previousTimezone = date_default_timezone_get();
		date_default_timezone_set("UTC");

		try {
			$stream = $this->getStream();
			$command = new RunCommand();
			$command->setStream($stream);
			$wait = new DateTime(
				"2026-06-22 12:34:56",
				new DateTimeZone("UTC")
			);

			$command->cronRunStep(0, $wait, false, [], "build-index");
			$output = explode(PHP_EOL, trim($this->getFullOutput($stream)));

			self::assertMatchesRegularExpression(
				"/^Current time: \d\d:\d\d:\d\d$/",
				$output[0]
			);
			self::assertSame(
				"Next job at: 12:34:56 [build-index]",
				$output[2]
			);
		}
		finally {
			date_default_timezone_set($previousTimezone);
		}
	}

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

	public function testRunNowCronGoScriptLoadsProjectComposerAutoloader():void {
		$outputFile = $this->projectDirectory . "/service-output.txt";

		$this->writeProjectFile("config.default.ini", <<<INI
		[app]
		namespace = TestApp
		class_dir = src
		service_loader = ServiceContainer
		INI);

		$this->writeProjectFile("vendor/autoload.php", <<<'PHP'
		<?php
		spl_autoload_register(function(string $className):void {
			$map = [
				"Framework\\DefaultServiceLoader" => __DIR__ . "/framework/DefaultServiceLoader.php",
			];
			if(isset($map[$className])) {
				require $map[$className];
			}
		});
		PHP);

		$this->writeProjectFile("vendor/framework/DefaultServiceLoader.php", <<<'PHP'
		<?php
		namespace Framework;
		
		class DefaultServiceLoader {
		}
		PHP);

		$this->writeProjectFile("src/ServiceContainer.php", <<<PHP
		<?php
		namespace TestApp;
		
		use Framework\DefaultServiceLoader;
		use TestApp\Service\Recorder;
		
		class ServiceContainer extends DefaultServiceLoader {
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
		use TestApp\Service\Recorder;
		
		function go(Recorder $recorder):void {
			$recorder->write("loaded");
		}
		PHP);

		$cronContents = <<<CRON
		* * * * * cache
		CRON;

		$this->writeCronContents($cronContents);
		$stream = $this->getStream();
		chdir($this->projectDirectory);

		$args = new ArgumentValueList();
		$args->set("once");
		$command = new RunCommand();
		$command->setStream($stream);
		$command->run($args);

		self::assertSame("loaded", file_get_contents($outputFile));
	}

	public function testRunNowCronGoScriptRunsFromProjectDirectory():void {
		$outputFile = $this->projectDirectory . "/cwd-output.txt";
		$originalDirectory = getcwd();

		$this->writeProjectFile("cron/cache.php", <<<PHP
		<?php
		function go():void {
			file_put_contents("cwd-output.txt", getcwd());
		}
		
		chdir(__DIR__);
		PHP);

		$cronContents = <<<CRON
		* * * * * cache
		CRON;

		$this->writeCronContents($cronContents);
		$stream = $this->getStream();
		chdir($this->projectDirectory);

		$args = new ArgumentValueList();
		$args->set("once");
		$command = new RunCommand();
		$command->setStream($stream);
		$command->run($args);

		self::assertSame($this->projectDirectory, file_get_contents($outputFile));
		self::assertSame($this->projectDirectory, getcwd());

		if($originalDirectory !== false) {
			chdir($originalDirectory);
		}
	}

	public function testRunNowCronGoScriptMagicDirPointsAtCronScript():void {
		$outputFile = $this->projectDirectory . "/dir-output.txt";

		$this->writeProjectFile("cron/cache.php", <<<'PHP'
		<?php
		function go():void {
			file_put_contents("dir-output.txt", __DIR__);
		}
		PHP);

		$cronContents = <<<CRON
		* * * * * cache
		CRON;

		$this->writeCronContents($cronContents);
		$stream = $this->getStream();
		chdir($this->projectDirectory);

		$args = new ArgumentValueList();
		$args->set("once");
		$command = new RunCommand();
		$command->setStream($stream);
		$command->run($args);

		self::assertSame(
			$this->projectDirectory . "/cron",
			file_get_contents($outputFile)
		);
	}

	public function testRunNowCronGoScriptCanUseUnqualifiedInternalClasses():void {
		$outputFile = $this->projectDirectory . "/generator-output.txt";

		$this->writeProjectFile("cron/cache.php", <<<PHP
		<?php
		function go():void {
			file_put_contents(
				"generator-output.txt",
				implode(",", iterator_to_array(getValues()))
			);
		}
		
		function getValues():Generator {
			yield "a";
			yield "b";
		}
		PHP);

		$cronContents = <<<CRON
		* * * * * cache
		CRON;

		$this->writeCronContents($cronContents);
		$stream = $this->getStream();
		chdir($this->projectDirectory);

		$args = new ArgumentValueList();
		$args->set("once");
		$command = new RunCommand();
		$command->setStream($stream);
		$command->run($args);

		self::assertSame("a,b", file_get_contents($outputFile));
	}

	public function testRunNowCronGoScriptCanReceiveConfig():void {
		$outputFile = $this->projectDirectory . "/config-output.txt";
		$this->writeProjectFile("config.ini", <<<'INI'
		[app]
		namespace = TestApp
		
		[github]
		access_token = abc123
		INI);

		$this->writeProjectFile("cron/cache.php", <<<'PHP'
		<?php
		use Gt\Config\Config;
		
		function go(Config $config):void {
			$githubConfig = $config->getSection("github");
			file_put_contents("config-output.txt", $githubConfig->getString("access_token"));
		}
		PHP);

		$cronContents = <<<CRON
		* * * * * cache
		CRON;

		$this->writeCronContents($cronContents);
		$stream = $this->getStream();
		chdir($this->projectDirectory);

		$args = new ArgumentValueList();
		$args->set("once");
		$command = new RunCommand();
		$command->setStream($stream);
		$command->run($args);

		self::assertSame("abc123", file_get_contents($outputFile));
	}

	public function testRunNowCronGoScriptCanUseQualifiedClassNames():void {
		$outputFile = $this->projectDirectory . "/qualified-output.txt";
		$this->writeProjectFile("config.ini", <<<'INI'
		[app]
		namespace = TestApp
		class_dir = src
		INI);

		$this->writeProjectFile("src/Service/Recorder.php", <<<PHP
		<?php
		namespace TestApp\Service;
		
		class Recorder {
			public function write(string \$value):void {
				file_put_contents("$outputFile", \$value);
			}
		}
		PHP);

		$this->writeProjectFile("cron/cache.php", <<<'PHP'
		<?php
		function go():void {
			$recorder = new TestApp\Service\Recorder();
			$recorder->write("qualified");
		}
		PHP);

		$cronContents = <<<CRON
		* * * * * cache
		CRON;

		$this->writeCronContents($cronContents);
		$stream = $this->getStream();
		chdir($this->projectDirectory);

		$args = new ArgumentValueList();
		$args->set("once");
		$command = new RunCommand();
		$command->setStream($stream);
		$command->run($args);

		self::assertSame("qualified", file_get_contents($outputFile));
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
			"[ExampleClass::doSomething]",
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
