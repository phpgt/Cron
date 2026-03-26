<?php
namespace Gt\Cron\Test;

use DateInterval;
use DateTime;
use Gt\Cron\CronException;
use Gt\Cron\Expression;
use Gt\Cron\FunctionCommand;
use Gt\Cron\FunctionExecutionException;
use Gt\Cron\Job;
use Gt\Cron\ScriptResult;
use Gt\Cron\ScriptOutputMode;
use Gt\Cron\ScriptRunner;
use Gt\Cron\ScriptExecutionException;
use Gt\Cron\Test\Helper\Override;
use PHPUnit\Framework\TestCase;

class JobTest extends TestCase {
	public function testIsDueWhenExpressionDue() {
		$expression = $this->mockExpression(0);

		$job = new Job(
			$expression,
			"example"
		);
		self::assertTrue($job->isDue());
	}

	public function testIsDueWhenExpressionDueSuppliedDateTime() {
		$expression = $this->mockExpression(0);

		$job = new Job(
			$expression,
			"example"
		);
		self::assertTrue($job->isDue(new DateTime()));
	}

	public function testIsDueWhenExpressionNotDue() {
		$expression = $this->mockExpression(100);

		$job = new Job(
			$expression,
			"example"
		);
		self::assertFalse($job->isDue());
	}

	public function testGetNextRunDate() {
		$wait = 600;
		$expression = $this->mockExpression($wait);
		$job = new Job(
			$expression,
			"example"
		);

		$expectedRunDate = new DateTime();
		$expectedRunDate->add(
			new DateInterval("PT{$wait}S")
		);

		self::assertDateTimeEquals(
			$expectedRunDate,
			$job->getNextRunDate()
		);
	}

	public function testGetNextRunDateWithSuppliedDate() {
		$wait = 900;
		$expression = $this->mockExpression($wait);
		$job = new Job(
			$expression,
			"example"
		);

		$expectedRunDate = new DateTime();
		$expectedRunDate->add(
			new DateInterval("PT{$wait}S")
		);
		$now = new DateTime();
		$now->add(new DateInterval("PT150S"));

		self::assertDateTimeEquals(
			$expectedRunDate,
			$job->getNextRunDate()
		);
	}

	public function testGetCommand() {
		$job1 = new Job(
			$this->mockExpression(),
			$id1 = uniqid()
		);
		$job2 = new Job(
			$this->mockExpression(),
			$id2 = uniqid()
		);

		self::assertEquals($id2, $job2->getCommand());
		self::assertEquals($id1, $job1->getCommand());
	}

	public function testRunHasRun() {
		$job = new Job(
			$this->mockExpression(),
			"example"
		);

		self::assertFalse($job->hasRun());
		try {
			$job->run();
		}
		catch(CronException $exception) {
			self::assertInstanceOf(CronException::class, $exception);
		}

		self::assertTrue($job->hasRun());
	}

	public function testResetRunFlag() {
		$job = new Job(
			$this->mockExpression(),
			"example"
		);

		try {
			$job->run();
		}
		catch(CronException $exception) {
			self::assertInstanceOf(CronException::class, $exception);
		}
		$job->resetRunFlag();
		self::assertFalse($job->hasRun());
	}

	/** @runInSeparateProcess */
	public function testRunScriptClosesProc() {
		$job = new Job(
			$this->mockExpression(),
			"example"
		);

		$procCalls = [
			"proc_open" => [],
			"proc_close" => [],
		];
		Override::setCallback("proc_open", function($command)use(&$procCalls) {
			$procCalls["proc_open"] []= $command;
			return "EXAMPLE_PROCESS";
		});
		Override::load("proc_get_status");
		Override::setCallback("proc_close", function()use(&$procCalls) {
			$procCalls["proc_close"] []= time();
		});

		$job->run();
		self::assertCount(1, $procCalls["proc_open"]);
		self::assertCount(1, $procCalls["proc_close"]);
	}

