<?php
namespace Gt\Cron\Test;

use Gt\Cron\Expression;
use Gt\Cron\Job;
use Gt\Cron\JobRepository;
use PHPUnit\Framework\TestCase;

class JobRepositoryTest extends TestCase {
	public function testCreate() {
		$command = uniqid();

		$repository = new JobRepository();
		$job = $repository->create(
			$this->mockExpression(),
			$command
		);

		self::assertEquals($command, $job->getCommand());
	}

	protected function mockExpression():Expression {
		$expression = self::createMock(Expression::class);
		/** @var Expression $expression */
		return $expression;
	}
}
