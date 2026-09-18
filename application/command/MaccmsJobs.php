<?php

namespace app\command;

use app\common\util\ContentJobRepository;
use app\common\util\ContentJobWorker;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;

class MaccmsJobs extends Command
{
    protected function configure()
    {
        $this->setName('maccms:jobs')
            ->setDescription('Process a bounded batch of MACCMS content jobs')
            ->addOption('max-jobs', null, Option::VALUE_OPTIONAL, 'Maximum jobs per run', '20')
            ->addOption('max-seconds', null, Option::VALUE_OPTIONAL, 'Maximum runtime seconds', '50')
            ->addOption('lease-seconds', null, Option::VALUE_OPTIONAL, 'Lease duration seconds', '120')
            ->addOption('worker', null, Option::VALUE_OPTIONAL, 'Stable worker identifier', 'cron-default');
    }

    protected function execute(Input $input, Output $output)
    {
        $handlers = [];
        $aiHandler = config('maccms.ai_normalization_handler');
        if (is_callable($aiHandler)) {
            $handlers['ai.normalize'] = $aiHandler;
        }
        $worker = new ContentJobWorker(new ContentJobRepository(), $handlers);
        $result = $worker->run(
            (string) $input->getOption('worker'),
            (int) $input->getOption('max-jobs'),
            (int) $input->getOption('max-seconds'),
            (int) $input->getOption('lease-seconds')
        );
        $output->writeln(sprintf(
            'done, processed=%d, succeeded=%d, failed=%d',
            $result['processed'], $result['succeeded'], $result['failed']
        ));
    }
}
