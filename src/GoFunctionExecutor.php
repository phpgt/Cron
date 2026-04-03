<?php
namespace Gt\Cron;

use Gt\Config\Config;
use Gt\Config\ConfigFactory;
use Gt\ServiceContainer\Container;
use Gt\ServiceContainer\Injector;

class GoFunctionExecutor {
	private ?Config $config = null;

	public function __construct(
		private readonly string $projectDirectory,
	) {
	}

	public function execute(CronScript $cronScript):void {
		$config = $this->loadConfig();
		$this->setupProjectAutoloader($config);

		$container = $this->createContainer($config);
		$container->set(Input::fromQuery($cronScript->getQuery()));

		$injector = new Injector($container);
		$functionName = $this->loadGoFunction($cronScript->getPath());
		$injector->invoke(null, $functionName);
	}

	private function loadConfig():Config {
		if($this->config) {
			return $this->config;
		}

		$defaultConfigPath = implode(DIRECTORY_SEPARATOR, [
			$this->projectDirectory,
			"vendor",
			"phpgt",
			"webengine",
			"config.default.ini",
		]);

		if($this->hasProjectConfigFiles() || is_file($defaultConfigPath)) {
			$this->config = ConfigFactory::createForProject(
				$this->projectDirectory,
				is_file($defaultConfigPath) ? $defaultConfigPath : null,
			);
		}
		else {
			$this->config = new Config();
		}

		return $this->config;
	}

	private function setupProjectAutoloader(Config $config):void {
		$appNamespace = $config->getString("app.namespace");
		$classDir = $config->getString("app.class_dir");
		if(!$appNamespace || !$classDir) {
			return;
		}

		$classDir = $this->resolveProjectPath($classDir);
		(new ProjectAutoloader($appNamespace, $classDir))->setup();
	}

	private function createContainer(Config $config):Container {
		$container = new Container();
		$container->set($config);

		$defaultLoaderClass = "GT\\WebEngine\\Service\\DefaultServiceLoader";
		if(class_exists($defaultLoaderClass)) {
			$container->addLoaderClass(new $defaultLoaderClass($config, $container));
		}

		$customLoaderClass = $this->getProjectServiceLoaderClass($config);
		if($customLoaderClass && class_exists($customLoaderClass)) {
			$container->addLoaderClass(new $customLoaderClass($config, $container));
		}

		return $container;
	}

	private function getProjectServiceLoaderClass(Config $config):?string {
		$appNamespace = trim((string)$config->get("app.namespace"), "\\");
		$serviceLoaderClass = trim((string)$config->get("app.service_loader"), "\\");
		if($appNamespace === "" || $serviceLoaderClass === "") {
			return null;
		}

		return implode("\\", [
			$appNamespace,
			$serviceLoaderClass,
		]);
	}

	private function hasProjectConfigFiles():bool {
		$configFileList = [
			"config.default.ini",
			"config.ini",
			"config.dev.ini",
			"config.deploy.ini",
			"config.production.ini",
		];

		foreach($configFileList as $fileName) {
			if(is_file($this->projectDirectory . DIRECTORY_SEPARATOR . $fileName)) {
				return true;
			}
		}

		return false;
	}

	private function resolveProjectPath(string $path):string {
		if(str_starts_with($path, "/") || preg_match('/^[A-Za-z]:[\\\\\/]/', $path)) {
			return $path;
		}

		return $this->projectDirectory . DIRECTORY_SEPARATOR . $path;
	}

	/** @SuppressWarnings(PHPMD.EvalExpression) */
	private function loadGoFunction(string $path):string {
		$path = realpath($path) ?: $path;
		$namespace = "Gt\\Cron\\Runtime\\File_" . md5($path);
		$functionName = "$namespace\\go";

		if(function_exists($functionName)) {
			return $functionName;
		}

		$code = file_get_contents($path);
		if($code === false) {
			throw new FunctionExecutionException("$path::go");
		}

		$code = preg_replace('/^\xEF\xBB\xBF/', '', $code) ?? $code;
		$code = preg_replace('/^\s*<\?(php)?/i', '', $code, 1) ?? $code;
		$code = preg_replace('/\?>\s*$/', '', $code, 1) ?? $code;

		eval("namespace $namespace;\n" . $code);
		return $functionName;
	}
}
