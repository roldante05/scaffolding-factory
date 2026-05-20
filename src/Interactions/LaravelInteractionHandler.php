<?php

declare(strict_types=1);

namespace Roldante05\ScaffoldingFactory\Interactions;

use Roldante05\ScaffoldingFactory\DTOs\LaravelOptions;
use Roldante05\ScaffoldingFactory\DTOs\ProjectOptions;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use function Laravel\Prompts\confirm;

class LaravelInteractionHandler implements InteractionHandlerInterface
{
    public function handle(InputInterface $input, OutputInterface $output): ProjectOptions
    {
        $projectName = $input->getArgument('name');
        $verbose = $output->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE;
        $quiet = !$verbose;
        $isTty = stream_isatty(STDIN);

        if (!$isTty) {
            return new LaravelOptions(
                projectName: $projectName,
                database: 'sqlite',
                withSail: false,
                quiet: $quiet,
                verbose: $verbose
            );
        }

        $withSail = confirm(
            label: 'Do you want to configure Docker with Laravel Sail?',
            default: true,
            hint: 'This will install Laravel Sail and configure Docker containers.'
        );

        return new LaravelOptions(
            projectName: $projectName,
            database: 'sqlite',
            withSail: $withSail,
            quiet: $quiet,
            verbose: $verbose
        );
    }
}