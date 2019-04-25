<?php

namespace app\customerservice\filter;

use app\common\model\DepartmentUserMap;
use app\common\service\ChannelAccountConst;
use app\common\service\Common;
use app\index\service\MemberShipService;
use app\common\filter\BaseFilter;
use app\common\model\Department;
use app\index\service\Department as DepartmentService;
use app\publish\service\AmazonPublishService;

class EbayDepartmentFilter extends BaseFilter
{
    protected $scope = 'Department';

    public static function getName(): string
    {
        return 'Ebay通用部门权限过滤器';
    }

    public static function config(): array
    {
        $model = new Department();
        $options = $model->field('id as value, name as label')->select();
        if($options) {
            foreach ($options as &$option) {
                $option['label']=(new DepartmentService)->getDepartmentNames($option['value']);
            }
        }

        return [
            'key' => 'type',
            'type' => static::TYPE_SELECT,
            'options' => $options
        ];
    }

    public function generate()
    {
        $type = $this->getConfig();
        $pulishService = new AmazonPublishService();

        //先找出用户部门；
        $users = [];
        foreach ($type as $departmant_id) {
            if ($departmant_id == 0) {
                continue;
            }
            $sellerList = $pulishService->getUserByDepartmentId($departmant_id, $type);
            $users = array_merge($users, $sellerList);
        }

        $accounts=[];

        if($users)
        {
            $users = array_unique($users);
            $memberShipService = new MemberShipService();
            foreach ($users as $user)
            {
                $accountList = $memberShipService->getAccountIDByUserId($user, ChannelAccountConst::channel_ebay);
                $accounts = array_merge($accounts, $accountList);
            }
        }else{
            $accountList=[];
            $accounts = array_merge($accounts, $accountList);
        }

        if (is_array($type)) {
            $accounts = array_merge($type, $accounts);
        } else {
            $accounts[] = $type;
        }

        $accounts = array_merge(array_unique(array_filter($accounts)));
        return $accounts;
    }
}