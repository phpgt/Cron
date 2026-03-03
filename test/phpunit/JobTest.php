<?php
namespace Gt\Cron\Test;

use Cron\CronExpression;
use DateInterval;
use DateTime;
use Gt\Cron\CronException;
use Gt\Cron\FunctionExecutionException;
use Gt\Cron\Job;
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
		catch(CronException $exception) {}

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
		catch(CronException $exception) {}
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
			return null;
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

	protected function mockExpression(int...$wait):CronExpression {
		$runDateCallbackCount = 0;

		$runDate = [];
		$isDue = [];
		foreach($wait as $w) {
			$isDue []= $w < 60;

			$d = new DateTime();
			$d->add(new DateInterval("PT{$w}S"));
			$runDate []= $d;
		}

		$expression = self::createMock(CronExpression::class);
		$expression->method("isDue")
			->willReturnOnConsecutiveCalls(...$isDue);
		$expression->method("getNextRunDate")
			->willReturnCallback(function()
			use(&$runDateCallbackCount, $runDate) {
				$value = $runDate[$runDateCallbackCount];
				$runDateCallbackCount++;
				return $value;
			});

		/** @var CronExpression $expression */
		return $expression;
	}
}
