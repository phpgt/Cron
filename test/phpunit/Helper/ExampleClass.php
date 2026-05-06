<?php
namespace GT\Cron\Test\Helper;

class ExampleClass {
	public static $calls = 0;
	public static $message = "";
	public static $counter = 0;

	public static function doSomething($message = "", $counter = 0) {
		self::$calls ++;
		self::$message .= $message;
		self::$counter += $counter;
	}
}