<?php
namespace GT\Cron;

readonly class ProjectAutoloader {
	public function __construct(
		private string $namespace,
		private string $classDir,
	) {
	}

	public function setup():void {
		if($this->namespace === "" || $this->classDir === "" || !is_dir($this->classDir)) {
			return;
		}

		spl_autoload_register(fn(string $className) => $this->autoload($className));
	}

	private function autoload(string $className):void {
		if(!str_starts_with($className, $this->namespace . "\\")) {
			return;
		}

		$classNameWithoutNs = substr(
			$className,
			strlen($this->namespace) + 1
		);

		$phpFilePath = $this->classDir;
		if(!str_starts_with($phpFilePath, "/") && !preg_match('/^[A-Za-z]:[\\\\\/]/', $phpFilePath)) {
			$phpFilePath = $this->classDir;
		}

		foreach(explode("\\", $classNameWithoutNs) as $classPart) {
			$phpFilePath .= DIRECTORY_SEPARATOR;
			$phpFilePath .= ucfirst($classPart);
		}

		$phpFilePath .= ".php";
		if(is_file($phpFilePath)) {
			require($phpFilePath);
		}
	}
}
