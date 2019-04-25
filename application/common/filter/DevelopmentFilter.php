<?php
/**
 * Created by PhpStorm.
 * User: libaimin
 * Date: 2018/11/28
 * Time: 16:33
 */

namespace app\common\filter;
use app\common\service\Common;
use app\common\traits\User;

class DevelopmentFilter extends BaseFilter
{
    use User;
    protected $scope = 'Development';

    public static function getName(): string
    {
        return '开发人员过滤器';
    }

    public static function config(): array
    {
        $options = [
            ['value'=>-1,'label'=>'关闭'],
            ['value'=>0,'label'=>'自己'],
            ['value'=>1,'label'=>'下属'],
        ];
        return [
            'key' => 'type',
            'type' => static::TYPE_SELECT,
            'options' => $options
        ];
    }

    public function generate()
    {
        $type = $this->getConfig();
        $result = [];
        if($type){
            $userInfo = Common::getUserInfo();
            if(in_array(0,$type)){
                $result[] = $userInfo['user_id'];
            }
            IF(in_array(1,$type)){
                $users = $this->getUnderlingInfo($userInfo['user_id']);
                $result = array_merge($result,$users);
            }
            if(in_array(-1,$type)){
                $result[] = -1;
            }
        }

        return $result;
    }
}