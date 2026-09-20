<?php
namespace app\command;
use app\common\util\ContentRepairService;use think\console\Command;use think\console\Input;use think\console\input\Option;use think\console\Output;
class MaccmsRepairContent extends Command
{
 protected function configure(){$this->setName('maccms:repair-content')->setDescription('Dry-run or repair a bounded batch of existing content state')->addOption('apply',null,Option::VALUE_NONE,'Apply safe repairs')->addOption('confirm',null,Option::VALUE_OPTIONAL,'Required confirmation string for apply','')->addOption('limit',null,Option::VALUE_OPTIONAL,'Maximum videos to scan','100');}
 protected function execute(Input $input,Output $output){$apply=(bool)$input->getOption('apply');$confirmed=(string)$input->getOption('confirm')==='REPAIR';$r=(new ContentRepairService())->run($apply,$confirmed,(int)$input->getOption('limit'));$output->writeln(sprintf('%s, scanned=%d, repaired=%d, skipped=%d, extensions=%d, taxonomy=%d, ai_jobs=%d',$apply?'applied':'dry-run',$r['scanned'],$r['repaired'],$r['skipped'],$r['extensions'],$r['taxonomy'],$r['ai_jobs']));}
}
