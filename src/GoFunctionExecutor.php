<?php
namespace GT\Cron;

use Gt\Config\Config;
use Gt\Config\ConfigFactory;
use Gt\ServiceContainer\Container;
use Gt\ServiceContainer\Injector;
use ReflectionClass;

class GoFunctionExecutor {
	private ?Config $config = null;

	public function __construct(
		private readonly string $projectDirectory,
	) {
	}

	public function execute(CronScript $cronScript):void {
		$this->withProjectDirectory(function() use($cronScript):void {
			$this->loadProjectAutoloader();

			$config = $this->loadConfig();
			$this->setupProjectAutoloader($config);

			$container = $this->createContainer($config);
			$container->set(Input::fromQuery($cronScript->getQuery()));

			$injector = new Injector($container);
			$functionName = $this->loadGoFunction($cronScript->getPath());
			$this->withProjectDirectory(
				fn() => $injector->invoke(null, $functionName)
			);
		});
	}

	private function withProjectDirectory(callable $callback):void {
		$originalDirectory = getcwd();
		if(is_dir($this->projectDirectory)) {
			chdir($this->projectDirectory);
		}

		try {
			$callback();
		}
		finally {
			if($originalDirectory !== false && is_dir($originalDirectory)) {
				chdir($originalDirectory);
			}
		}
	}

	private function loadProjectAutoloader():void {
		$autoloadPath = implode(DIRECTORY_SEPARATOR, [
			$this->projectDirectory,
			"vendor",
			"autoload.php",
		]);

		if(is_file($autoloadPath)) {
			require_once $autoloadPath;
		}
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
		$namespace = "GT\\Cron\\Runtime\\File_" . md5($path);
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
		$code = $this->replaceMagicConstants($code, $path);

		eval("namespace $namespace;\n" . $this->getInternalAliasStatements($code) . $code);
		return $functionName;
	}

	private function getInternalAliasStatements(string $code):string {
		$aliasList = [];
		$existingAliasList = $this->getExistingUseAliasList($code);
		foreach([
			...get_declared_classes(),
			...get_declared_interfaces(),
			...get_declared_traits(),
		] as $className) {
			$reflection = new ReflectionClass($className);
			if(!$reflection->isInternal()) {
				continue;
			}

			$shortName = $reflection->getShortName();
			if(isset($existingAliasList[strtolower($shortName)])) {
				continue;
			}

			$aliasList[$shortName] = "use \\" . ltrim($className, "\\") . ";\n";
		}

		ksort($aliasList);
		return implode("", $aliasList);
	}

	/** @return array<string, true> */
	private function getExistingUseAliasList(string $code):array {
		$aliasList = [];
		preg_match_all(
			'/^\s*use\s+(?!function\b|const\b)([^;]+);/mi',
			$code,
			$matches
		);

		foreach($matches[1] as $useStatement) {
			foreach(explode(",", $useStatement) as $usePart) {
				$usePart = trim($usePart);
				if(preg_match('/\s+as\s+(\w+)$/i', $usePart, $aliasMatch)) {
					$alias = $aliasMatch[1];
				}
				else {
					$alias = basename(str_replace("\\", "/", $usePart));
				}

				$aliasList[strtolower($alias)] = true;
			}
		}

		return $aliasList;
	}

	private function replaceMagicConstants(string $code, string $path):string {
		$file = var_export($path, true);
		$directory = var_export(dirname($path), true);
		$output = "";

		foreach(token_get_all("<?php\n" . $code) as $index => $token) {
			if($index === 0) {
				continue;
			}

			if(!is_array($token)) {
				$output .= $token;
				continue;
			}

			$output .= match($token[0]) {
				T_FILE => $file,
				T_DIR => $directory,
				T_NAME_QUALIFIED => "\\" . $token[1],
				default => $token[1],
			};
		}

		return $output;
	}
}
