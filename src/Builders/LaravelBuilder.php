<?php

declare(strict_types=1);

namespace Roldante05\ScaffoldingFactory\Builders;

use Roldante05\ScaffoldingFactory\DTOs\ProjectOptions;
use Roldante05\ScaffoldingFactory\DTOs\LaravelOptions;
use Roldante05\ScaffoldingFactory\Helpers\StubProcessor;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\ConsoleSectionOutput;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;

class LaravelBuilder implements BuilderInterface
{
    /** @var array<string> */
    private array $allowedDatabases = ['mysql', 'mariadb', 'pgsql', 'sqlite', 'sqlsrv', 'none'];
    /** @var array<string> */
    private array $allowedKits = ['Breeze', 'Jetstream', 'Official Starter Kit (2026)', 'None'];
    /** @var array<string> */
    private array $allowedStacks = ['livewire', 'vue', 'react', 'inertia', 'blade', 'none'];

    public function build(string $projectName, ProjectOptions $options, OutputInterface $output): int
    {
        /** @var LaravelOptions $options */
        // Validate and sanitize inputs FIRST
        $sanitizedOptions = $this->sanitizeLaravelOptions($options);
        $sanitizedProjectName = $this->sanitizeProjectName($projectName);

        $projectPath = getcwd() . DIRECTORY_SEPARATOR . $sanitizedProjectName;

        try {
            // 0️⃣ Preparar secciones de salida (historial y activo)
            [$historySection, $activeSection] = $this->getOutputSections($output);

            // 1️⃣ Scaffold del proyecto Laravel
            $this->scaffoldProject($sanitizedProjectName, $output);
            $historySection->writeln('   <fg name="green">✔</> Laravel project created');

            // 2️⃣ Bootstrap JS
            $this->ensureBootstrap($projectPath);

            // 3️⃣ Sail
            $this->configureSail($projectPath, $sanitizedOptions, $historySection, $activeSection);

            // 4️⃣ Auth Kit
            $this->installAuthKit($projectPath, $sanitizedOptions, $historySection, $activeSection);

            // 5️⃣ Boost (opcional)
            $this->maybeInstallBoost($projectPath, $sanitizedOptions, $historySection, $activeSection);

            // 6️⃣ Base de datos
            $this->setDatabase($projectPath, $sanitizedOptions->database, $historySection, $activeSection);

            // 7️⃣ Script de instalación
            $this->generateInstallationScript($projectPath, $sanitizedOptions, $historySection, $activeSection);

            // 8️⃣ Permisos de node_modules
            $this->fixNodeModulesPermissions($projectPath, $activeSection);

            // 9️⃣ Mensaje final
            $this->showFinalInfo($activeSection, $sanitizedProjectName);

            return 0;
        } catch (\Exception $e) {
            $output->writeln('<error>❌ Error: ' . $e->getMessage() . '</error>');
            return 1;
        }
    }

    /**
     * Sanitiza y valida las opciones de Laravel.
     *
     * @param LaravelOptions $options Las opciones a sanitizar
     * @return LaravelOptions Las opciones sanitizadas
     * @throws \InvalidArgumentException Si las opciones son inválidas
     */
    private function sanitizeLaravelOptions(LaravelOptions $options): LaravelOptions
    {
        // Validar y sanitizar la base de datos
        $database = strtolower($options->database);
        if (!in_array($database, $this->allowedDatabases, true)) {
            throw new \InvalidArgumentException("Database '{$options->database}' is not allowed. Allowed: " . implode(', ', $this->allowedDatabases));
        }

        // Validar y sanitizar el kit
        $kit = $options->kit;
        if (!in_array($kit, $this->allowedKits, true)) {
            throw new \InvalidArgumentException("Kit '{$options->kit}' is not allowed. Allowed: " . implode(', ', $this->allowedKits));
        }

        // Validar y sanitizar el stack
        $stack = strtolower($options->stack);
        if (!in_array($stack, $this->allowedStacks, true)) {
            throw new \InvalidArgumentException("Stack '{$options->stack}' is not allowed. Allowed: " . implode(', ', $this->allowedStacks));
        }

        // Validar y sanitizar withBoost (debe ser booleano)
        $withBoost = filter_var($options->withBoost, FILTER_VALIDATE_BOOL);
        if ($withBoost === null && $options->withBoost !== false && $options->withBoost !== '') {
            throw new \InvalidArgumentException("withBoost must be a boolean value");
        }

        // Validar y sanitizar withTeams (debe ser booleano)
        $withTeams = filter_var($options->withTeams, FILTER_VALIDATE_BOOL);
        if ($withTeams === null && $options->withTeams !== false && $options->withTeams !== '') {
            throw new \InvalidArgumentException("withTeams must be a boolean value");
        }

        // Retornar un nuevo objeto con valores sanitizados
        return new LaravelOptions(
            projectName: $options->projectName, // Se sanitiza por separado
            wantKit: $options->wantKit,
            kit: $kit,
            stack: $stack,
            withTeams: $withTeams,
            database: $database,
            withBoost: $withBoost
        );
    }

