<?php
/**
 * Created by PhpStorm.
 * User: wlw2533
 * Date: 18-5-22
 * Time: 下午2:05
 */

namespace app\publish\task;

use app\common\model\lazada\LazadaSite;
use app\index\service\AbsTasker;
use app\publish\helper\lazada\LazadaHelper;
use app\publish\queue\LazadaBrandsQueue;
use think\Exception;

class LazadaBrandTask extends AbsTasker
{
    public function getName()
    {
       return 'lazada品牌';
    }

    public function getDesc()
    {
        return 'lazada品牌';
    }

    public function getCreator()
    {
        return 'kevin';
    }

    public function getParamRule()
    {
       return [];
    }

    public function execute()
    {
       set_time_limit(0);
       //获取siteIds
        $siteCodes = LazadaSite::column('code');
//       try {
//           $wh = [
//               'platform_status' => 1,
//               'app_key' => ['neq', ''],
//               'status' => 1,
//               ];
//           foreach ( $siteCodes as $k=>$siteCode){
//               $wh['site'] = $siteCode;
//               $accounts = (new \app\common\model\lazada\LazadaAccount())->where($wh)->select();
//               foreach ($accounts as $k => $v) {
//                   $accountId = $v['id'];
//                   $page = $offset = 0;
//                   $pageSize = 1000;
//                   do {
//                       try {
//                           $response  = (new LazadaHelper())->syncBrands($accountId, $offset, $pageSize);
//                       } catch (Exception $e) {
//                           throw new Exception($e->getMessage());
//                       }
//                       if (is_string($response)) {
//                           continue;
//                       }
//                       if (empty($response)) {
//                           break;
//                       }
//                       $totalPage = ceil( count($response)/ $pageSize);
//                       foreach ($response['products'] as $k=>$v) {
//                           $queue = $accountId. '|'. $v['item_id'];
//                           (new LazadaBrandsQueue())->execute($queue);
//////                        (new UniqueQueuer(LazadaSyncItemDetailQueue::class))->push($queue);
//                       }
//                       $offset += 100;
//                       $page++;
//                   } while ($page <= $totalPage);
//               }
//           }
//
//
//       } catch (Exception $exp) {
//           throw new Exception($exp->getMessage());
//       }
    }
}