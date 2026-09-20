<?php
namespace app\command;

use app\common\util\VideoRankingRepository;
use think\console\Command;
use think\console\Input;
use think\console\Output;

class MaccmsAnalytics extends Command
{
    protected function configure(){ $this->setName('maccms:analytics')->setDescription('Aggregate video rankings and purge processed raw events'); }
    protected function execute(Input $input,Output $output)
    {
        $repository=new VideoRankingRepository();$total=0;$passes=0;
        do{$result=$repository->aggregate();$total+=$result['processed'];$passes++;}while(!empty($result['pending'])&&$passes<10);
        $purged=$repository->purge();
        $output->writeln(sprintf('done, processed=%d, purged=%d',$total,$purged));
    }
}
