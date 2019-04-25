<?php
// +----------------------------------------------------------------------
// | 
// +----------------------------------------------------------------------
// | File  : ProfitExportQueue.php
// +----------------------------------------------------------------------
// | Author: LiuLianSen <3024046831@qq.com>
// +----------------------------------------------------------------------
// | Date  : 2017-08-07
// +----------------------------------------------------------------------

namespace  app\report\queue;

use app\common\cache\Cache;
use app\common\service\SwooleQueueJob;
use app\report\service\ProfitStatement;
use app\report\service\StatisticDeeps;


class WriteBackOrderDeeps extends SwooleQueueJob
{
    public function getName(): string
    {
        return "回写发货销售额";
    }

    public function getDesc(): string
    {
        return "回写发货销售额";
    }

    public function getAuthor(): string
    {
        return "Phill";
    }

    public static function swooleTaskMaxNumber():int
    {
        return 20;
    }

    public function execute()
    {
        try {
            $deepsService = new StatisticDeeps();
            $deepsService->updateReportByDelivery($this->params);
        }catch (\Exception $ex){

        }
    }
}