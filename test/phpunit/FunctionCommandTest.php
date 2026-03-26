<?php
namespace Gt\Cron\Test;

use Gt\Cron\FunctionCommand;
use Gt\Cron\FunctionExecutionException;
use Gt\Cron\Test\Helper\ExampleClass;
use PHPUnit\Framework\TestCase;

class FunctionCommandTest extends TestCase {
	protected function setUp():void {
		ExampleClass::$calls = 0;
		ExampleClass::$message = "";
		ExampleClass::$counter = 0;
	}

	public function testIsCallableReturnsTrueForStaticMethod():void {
		$command = new FunctionCommand();

		self::assertTrue($command->isCallable(
			"Gt\\Cron\\Test\\Helper\\ExampleClass::doSomething"
		));
	}

	public function testIsCallableReturnsFalseForShellCommand():void {
		$command = new FunctionCommand();

		self::assertFalse($command->isCallable("php -v"));
	}

	public function testExecuteCallsFunctionWithArguments():void {
		$command = new FunctionCommand();

		$command->execute(
			'Gt\\Cron\\Test\\Helper\\ExampleClass::doSomething("hello", 5)'
		);

		self::assertSame(1, ExampleClass::$calls);
		self::assertSame("hello", ExampleClass::$message);
		self::assertSame(5, ExampleClass::$counter);
	}

	public function testExecuteThrowsForMissingMethod():void {
		$command = new FunctionCommand();

		self::expectException(FunctionExecutionException::class);
		$command->execute(
			"Gt\\Cron\\Test\\Helper\\ExampleClass::doesNotExist"
		);
	}
}
