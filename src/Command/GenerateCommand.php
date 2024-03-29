<?php

/*
 * This file is part of JoliCode's Harvest OpenAPI Generator project.
 *
 * (c) JoliCode <coucou@jolicode.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace JoliCode\Extractor\Command;

use JoliCode\Extractor\Dumper\Dumper;
use JoliCode\Extractor\Extractor\Extractor;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class GenerateCommand extends Command
{
    public function configure()
    {
        $this->setName('generate');
        $this->setDescription('Generate a Harvest\'s swagger.yaml definition.');
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $extractor = new Extractor();
        $dumper = new Dumper(__DIR__.'/../../generated/harvest-openapi.yaml');
        $warnings = $dumper->dump($extractor->extract());

        if (\count($warnings) > 0) {
            /** @var \Symfony\Component\Console\Helper\FormatterHelper */
            $formatter = $this->getHelper('formatter');
            $output->writeln($formatter->formatBlock($warnings, 'bg=yellow;fg=black', true));
        }

        return Command::SUCCESS;
    }
}
