<?php
namespace Gt\Cron\Test;

use Gt\Cron\ResolvedScriptCommand;
use Gt\Cron\ScriptExecutionException;
use Gt\Cron\ScriptOutputMode;
use Gt\Cron\ScriptRunner;
use Gt\Cron\Test\Helper\Override;
use PHPUnit\Framework\TestCase;

class ScriptRunnerTest extends TestCase {
	public function testRunCapturesOutput():void {
		$command = PHP_BINARY . " -r "
			. escapeshellarg("fwrite(STDOUT, 'out');fwrite(STDERR, 'err');");

		$runner = new ScriptRunner(ScriptOutputMode::CAPTURE);
		$result = $runner->run($command);

		self::assertSame("out", $result->stdout);
		self::assertSame("err", $result->stderr);
	}

	/** @runInSeparateProcess */
	public function testRunUsesInjectedCommandResolver():void {
		$resolver = $this->createMock(ResolvedScriptCommand::class);
		$resolver->expects(self::once())
			->method("resolve")
			->with("example")
			->willReturn("resolved-example");

		$calledCommand = null;
		Override::setCallback("proc_open", function($command) use(&$calledCommand) {
			$calledCommand = $command;
			return "EXAMPLE_PROCESS";
		});
		Override::load("proc_get_status");
		Override::setCallback("proc_close", function() {
		});

		$runner = new ScriptRunner(ScriptOutputMode::DISCARD, $resolver);
		$runner->run("example");

		self::assertSame("resolved-example", $calledCommand);
	}

	/** @runInSeparateProcess */
	public function testRunUsesInheritDescriptor():void {
		$descriptor = null;

		Override::setCallback("proc_open", function($command, $descriptorArg) use(&$descriptor) {
			$descriptor = $descriptorArg;
			return "EXAMPLE_PROCESS";
		});
		Override::load("proc_get_status");
		Override::setCallback("proc_close", function() {
		});

		$runner = new ScriptRunner(ScriptOutputMode::INHERIT);
		$runner->run("example");

		self::assertSame(["file", "php://stdout", "w"], $descriptor[1]);
		self::assertSame(["file", "php://stderr", "w"], $descriptor[2]);
	}

	/** @runInSeparateProcess */
	public function testRunThrowsWhenProcessFails():void {
		Override::setCallback("proc_open", function() {
			return false;
		});
		Override::load("proc_get_status");

		$runner = new ScriptRunner();

		self::expectException(ScriptExecutionException::class);
		$runner->run("example");
	}
}
