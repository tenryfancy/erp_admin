<?php

namespace app\report\filter;

use app\common\cache\Cache;
use app\common\filter\BaseFilter;
use app\common\service\Common;
use app\common\traits\User;
use app\common\model\User as ModelUser;
use app\index\service\MemberShipService;

/**
 * Created by PhpStorm.
 * User: hecheng
 * Date: 2019/4/8
 * Time: 9:55
 */
class ProfitStatementBySellerFilter extends BaseFilter
{
    use User;
    protected $scope = 'Order';

    public static function getName(): string
    {
        return '通过销售员对应的账号过滤订单利润明细';
    }

    public static function config(): array
    {
        $options = ModelUser::where('status',1)->field('id as value, realname as label')->select();
        return [
            'key' => 'type',
            'type' => static::TYPE_SELECT,
            'options' => $options
        ];
    }

    public function generate()
    {
        //查询账号
        $userInfo = Common::getUserInfo();
        $userId = $userInfo['user_id'];
        $cache = Cache::handler();
        $key = 'cache:OrderBySellerAccountFilterByUserId:' . $userId;
        if ($cache->exists($key)) {
            $accountId = $cache->get($key);
            return json_decode($accountId,true);
        }
        $type = $this->getConfig();
        $memberShipService = new MemberShipService();
        //获取自己和下级用户
        $underling = $this->getUnderlingInfo($userId);
        $userList = array_merge($type, $underling);
        $userList = array_unique($userList);
        $accountId = [];
        if (!empty($userList)) {
            foreach ($userList as $user_id) {
                $accountList = $memberShipService->getAccountIDByUserId($user_id, 0, true);
                $accountId = array_merge($accountId, $accountList);
            }
            $accountId = array_merge($accountId, $type);
        } else {
            $accountList = $memberShipService->getAccountIDByUserId($userId, 0, true);
            $accountId = array_merge($type, $accountList);
        }
        $accountId = array_unique($accountId);
        if (count($accountId) > 50) {
            Cache::handler()->set($key, json_encode($accountId), 60 * 10);
        }
        return $accountId;
    }
}

