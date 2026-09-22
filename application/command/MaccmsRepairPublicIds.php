<?php
namespace app\command;
use app\common\util\PublicIdRepairService;use think\console\Command;use think\console\Input;use think\console\input\Option;use think\console\Output;
class MaccmsRepairPublicIds extends Command{protected function configure(){$this->setName('maccms:repair-public-ids')->setDescription('Inspect or repair video public IDs')->addOption('confirm',null,Option::VALUE_NONE,'Apply repair');}protected function execute(Input $i,Output $o){$apply=(bool)$i->getOption('confirm');$r=(new PublicIdRepairService())->repair($apply);$o->writeln(($apply?'applied':'dry-run').', missing='.$r['missing'].', invalid='.$r['invalid']);return 0;}}
