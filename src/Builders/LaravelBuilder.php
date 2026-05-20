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
    private array $logBuffer = [];
    private int $maxLogLines = 5;

    public function build(string $projectName, ProjectOptions $options, OutputInterface $output): int
    {
        /** @var LaravelOptions $options */
        $sanitizedProjectName = $this->sanitizeProjectName($projectName);
        $projectPath = getcwd() . DIRECTORY_SEPARATOR . $sanitizedProjectName;

        try {
            $logSection = $output instanceof ConsoleOutputInterface ? $output->section() : $output;
            $historySection = $output;
            $statusSection = $output;

            $this->runStep(
                'Scaffolding project with Laravel installer',
                fn() => $this->runLaravelInstaller($sanitizedProjectName, $statusSection, $logSection, $options),
                'Laravel project created',
                $historySection,
                $statusSection,
                $logSection
            );

            $config = $this->detectProjectConfig($projectPath);

            if ($options->withSail) {
                $this->runStep(
                    'Configuring Laravel Sail',
                    fn() => $this->configureSailStep($projectPath, $config['database'], $logSection, $options),
                    'Laravel Sail configured',
                    $historySection,
                    $statusSection,
                    $logSection
                );
            }

            $this->runStep(
                'Generating installation script',
                fn() => $this->generateInstallScript($projectPath, $config, $options, $logSection),
                'Installation script generated',
                $historySection,
                $statusSection,
                $logSection
            );

            $this->fixNodeModulesPermissions($projectPath, $statusSection, $options);

            $this->showConfigSummary($statusSection, $config, $options);

            $this->showFinalInfo($statusSection, $sanitizedProjectName);

            return 0;
        } catch (\Exception $e) {
            $output->writeln('<error>❌ Error: ' . $e->getMessage() . '</error>');
            return 1;
        }
    }

    private function sanitizeProjectName(string $projectName): string
    {
        if (strpos($projectName, '/') !== false || strpos($projectName, '\\') !== false) {
            throw new \InvalidArgumentException("Project name '{$projectName}' contains invalid path separators.");
        }

        if (empty($projectName) || $projectName === '.' || $projectName === '..') {
            throw new \InvalidArgumentException("Project name '{$projectName}' is invalid.");
        }

        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $projectName)) {
            throw new \InvalidArgumentException("Project name '{$projectName}' contains invalid characters. Only letters, numbers, hyphens, and underscores are allowed.");
        }

        if (strlen($projectName) > 50) {
            throw new \InvalidArgumentException("Project name is too long (maximum 50 characters)");
        }

        return $projectName;
    }

    private function runStep(
        string $title,
        callable $task,
        string $successTitle,
        OutputInterface $historySection,
        OutputInterface $statusSection,
        OutputInterface $logSection
    ): void {
        $this->logBuffer = [];
        $statusSection->writeln("   • {$title} ...");
        $task();

        if ($logSection instanceof ConsoleSectionOutput) {
            $logSection->clear();
        }

        if ($statusSection->isDecorated()) {
            $statusSection->write("\033[1A\033[2K");
        }

        $historySection->writeln("   <info>✔</info> {$successTitle}");
    }

    protected function runLaravelInstaller(string $projectName, OutputInterface $statusSection, OutputInterface $logSection, LaravelOptions $options): void
    {
        $this->checkForLaravelInstallerUpdates($logSection, $options);

        $home = getenv('HOME');
        if ($home) {
            putenv("PATH={$home}/.config/composer/vendor/bin:{$home}/.composer/vendor/bin:" . getenv('PATH'));
        }

        $resultCode = 0;
        passthru('laravel new ' . escapeshellarg($projectName), $resultCode);

        if ($resultCode !== 0) {
            throw new \RuntimeException("Laravel installer failed with exit code {$resultCode}.");
        }
    }

    protected function checkForLaravelInstallerUpdates(OutputInterface $logSection, LaravelOptions $options): void
    {
        $this->runProcess(['composer', 'global', 'require', 'laravel/installer', '--no-interaction', '--quiet'], getcwd(), $logSection, false, true, $options->isQuiet(), $options->isVerbose());
    }

    private function setComposerPathInEnvironment(): void
    {
        $home = getenv('HOME');
        putenv("PATH={$home}/.config/composer/vendor/bin:{$home}/.composer/vendor/bin:" . getenv('PATH'));
    }

    /**
     * Detecta la configuración del proyecto después de que el installer termina.
     *
     * @param string $projectPath Ruta del proyecto
     * @return array<string, mixed>
     */
    public function detectProjectConfig(string $projectPath): array
    {
        $config = [
            'starterKit' => 'none',
            'database' => 'sqlite',
            'hasBoost' => false,
            'testing' => 'phpunit',
            'hasTeams' => false,
        ];

        $composerJson = file_get_contents($projectPath . '/composer.json');
        $composer = json_decode($composerJson, true);
        $requires = $composer['require'] ?? [];
        $requireDev = $composer['require-dev'] ?? [];

        if (isset($requires['laravel/boost'])) {
            $config['hasBoost'] = true;
        }

        if (isset($requireDev['pestphp/pest']) || isset($requireDev['pestphp/pest-plugin-laravel'])) {
            $config['testing'] = 'pest';
        }

        if (file_exists($projectPath . '/.env')) {
            $envContent = file_get_contents($projectPath . '/.env');
            if (preg_match('/^DB_CONNECTION=(.*)$/m', $envContent, $matches)) {
                $config['database'] = trim($matches[1]);
            }
        }

        if (is_dir($projectPath . '/resources/js/Pages')) {
            $viteConfig = file_get_contents($projectPath . '/vite.config.js');
            if (str_contains($viteConfig, 'react')) {
                $config['starterKit'] = 'react';
            } elseif (str_contains($viteConfig, 'vue')) {
                $config['starterKit'] = 'vue';
            }
        }

        if (is_dir($projectPath . '/app/Livewire')) {
            $config['starterKit'] = 'livewire';
        }

        if (file_exists($projectPath . '/svelte.config.js')) {
            $config['starterKit'] = 'svelte';
        }

        return $config;
    }

    protected function configureSailStep(string $projectPath, string $database, OutputInterface $logSection, LaravelOptions $options): void
    {
        if ($database === 'sqlsrv') {
            $logSection->writeln('      <fg=yellow>⚠ SQL Server selected — skipping Sail installation (SQL Server requires pdo_sqlsrv extension not included in Sail containers)</>');
            return;
        }

        $this->runProcess(['composer', 'require', 'laravel/sail', '--dev', '--no-interaction', '--quiet'], $projectPath, $logSection, false, true, $options->isQuiet(), $options->isVerbose());
        $this->installSail($projectPath, $database, $logSection, $options);
    }

    private function installSail(string $projectPath, string $database, OutputInterface $activeSection, LaravelOptions $options): void
    {
        $nativeSailDatabases = ['mysql', 'mariadb', 'pgsql'];
        $sailServices = in_array($database, $nativeSailDatabases, true) ? [$database] : [];

        $sailCommand = ['php', 'artisan', 'sail:install', '--no-interaction'];
        if (!empty($sailServices)) {
            $sailCommand[] = '--with=' . implode(',', $sailServices);
        } else {
            $sailCommand[] = '--with=';
        }

        $this->runProcess($sailCommand, $projectPath, $activeSection, false, true, $options->isQuiet(), $options->isVerbose());
        $this->customizeComposeFile($projectPath, $database, $activeSection);

        if ($database === 'sqlite') {
            $this->fixEnvForSqlite($projectPath);
        }
    }

    private function fixEnvForSqlite(string $projectPath): void
    {
        $envPath = $projectPath . '/.env';
        if (!file_exists($envPath)) {
            return;
        }

        $envContent = file_get_contents($envPath);

        $patterns = [
            '/^DB_CONNECTION=.*$/m' => 'DB_CONNECTION=sqlite',
            '/^DB_HOST=.*$/m' => 'DB_HOST=sqlite',
        ];

        foreach ($patterns as $pattern => $replacement) {
            $envContent = preg_replace($pattern, $replacement, $envContent);
        }

        file_put_contents($envPath, $envContent);
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

    protected function generateInstallScript(string $projectPath, array $config, LaravelOptions $options, OutputInterface $output): void
    {
        $stubPath = __DIR__ . '/../Templates/laravel/install.sh.stub';
        if (!file_exists($stubPath)) {
            $output->writeln('<error>❌ Template not found</error>');
            return;
        }

        $variables = [
            'PROJECT_NAME' => basename($projectPath),
            'DB_SERVICE' => $config['database'],
        ];

        $tags = [
            'USE_SAIL' => $options->withSail,
            'USE_SQLSRV' => $config['database'] === 'sqlsrv',
        ];

        $scriptsDir = $projectPath . '/scripts';
        if (!is_dir($scriptsDir)) {
            mkdir($scriptsDir, 0755, true);
        }

        $stub = file_get_contents($stubPath);
        $content = StubProcessor::process($stub, $variables, $tags);
        file_put_contents($scriptsDir . '/install.sh', $content);
        chmod($scriptsDir . '/install.sh', 0755);
    }

    protected function fixNodeModulesPermissions(string $projectPath, OutputInterface $activeSection, LaravelOptions $options): void
    {
        $nodeModulesPath = $projectPath . DIRECTORY_SEPARATOR . 'node_modules';
        if (is_dir($nodeModulesPath)) {
            $this->runProcess(['chmod', '-R', '755', 'node_modules'], $projectPath, $activeSection, false, false, $options->isQuiet(), $options->isVerbose());
        }
    }

    protected function showConfigSummary(OutputInterface $output, array $config, LaravelOptions $options): void
    {
        $kitName = match ($config['starterKit']) {
            'react' => 'React',
            'vue' => 'Vue',
            'livewire' => 'Livewire',
            'svelte' => 'Svelte',
            default => 'None',
        };

        $output->writeln('');
        $output->writeln('<info>📋 Project Configuration:</info>');
        $output->writeln("   • Starter Kit: {$kitName}");
        $output->writeln("   • Database: {$config['database']}");
        $output->writeln("   • Testing: {$config['testing']}");
        $output->writeln("   • Laravel Boost: " . ($config['hasBoost'] ? 'Yes' : 'No'));
        $output->writeln("   • Docker/Sail: " . ($options->withSail ? 'Yes' : 'No'));
    }

    protected function showFinalInfo(OutputInterface $activeSection, string $projectName): void
    {
        $activeSection->writeln('');
        $activeSection->writeln('<info>🎉 Project generated successfully!</info>');
        $activeSection->writeln('<info>📝 Next steps:</info>');
        $activeSection->writeln('   1. cd ' . $projectName);
        $activeSection->writeln('   2. scripts/install.sh');
    }

    protected function runProcess(
        array $command,
        string $workingDirectory,
        OutputInterface $output,
        bool $hideOutput = false,
        bool $allowFailure = false,
        bool $quietMode = false,
        bool $verboseMode = false,
        array $env = []
    ): void {
        $process = new Process($command, $workingDirectory, $env);
        $process->setTimeout(null);

        $process->run(function ($type, $line) use ($output, $hideOutput, $quietMode, $verboseMode) {
            if ($hideOutput) {
                return;
            }

            $this->handleProcessOutput($type, $line, $output, $quietMode, $verboseMode);
        });

        if (!$process->isSuccessful() && !$allowFailure) {
            throw new \RuntimeException(
                sprintf(
                    'Process failed with exit code %d.' . PHP_EOL .
                    'Output: %s' . PHP_EOL .
                    'Error: %s',
                    $process->getExitCode(),
                    $process->getOutput(),
                    $process->getErrorOutput()
                )
            );
        }
    }

    private function handleProcessOutput(string $type, string $line, OutputInterface $output, bool $isQuiet, bool $isVerbose): void
    {
        if ($isVerbose) {
            $output->write($line);
            return;
        }

        if ($isQuiet) {
            $lines = explode("\n", $line);
            foreach ($lines as $singleLine) {
                if (trim($singleLine) === '') {
                    continue;
                }
                if (!$this->isNoise($singleLine)) {
                    $cleanOutput = preg_replace(['/<[^>]*>/', '/\x1b\[[0-9;?]*[A-Za-z]/'], '', $singleLine);
                    $formattedOutput = '      <fg=gray>» ' . $cleanOutput . '</>';
                    $output->writeln($formattedOutput);
                }
            }
            return;
        }

        $output->write($line);
    }

    protected function isNoise(string $line): bool
    {
        $cleanLine = preg_replace(['/<[^>]*>/', '/\x1b\[[0-9;]*m/'], '', $line);

        $noisePatterns = [
            '/^(?:\s*)?[-]{3,}(?:\s*)(?:[\d.]+%?)?(?:\s*)?[-]{3,}$/',
            '/^(?:\s*)?[.]{3,}(?:\s*)?[.]{3,}(?:\s*)?$/',
            '/^(?:\s*)?={3,}(?:\s*)?={3,}(?:\s*)?$/',
            '/^(?:\s*)?#{3,}(?:\s*)?#{3,}(?:\s*)?$/',
            '/^(?:\s*)?\[.{20,}\](?:\s*)?$/',
            '/^(?:\s*)?Progress: \d+(?:\.\d+)?%(?:\s*)?$/',
            'Loading composer repositories',
            'Updating dependencies',
            'Installing dependencies',
            'Writing lock file',
            'Generating autoload files',
            'Generating optimized autoload files',
            'Generated optimized autoload files',
            'Nothing to install, update or remove',
            'Packages you are using are looking for funding',
            '/^  - Locking /',
            'Use the `composer fund` command',
            'No security vulnerability advisories found',
            'Using version',
            './composer.json has been updated',
            'Running composer update',
            'Changed current directory',
            'Info from https://repo.packagist.org:',
            'Executing command',
            'Executing script',
            'Discovered Package:',
            'Package manifest generated successfully',
            'Lock file operations',
            'Package operations',
            'Nothing to modify in lock file',
            '/\[[>=-]*\]/i',
            '/^ - Locking /',
            'Extracting files',
            'Application ready in',
            'Running database migrations',
            'Configuration cached successfully',
            'Route cached successfully',
            'Filesystem linked successfully',
            'Copying .env',
            'publishing [config]',
            '/➜\s+cd/u',
            'New to Laravel?',
            'Check out our documentation',
            'Build something amazing!',
            'WARN  TTY mode requires /dev/tty',
            'INFO  Discovering packages',
            'INFO  No publishable resources',
            'INFO  Installing and building Node dependencies',
            '/^> /',
            '/^  [\w\/-]+(?:\s+\.+\s*|\s+)DONE/i',
            'Pulling',
            'Pulled',
            'Downloading',
            'Verifying archive integrity',
            'All good!',
            'build-kit',
            'context:',
            'dockerfile:',
            'COPY',
            'RUN',
            'ENTRYPOINT',
            '[webpack] building modules',
            '[webpack] optimization stages',
            '[webpack] emit',
            '[webpack] done',
            'webpack compiled with',
            'webpack compiled successfully',
            'asset unchanged',
            'Entrypoint',
            'runtime modules',
            '[built]',
        ];

        foreach ($noisePatterns as $pattern) {
            if (str_starts_with($pattern, '/')) {
                if (@preg_match($pattern, $cleanLine)) {
                    return true;
                }
            } else {
                if (stripos($cleanLine, $pattern) !== false) {
                    return true;
                }
            }
        }

        return false;
    }
}