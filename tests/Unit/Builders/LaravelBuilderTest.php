<?php

declare(strict_types=1);

namespace Tests\Unit\Builders;

use Roldante05\ScaffoldingFactory\Builders\LaravelBuilder;
use Roldante05\ScaffoldingFactory\DTOs\LaravelOptions;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\ConsoleSectionOutput;
use InvalidArgumentException;
use ReflectionClass;

class LaravelBuilderTest extends \PHPUnit\Framework\TestCase
{
    private OutputInterface $output;
    private ConsoleOutputInterface $consoleOutput;
    private ConsoleSectionOutput $logSection;

    protected function setUp(): void
    {
        $this->output = $this->createMock(OutputInterface::class);
        $this->consoleOutput = $this->createMock(ConsoleOutputInterface::class);
        $this->logSection = $this->createMock(ConsoleSectionOutput::class);
        
        $this->consoleOutput->method('section')
            ->willReturn($this->logSection);
    }

    private function createMockBuilder(): MockObject
    {
        return $this->getMockBuilder(LaravelBuilder::class)
                    ->onlyMethods([
                        'runLaravelInstaller',
                        'detectProjectConfig',
                        'configureSailStep',
                        'fixNodeModulesPermissions',
                        'showFinalInfo',
                        'showConfigSummary',
                        'runProcess',
                        'installSail',
                        'customizeComposeFile',
                        'generateInstallScript'
                    ])
                    ->getMock();
    }

    public function test_builder_can_be_instantiated(): void
    {
        $builder = new LaravelBuilder();
        $this->assertInstanceOf(LaravelBuilder::class, $builder);
    }

    public function test_build_method_returns_zero_on_success(): void
    {
        $options = new LaravelOptions(
            projectName: 'test-project',
            database: 'sqlite',
            withSail: false
        );

        $builder = $this->createMockBuilder();

        $result = $builder->build('test-project', $options, $this->output);

        $this->assertEquals(0, $result);
    }

    public function test_build_method_returns_one_on_exception(): void
    {
        $options = new LaravelOptions(
            projectName: 'test-project',
            database: 'sqlite',
            withSail: false
        );

        $builder = $this->createMockBuilder();
        $builder->expects($this->once())
                ->method('runLaravelInstaller')
                ->willThrowException(new \Exception('Test exception'));

        $result = $builder->build('test-project', $options, $this->consoleOutput);

        $this->assertEquals(1, $result);
    }

    public function test_build_method_throws_exception_on_invalid_project_name(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("contains invalid path separators");

        $options = new LaravelOptions(
            projectName: 'test-project',
            database: 'sqlite',
            withSail: false
        );

        $builder = new LaravelBuilder();
        $builder->build('invalid/name', $options, $this->output);
    }

    public function test_build_method_throws_exception_on_project_name_with_dots(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("is invalid");

        $options = new LaravelOptions(
            projectName: 'test-project',
            database: 'sqlite',
            withSail: false
        );

        $builder = new LaravelBuilder();
        $builder->build('..', $options, $this->output);
    }

    public function test_build_method_throws_exception_on_project_name_too_long(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Project name is too long (maximum 50 characters)");

        $options = new LaravelOptions(
            projectName: 'test-project',
            database: 'sqlite',
            withSail: false
        );

        $builder = new LaravelBuilder();
        $builder->build(str_repeat('a', 51), $options, $this->output);
    }

    public function test_build_method_sanitizes_project_name_with_valid_chars(): void
    {
        $options = new LaravelOptions(
            projectName: 'test-project',
            database: 'sqlite',
            withSail: false
        );

        $builder = $this->createMockBuilder();
        $result = $builder->build('valid-project_name', $options, $this->output);
        $this->assertEquals(0, $result);
    }

    public function test_run_process_filters_noise_in_quiet_mode(): void
    {
        $output = $this->createMock(OutputInterface::class);
        $builder = new LaravelBuilder();
        $method = new ReflectionClass(LaravelBuilder::class);
        $runProcess = $method->getMethod('runProcess');
        $runProcess->setAccessible(true);

        $output->expects($this->exactly(2))
               ->method('writeln')
               ->with($this->callback(fn($line) => 
                   stripos($line, 'Meaningful output') !== false || 
                   stripos($line, 'Another meaningful line') !== false
               ));

        $runProcess->invoke(
            $builder,
            ['php', '-r', 'echo "Loading composer repositories\n"; echo "✔ Meaningful output\n"; echo "Updating dependencies\n"; echo "Another meaningful line\n";'],
            getcwd(),
            $output,
            false,
            false,
            true,
            false
        );
    }

    public function test_run_process_shows_all_in_verbose_mode(): void
    {
        $output = $this->createMock(OutputInterface::class);
        $builder = new LaravelBuilder();
        $method = new ReflectionClass(LaravelBuilder::class);
        $runProcess = $method->getMethod('runProcess');
        $runProcess->setAccessible(true);

        $output->expects($this->atLeastOnce())
               ->method('write')
               ->with($this->stringContains('Loading composer repositories'));

        $runProcess->invoke(
            $builder,
            ['php', '-r', 'echo "Loading composer repositories\n"; echo "✔ Meaningful output\n"; echo "Updating dependencies\n"; echo "Another meaningful line\n";'],
            getcwd(),
            $output,
            false,
            false,
            false,
            true
        );
    }
}