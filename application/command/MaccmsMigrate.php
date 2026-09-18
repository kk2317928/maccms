<?php

namespace app\command;

use app\common\util\SchemaMigrationService;
use think\console\Command;
use think\console\Input;
use think\console\Output;

class MaccmsMigrate extends Command
{
    protected function configure()
    {
        $this->setName('maccms:migrate')
            ->setDescription('Apply pending versioned MACCMS schema migrations');
    }

    protected function execute(Input $input, Output $output)
    {
        $service = new SchemaMigrationService(
            APP_PATH . 'data/migrations',
            (string) config('database.prefix')
        );
        $result = $service->migrate();

        foreach ($result['applied'] as $version) {
            $output->writeln('applied: ' . $version);
        }
        $output->writeln('done, applied=' . count($result['applied']) . ', skipped=' . count($result['skipped']));
    }
}