    /**
     * Sanitiza el nombre del proyecto para prevenir path traversal.
     *
     * @param string $projectName El nombre del proyecto a sanitizar
     * @return string El nombre del proyecto sanitizado
     * @throws \InvalidArgumentException Si el nombre del proyecto es inválido
     */
    private function sanitizeProjectName(string $projectName): string
    {
        // Reject any path separators to prevent directory traversal
        if (strpos($projectName, '/') !== false || strpos($projectName, '\\') !== false) {
            throw new \InvalidArgumentException("Project name '{$projectName}' contains invalid path separators.");
        }

        // Also reject if the name is empty, '.', or '..'
        if (empty($projectName) || $projectName === '.' || $projectName === '..') {
            throw new \InvalidArgumentException("Project name '{$projectName}' is invalid.");
        }

        // Verificar que solo contenga caracteres seguros (letras, números, guiones, guiones bajos)
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $projectName)) {
            throw new \InvalidArgumentException("Project name '{$projectName}' contains invalid characters. Only letters, numbers, hyphens, and underscores are allowed.");
        }

        // Limitar la longitud para prevenir DoS
        if (strlen($projectName) > 50) {
            throw new \InvalidArgumentException("Project name is too long (maximum 50 characters)");
        }

        return $projectName;
    }

    /**
     * Prepara las secciones de salida: historial (historial acumulado) y activo (temporal).
     *
     * @param OutputInterface $output Salida de consola que puede producir secciones.
     * @return array{0: OutputInterface, 1: OutputInterface} [historySection, activeSection]
     */
    private function getOutputSections(OutputInterface $output): array
    {
        if ($output instanceof ConsoleOutputInterface) {
            return [$output->section(), $output->section()]; // [history, active]
        }

        return [$output, $output]; // fallback: ambos apuntan al mismo output
    }

    /**
     * Ejecuta un proceso en el directorio especificado con manejo de salida.
     *
     * @param array $command El comando y sus argumentos como array
     * @param string $workingDirectory El directorio donde ejecutar el comando
     * @param OutputInterface $output La salida donde se mostrará el progreso
     * @param bool $hideOutput Si se debe ocultar la salida del comando
     * @param bool $allowFailure Si se debe permitir que el comando falle sin lanzar excepción
     * @throws \RuntimeException Si el proceso falla y $allowPayment es false
     */
    private function runProcess(array $command, string $workingDirectory, OutputInterface $output, bool $hideOutput = false, bool $allowFailure = false): void
    {
        $process = new Process($command);
        $process->setWorkingDirectory($workingDirectory);
        $process->setTimeout(null);

        $process->run(function ($type, $line) use ($output, $hideOutput): void {
            if ($hideOutput || trim($line) === '') {
                return;
            }

            $output->write($line);
        });

        if (!$process->isSuccessful() && !$allowFailure) {
            throw new \RuntimeException(
                sprintf('Process failed with exit code %d.' . PHP_EOL .
                        'Output: %s' . PHP_EOL .
                        'Error: %s',
                        $process->getExitCode(),
                        $process->getOutput(),
                        $process->getErrorOutput())
            );
        }
    }

    /**
     * Ejecuta la creación del proyecto Laravel usando el instalador oficial.
     *
     * @param string $projectName Nombre del proyecto a crear (ya sanitizado).
     * @param OutputInterface $output Salida para mostrar progreso.
     */
    private function scaffoldProject(string $projectName, OutputInterface $output): void
    {
        $output->writeln(' • Scaffolding project');
        $this->createLaravelProjectWithInstaller($projectName, $output);
    }

    /**
     * Asegura que exista el archivo resources/js/bootstrap.js y su contenido básico.
     *
     * @param string $projectPath Ruta raíz del proyecto.
     */
    private function ensureBootstrap(string $projectPath): void
    {
        $this->ensureBootstrapJs($projectPath);
    }

    /**
     * Configura Laravel Sail según las opciones.
     *
     * @param string $projectPath Ruta raíz del proyecto.
     * @param LaravelOptions $options Opciones de Laravel (ya sanitizadas).
     * @param OutputInterface $historySection Sección de historial para mensajes de éxito.
     * @param OutputInterface $activeSection Sección activa para logs en tiempo real.
     */
    private function configureSail(string $projectPath, LaravelOptions $options, OutputInterface $historySection, OutputInterface $activeSection): void
    {
        $activeSection->writeln('   • Configuring Laravel Sail');
        $this->runProcess(['composer', 'require', 'laravel/sail', '--dev', '--no-interaction', '--quiet'], $projectPath, $activeSection, false, true);
        $this->installSail($projectPath, $options, $activeSection, $activeSection);
        $activeSection->clear();
        $historySection->writeln('   <fg name="green">✔</> Laravel Sail configured');
    }

    /**
     * Instala el kit de autenticación seleccionado (Breeze, Jetstream o el kit oficial).
     *
     * @param string $projectPath Raíz del proyecto.
     * @param LaravelOptions $options Opciones de Laravel (ya sanitizadas).
     * @param OutputInterface $historySection Sección de historial para mensajes de éxito.
     * @param OutputInterface $activeSection Sección activa para logs en tiempo real.
     */
    private function installAuthKit(string $projectPath, LaravelOptions $options, OutputInterface $historySection, OutputInterface $activeSection): void
    {
        $kit = $options->kit;

        if ($kit === 'Breeze' || $kit === 'Official Starter Kit (2026)') {
            $this->installBreezeOrOfficialKit($projectPath, $options, $historySection, $activeSection);
        } elseif ($kit === 'Jetstream') {
            $this->installJetstreamKit($projectPath, $options, $historySection, $activeSection);
        }

        if ($kit === 'Breeze' || $kit === 'Official Starter Kit (2026)' || $kit === 'Jetstream') {
            $this->fixJsDependencies($projectPath, $options->stack, $historySection);
        }
    }

    /**
     * Instala el kit de autenticación Breeze o el Kit Oficial.
     *
     * @param string $projectPath Raíz del proyecto.
     * @param LaravelOptions $options Opciones de Laravel (ya sanitizadas).
     * @param OutputInterface $historySection Sección de historial para mensajes de éxito.
     * @param OutputInterface $activeSection Sección activa para logs en tiempo real.
     */
    private function installBreezeOrOfficialKit(string $projectPath, LaravelOptions $options, OutputInterface $historySection, OutputInterface $activeSection): void
    {
        $kitName = $options->kit === 'Breeze' ? 'Laravel Breeze' : 'Official Starter Kit (2026)';
        $activeSection->writeln("   • Ensuring {$kitName} is installed");

        $breezePath = $projectPath . '/vendor/laravel/breeze';
        if (!is_dir($breezePath)) {
            $this->runProcess(['composer', 'require', 'laravel/breeze', '--dev', '--no-interaction', '--quiet'], $projectPath, $activeSection, false, true);
            $breezeArgs = [$options->stack]; // Ya sanitizado

            // Set env to avoid NPM ERESOLVE issues during artisan's internal npm install
            $process = new Process(array_merge(['php', 'artisan', 'breeze:install'], $breezeArgs), $projectPath, ['NPM_CONFIG_LEGACY_PEER_DEPS' => 'true']);
            $process->setTimeout(null);
            $process->run(function ($type, $line) use ($activeSection) {
                if (!$activeSection instanceof ConsoleSectionOutput) {
                    $activeSection->write($line);
                }
            });

            if (!$process->isSuccessful()) {
                $historySection->writeln('   <fg name="yellow">⚠</> Breeze install finished with some warnings (likely NPM)');
            } else {
                $activeSection->clear();
                $historySection->writeln('   <fg name="green">✔</> ' . $kitName . ' installed');
            }
        } else {
            $activeSection->clear();
            $historySection->writeln('   <fg name="green">✔</> ' . $kitName . ' already installed');
        }
    }

    /**
     * Instala el kit de autenticación Jetstream.
     *
     * @param string $projectPath Raíz del proyecto.
     * @param LaravelOptions $options Opciones de Laravel (ya sanitizadas).
     * @param OutputInterface $historySection Sección de historial para mensajes de éxito.
     * @param OutputInterface $activeSection Sección activa para logs en tiempo real.
     */
    private function installJetstreamKit(string $projectPath, LaravelOptions $options, OutputInterface $historySection, OutputInterface $activeSection): void
    {
        $activeSection->writeln('   • Ensuring Laravel Jetstream is installed');

        $jetstreamPath = $projectPath . '/vendor/laravel/jetstream';
        if (!is_dir($jetstreamPath)) {
            $this->runProcess(['composer', 'require', 'laravel/jetstream', '--no-interaction', '--quiet'], $projectPath, $activeSection, false, true);

            // Set env to avoid NPM ERESOLVE issues during artisan's internal npm install
            $process = new Process(['php', 'artisan', 'jetstream:install', $options->stack, '--no-interaction'], $projectPath, ['NPM_CONFIG_LEGACY_PEER_DEPS' => 'true']);
            $process->setTimeout(null);
            $process->run(function ($type, $line) use ($activeSection) {
                if (!$activeSection instanceof ConsoleSectionOutput) {
                    $activeSection->write($line);
                }
            });

            if (!$process->isSuccessful()) {
                $historySection->writeln('   <fg name="yellow">⚠</> Jetstream install finished with some warnings (likely NPM)');
            } else {
                $activeSection->clear();
                $historySection->writeln('   <fg name="green">✔</> Laravel Jetstream installed');
            }
        } else {
            $activeSection->clear();
            $historySection->writeln('   <fg name="green">✔</> Laravel Jetstream already installed');
        }
    }

    /**
     * Instala Laravel Boost si está habilitado.
     *
     * @param string $projectPath Ruta raíz del proyecto.
     * @param LaravelOptions $options Opciones de Laravel (ya sanitizadas).
     * @param OutputInterface $historySection Sección de historial para mensajes de éxito.
     * @param OutputInterface $activeSection Sección activa para logs en tiempo real.
     */
    private function maybeInstallBoost(string $projectPath, LaravelOptions $options, OutputInterface $historySection, OutputInterface $activeSection): void
    {
        if (!$options->withBoost) {
            return;
        }

        $activeSection->writeln('   • Installing Laravel Boost');
        $this->installBoost($projectPath, $activeSection, $activeSection);
        $activeSection->clear();
        $historySection->writeln('   <fg name="green">✔</> Laravel Boost installed');
    }

    /**
     * Establece la conexión de base de datos en el archivo .env.
     *
     * @param string $projectPath Ruta raíz del proyecto.
     * @param string $database Tipo de base de datos (ej. 'mysql', 'sqlite', ya sanitizado).
     * @param OutputInterface $historySection Sección de historial para mensajes de éxito.
     * @param OutputInterface $activeSection Sección activa para logs en tiempo real.
     */
    private function setDatabase(string $projectPath, string $database, OutputInterface $historySection, OutputInterface $activeSection): void
    {
        $activeSection->writeln('   • Setting database connection');
        $this->setDatabaseConnection($projectPath, $database, $activeSection);
        $activeSection->clear();
        $historySection->writeln('   <fg name="green">✔</> Database connection set to ' . $database);
    }

    /**
     * Genera el script de instalación (install.sh) a partir del stub.
     *
     * @param string $projectPath Ruta raíz del proyecto.
     * @param LaravelOptions $options Opciones de Laravel (ya sanitizadas).
     * @param OutputInterface $historySection Sección de historial para mensajes de éxito.
     * @param OutputInterface $activeSection Sección activa para logs en tiempo real.
     */
    private function generateInstallationScript(string $projectPath, LaravelOptions $options, OutputInterface $historySection, OutputInterface $activeSection): void
    {
        $activeSection->writeln('   • Generating installation script');
        $this->generateInstallScript($projectPath, $options, $activeSection);
        $activeSection->clear();
        $historySection->writeln('   <fg name="green">✔</> Installation script generated');
    }

    /**
     * Corrige los permisos de la carpeta node_modules si existe.
     *
     * @param string $projectPath Ruta raíz del proyecto.
     * @param OutputInterface $activeSection Sección activa para logs en tiempo real (se usa para posibles mensajes, aunque actualmente no se muestra nada).
     */
    private function fixNodeModulesPermissions(string $projectPath, OutputInterface $activeSection): void
    {
        $nodeModulesPath = $projectPath . DIRECTORY_SEPARATOR . 'node_modules';
        if (is_dir($nodeModulesPath)) {
            // Usar permisos más seguros: propietario puede leer/escribir/ejecutar, grupo y otros solo leer y ejecutar
            $this->runProcess(['chmod', '-R', '755', 'node_modules'], $projectPath, $activeSection);
        }
    }

    /**
     * Muestra la información final después de la generación exitosa.
     *
     * @param OutputInterface $activeSection Sección activa para imprimir los mensajes finales.
     * @param string $projectName Nombre del proyecto generado (ya sanitizado).
     */
    private function showFinalInfo(OutputInterface $activeSection, string $projectName): void
    {
        $activeSection->clear();
        $activeSection->writeln('');
        $activeSection->writeln('<info>🎉 Project generated successfully!</info>');
        $activeSection->writeln('<info>📝 Next steps:</info>');
        $activeSection->writeln('   1. cd ' . $projectName);
        $activeSection->writeln('   2. ./install.sh');
    }

    /**
     * Creates a Laravel project using the official installer.
     * This method has been refactored to improve readability and testability.
     *
     * @param string $projectName Nombre del proyecto a crear (ya sanitizado).
     * @param OutputInterface $output Output para mostrar progreso.
     */
    protected function createLaravelProjectWithInstaller(string $projectName, OutputInterface $output): void
    {
        $this->checkForLaravelInstallerUpdates($output);
        $this->setComposerPathInEnvironment();
        $this->createLaravelProject($projectName, $output);
    }

    /**
     * Checks for Laravel installer updates and outputs progress information.
     *
     * @param OutputInterface $output Output para mostrar progreso.
     */
    private function checkForLaravelInstallerUpdates(OutputInterface $output): void
    {
        $output->writeln('   • Checking for Laravel installer updates');
        $process = new Process(['composer', 'global', 'require', 'laravel/installer']);
        $process->setTimeout(null);

        $process->run(function ($type, $line) use ($output) {
            $isNoise = $this->isComposerNoise($line);
            if (!$isNoise && trim($line) !== '') {
                $output->writeln('      <fg name="gray">» ' . trim($line) . '</>');
            }
        });
    }

    /**
     * Determines if a Composer output line is noise that should be filtered.
     *
     * @param string $line Una línea de output de Composer.
     * @return bool Verdadero si la línea es ruido, falso en otro caso.
     */
    private function isComposerNoise(string $line): bool
    {
        return (
            strpos($line, 'Loading composer repositories') !== false ||
            strpos($line, 'Updating dependencies') !== false ||
            strpos($line, 'Installing dependencies') !== false ||
            strpos($line, 'Writing lock file') !== false ||
            strpos($line, 'Generating autoload files') !== false ||
            strpos($line, 'Nothing to install, update or remove') !== false ||
            strpos($line, 'Packages you are using are looking for funding') !== false ||
            strpos($line, 'Use the `composer fund` command') !== false ||
            strpos($line, 'No security vulnerability advisories found') !== false ||
            strpos($line, 'Using version') !== false ||
            strpos($line, './composer.json has been updated') !== false ||
            strpos($line, 'Running composer update') !== false ||
            strpos($line, 'Changed current directory') !== false
        );
    }

    /**
     * Sets the Composer path in the environment variables.
     */
    private function setComposerPathInEnvironment(): void
    {
        $home = getenv('HOME');
        putenv("PATH={$home}/.config/composer/vendor/bin:{$home}/.composer/vendor/bin:" . getenv('PATH'));
    }

    /**
     * Creates the Laravel project using the Laravel installer.
     *
     * @param string $projectName Nombre del proyecto a crear (ya sanitizado).
     * @param OutputInterface $output Output para mostrar progreso.
     */
    private function createLaravelProject(string $projectName, OutputInterface $output): void
    {
        $process = new Process(['laravel', 'new', $projectName, '--no-interaction']);
        $process->setTimeout(null);
        $skipBlock = false;

        $process->run(function ($type, $line) use ($output, &$skipBlock) {
            if ($this->shouldSkipLaravelOutput($line)) {
                $skipBlock = true;
            }
            if (!$skipBlock) {
                $output->write($line);
            }
        });

        if (!$process->isSuccessful()) {
            throw new \RuntimeException("laravel new failed: " . $process->getErrorOutput());
        }
    }

    /**
     * Determines if Laravel installer output should be skipped.
     *
     * @param string $line Una línea de output del instalador de Laravel.
     * @param bool $skipBlock Referencia a la bandera skip block (pasada por referencia).
     * @return bool Verdadero si la salida debe omitirse, falso en otro caso.
     */
    private function shouldSkipLaravelOutput(string $line): bool
    {
        return (
            strpos($line, 'Running database migrations') !== false ||
            strpos($line, 'Application ready in') !== false
        );
    }

    /**
     * Asegura que exista el archivo resources/js/bootstrap.js y su contenido básico.
     *
     * @param string $projectPath Ruta raíz del proyecto.
     */
    protected function ensureBootstrapJs(string $projectPath): void
    {
        $jsPath = $projectPath . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'js';
        if (!is_dir($jsPath)) {
            // Usar permisos más seguros: 755 en lugar de 0755 (aunque 0755 es lo mismo, ser explícito)
            @mkdir($jsPath, 0755, true);
        }
        $bootstrapPath = $jsPath . DIRECTORY_SEPARATOR . 'bootstrap.js';
        if (!file_exists($bootstrapPath)) {
            $bootstrapContent = "import axios from 'axios';\nwindow.axios = axios;\nwindow.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';\n";
            // No suprimir el error, pero si falla, continuamos (no es crítico)
            $result = @file_put_contents($bootstrapPath, $bootstrapContent);
            if ($result === false) {
                // Log the error but don't fail - this is a minor issue
                // In a real application, we might want to use a proper logger
            }
        }
    }

    protected function installSail(string $projectPath, LaravelOptions $options, OutputInterface $activeSection, OutputInterface $detailSection): void
    {
        $database = $options->database; // Ya sanitizado
        $sailServices = $this->getSailServicesForDatabase($database);

        $sailCommand = $this->buildSailCommand($sailServices);

        $this->runProcess($sailCommand, $projectPath, $activeSection, false, true);
        $this->customizeComposeFile($projectPath, $database, $activeSection);
    }

    /**
     * Gets the Sail services to install based on the database type.
     *
     * @param string $database El tipo de base de datos (ya sanitizado).
     * @return array Lista de services to include with Sail.
     */
    private function getSailServicesForDatabase(string $database): array
    {
        $nativeSailDatabases = ['mysql', 'mariadb', 'pgsql'];
        if (in_array($database, $nativeSailDatabases, true)) {
            return [$database];
        }

        return [];
    }

    /**
     * Builds the Sail install command with the appropriate services.
     *
     * @param array $sailServices Lista de services to include.
     * @return array El comando Sail con los argumentos de service.
     */
    private function buildSailCommand(array $sailServices): array
    {
        $sailCommand = ['php', 'artisan', 'sail:install', '--no-interaction'];
        if (!empty($sailServices)) {
            $sailCommand[] = '--with=' . implode(',', $sailServices);
        } else {
            $sailCommand[] = '--with=';
        }

        return $sailCommand;
    }

    protected function installBoost(string $projectPath, OutputInterface $headerSection, OutputInterface $detailSection): void
    {
        $headerSection->writeln('   • Installing Laravel Boost for AI assisted coding');
        try {
            $this->runProcess(['composer', 'require', 'laravel/boost', '--dev', '--no-interaction', '--quiet'], $projectPath, $detailSection, false, true);
            $this->runProcess(['php', 'artisan', 'boost:install'], $projectPath, $detailSection, false, true);
        } catch (\Exception $e) {
            $headerSection->writeln('<warning>⚠️ Laravel Boost installation failed. Continuing without it...</warning>');
        }
    }

    protected function generateInstallScript(string $projectPath, LaravelOptions $options, OutputInterface $output): void
    {
        $stubPath = __DIR__ . '/../templates/laravel/install.sh.stub';
        if (!file_exists($stubPath)) {
            $output->writeln('<error>❌ Template not found</error>');
            return;
        }

        $database = $options->database; // Ya sanitizado
        $stub = file_get_contents($stubPath);
        $tags = [
            'USE_SAIL' => true,
            'USE_SQLSRV' => $database === 'sqlsrv',
            'USE_BREEZE' => $options->kit === 'Breeze',
            'USE_JETSTREAM' => $options->kit === 'Jetstream',
            'USE_STARTER_KIT' => $options->kit === 'Official Starter Kit (2026)',
        ];
        $variables = [
            'PROJECT_NAME' => basename($projectPath),
            'DB_SERVICE' => $database,
            'BREEZE_STACK' => $options->stack,
            'JETSTREAM_STACK' => $options->stack,
        ];

        $content = StubProcessor::process($stub, $variables, $tags);
        file_put_contents($projectPath . '/install.sh', $content);
        // Usar permisos más seguros: 755 en lugar de 0755
        chmod($projectPath . '/install.sh', 0755);
    }

    protected function fixJsDependencies(string $projectPath, string $stack, OutputInterface $output): void
    {
        $packageJsonPath = $projectPath . '/package.json';
        if (!file_exists($packageJsonPath)) {
            return;
        }

        $packageJson = json_decode(file_get_contents($packageJsonPath), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return;
        }

        if (!isset($packageJson['dependencies'])) {
            $packageJson['dependencies'] = [];
        }
        if (!isset($packageJson['devDependencies'])) {
            $packageJson['devDependencies'] = [];
        }

        if (!isset($packageJson['dependencies']['axios']) || empty($packageJson['dependencies']['axios'])) {
            $packageJson['dependencies']['axios'] = '^1.6.0';
        }

        $stack = strtolower($stack); // Ya debería estar sanitizado, pero lo aseguramos
        if (in_array($stack, ['react', 'vue', 'inertia'], true)) {
            // Force Vite version to avoid ERESOLVE conflicts with older plugins
            $packageJson['devDependencies']['vite'] = '^7.0.0';

            // Add overrides to force the version across the entire tree
            if (!isset($packageJson['overrides'])) {
                $packageJson['overrides'] = [];
            }
            $packageJson['overrides']['vite'] = '$vite';

            $output->writeln('   <fg name="green">✔</> Adjusted vite version and added overlays to resolve dependency conflicts');
        }

        file_put_contents($packageJsonPath, json_encode($packageJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    protected function customizeComposeFile(string $projectPath, string $database, OutputInterface $output): void
    {
        $composeFile = $projectPath . '/compose.yaml';
        if (!file_exists($composeFile)) {
            return;
        }

        $composeContent = file_get_contents($composeFile);
        $servicesToRemove = match ($database) {
            'sqlite' => ['mysql', 'redis'],
            default => [],
        };

        foreach ($servicesToRemove as $service) {
            $pattern = '/^\s*' . preg_quote($service, '/') . ':\s*$\n(?:^\s{2,}.*\n)*/m';
            $composeContent = preg_replace($pattern, '', $composeContent);
        }

        if (!empty($servicesToRemove)) {
            $composeContent = preg_replace('/^(\s+)depends_on:\s*\n(?:\1\s+-[^\n]*\n)*/m', '', $composeContent);
        }

        $orphanVolumes = match ($database) {
            'sqlite' => ['sail-mysql', 'sail-redis'],
            default => [],
        };

        foreach ($orphanVolumes as $vol) {
            $composeContent = preg_replace('/^\s*' . preg_quote($vol, '/') . ':\s*\n(?:\s+driver:.*\n)?/m', '', $composeContent);
        }

        $composeContent = preg_replace('/^volumes:\s*\n(?:\s*\n)*(?=\S|$)/m', '', $composeContent);
        file_put_contents($composeFile, $composeContent);
    }

    protected function setDatabaseConnection(string $projectPath, string $database, OutputInterface $output): void
    {
        $envPath = $projectPath . '/.env';
        if (!file_exists($envPath)) {
            return;
        }

        $envContent = file_get_contents($envPath);
        $dbVars = [
            'DB_CONNECTION' => $database,
            'DB_HOST' => '',
            'DB_PORT' => '',
            'DB_DATABASE' => '',
            'DB_USERNAME' => '',
            'DB_PASSWORD' => '',
        ];

        foreach ($dbVars as $var => $value) {
            $pattern = "/^{$var}=.*/m";
            $newLine = "{$var}={$value}";

            if (preg_match($pattern, $envContent)) {
                if ($database === 'sqlite' || $var === 'DB_CONNECTION') {
                    $envContent = preg_replace($pattern, $newLine, $envContent);
                }
            } else {
                $envContent .= "\n{$newLine}";
            }
        }

        file_put_contents($envPath, $envContent);
    }
}