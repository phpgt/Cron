<?php
namespace GT\Cron\Test\Helper;

class Override {
	protected static $callbackMap = [];

	public static function setCallback(
		string $functionName,
		callable $callback
	):void {
		self::$callbackMap[$functionName] = $callback;
		self::load($functionName);
	}

	public static function call(
		string $functionName,
		array $arguments = []
	) {
		if(!isset(self::$callbackMap[$functionName])) {
			return null;
		}

		return call_user_func_array(
			self::$callbackMap[$functionName],
			$arguments
		);
	}

	public static function load(string $functionName) {
		require_once(implode(DIRECTORY_SEPARATOR, [
			__DIR__,
			"OverrideFunction",
			"$functionName.php",
		]));
	}
}