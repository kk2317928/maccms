<?php
namespace app\command;
use app\common\util\VodRepeatRepairService; use think\console\Command; use think\console\Input; use think\console\input\Option; use think\console\Output;
class MaccmsRepairVodRepeat extends Command {
 protected function configure(){ $this->setName('maccms:repair-vod-repeat')->setDescription('Inspect or rebuild native duplicate-name cache')->addOption('confirm',null,Option::VALUE_NONE,'Apply rebuild'); }
 protected function execute(Input $input,Output $output){$apply=(bool)$input->getOption('confirm');$r=(new VodRepeatRepairService())->rebuild($apply);$output->writeln(($apply?'applied':'dry-run').', duplicate_groups='.$r['duplicate_groups'].', cached_rows='.$r['cached_rows'].', would_change='.(!empty($r['would_change'])?'yes':'no'));return 0;}
}