	public function testRunScriptFail() {
		$job = new Job(
			$this->mockExpression(),
			"example"
		);

		$procCalls = [
			"proc_open" => [],
			"proc_close" => [],
		];
		Override::setCallback("proc_open", function($command)use(&$procCalls) {
			$procCalls["proc_open"] []= $command;
			return false;
		});
		Override::load("proc_get_status");
		Override::setCallback("proc_close", function()use(&$procCalls) {
			$procCalls["proc_close"] []= time();
		});

		self::expectException(ScriptExecutionException::class);
		$job->run();
		self::assertCount(1, $procCalls["proc_open"]);
		self::assertCount(0, $procCalls["proc_close"]);
	}

	public function testRunUsesInjectedDependencies():void {
		$functionCommand = $this->createMock(FunctionCommand::class);
		$functionCommand->expects(self::once())
			->method("isCallable")
			->with("example")
			->willReturn(false);
		$functionCommand->expects(self::never())
			->method("execute");

		$scriptRunner = $this->createMock(ScriptRunner::class);
		$scriptRunner->expects(self::once())
			->method("run")
			->with("example")
			->willReturn(new ScriptResult("out", "err"));

		$job = new Job(
			$this->mockExpression(),
			"example",
			ScriptOutputMode::CAPTURE,
			$functionCommand,
			$scriptRunner
		);

		$job->run();

		self::assertSame("out", $job->getStdout());
		self::assertSame("err", $job->getStderr());
	}

	public function testRunFunctionNotExists():void {
		$command = "Gt\\Cron\\Test\\Nothing::thisDoesNotExist";
		$job = new Job(
			$this->mockExpression(),
			$command
		);

		self::expectException(FunctionExecutionException::class);
		self::expectExceptionMessage($command);
		$job->run();
	}

	/** @runInSeparateProcess */
	public function testRunScriptCaptureOutput():void {
		$command = PHP_BINARY . " -r "
			. escapeshellarg("fwrite(STDOUT, 'out');fwrite(STDERR, 'err');");

		$job = new Job(
			$this->mockExpression(),
			$command,
			ScriptOutputMode::CAPTURE
		);

		$job->run();

		self::assertSame("out", $job->getStdout());
		self::assertSame("err", $job->getStderr());
	}

	/** @runInSeparateProcess */
	public function testRunScriptInheritOutputDescriptor():void {
		$job = new Job(
			$this->mockExpression(),
			"example",
			ScriptOutputMode::INHERIT
		);

		$descriptor = null;
		Override::setCallback("proc_open", function($command, $descriptorArg) use(&$descriptor) {
			$descriptor = $descriptorArg;
			return "EXAMPLE_PROCESS";
		});
		Override::load("proc_get_status");
		Override::setCallback("proc_close", function() {
		});

		$job->run();

		self::assertSame(["file", "php://stdout", "w"], $descriptor[1]);
		self::assertSame(["file", "php://stderr", "w"], $descriptor[2]);
	}

	public static function assertDateTimeEquals(
		DateTime $expected,
		DateTime $actual,
		string $message = ""
	) {
		self::assertEquals(
			$expected->format("Y-m-d H:i:s"),
			$actual->format("Y-m-d H:i:s"),
			$message
		);
	}

	protected function mockExpression(int...$wait):Expression {
		$runDateCallbackCount = 0;

		$runDate = [];
		$isDue = [];
		foreach($wait as $w) {
			$isDue []= $w < 60;

			$d = new DateTime();
			$d->add(new DateInterval("PT{$w}S"));
			$runDate []= $d;
		}

		$expression = self::createMock(Expression::class);
		$expression->method("isDue")
			->willReturnOnConsecutiveCalls(...$isDue);
		$expression->method("getNextRunDate")
			->willReturnCallback(function()
			use(&$runDateCallbackCount, $runDate) {
				$value = $runDate[$runDateCallbackCount];
				$runDateCallbackCount++;
				return $value;
			});

		/** @var Expression $expression */
		return $expression;
	}
}
